<?php
/**
 * billing.class.php - WallboxBilling Abrechnungsklasse
 *
 * Monatliche automatische Abrechnung als Dolibarr-Spesenabrechnung (ExpenseReport).
 * Läuft am 1. jedes Monats (Cron-Job):
 *   1. Validiert Spesenabrechnungen des Vorvor-Monats (M-2) die noch im Entwurf sind
 *   2. Erstellt/befüllt Spesenabrechnungen für den Vormonat (M-1)
 *      - Wenn Abrechnung für M-1 bereits existiert: fehlende Sessions nachfüllen
 *      - Wenn noch keine Abrechnung: neu erstellen und alle Sessions eintragen
 *   3. Duplikatschutz: jede Session bekommt [sid:X]-Marker im Kommentar
 */

// Robuste htdocs-Erkennung: Modul kann in htdocs/custom/ ODER neben htdocs/ liegen
$_wbHtdocs = dirname(dirname(dirname(__DIR__))); // 3 Ebenen hoch (Standard-Install)
if (!file_exists($_wbHtdocs.'/core/class/commonobject.class.php')) {
    $_wbHtdocs .= '/htdocs'; // Fallback: Modul liegt als Geschwister neben htdocs/
}
require_once $_wbHtdocs.'/core/class/commonobject.class.php';
if (!class_exists('ExpenseReport')) {
    require_once $_wbHtdocs.'/expensereport/class/expensereport.class.php';
}
unset($_wbHtdocs);

/**
 * WallboxBillingCron — monatliche Spesenabrechnungs-Automatisierung
 */
class WallboxBillingCron extends CommonObject
{
    /** @var DoliDB */
    public $db;

    /** @var string */
    public $error;

    /** @var array */
    public $errors = array();

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Monatliche Abrechnung ausführen (Cron-Einstiegspunkt)
     *
     * @param  User  $user   Ausführender Benutzer (Cron-/Admin-User)
     * @param  int   $month  Abrechnungsmonat 1-12 (0 = Vormonat)
     * @param  int   $year   Abrechnungsjahr   (0 = aktuelles Jahr)
     * @return array|int     Ergebnis-Array oder -1 bei Fehler
     */
    public function runMonthlyBilling($user, $month = 0, $year = 0)
    {
        global $conf;

        $this->error  = '';
        $this->errors = array();

        // Abrechnungszeitraum (M-1) ermitteln
        $dr           = $this->getMonthDateRange($month, $year);
        $billingMonth = (int) $dr['month'];
        $billingYear  = (int) $dr['year'];

        dol_syslog("WallboxBilling: Starte Abrechnung ".$billingMonth."/".$billingYear, LOG_INFO);

        // Spesenkategorie TK_ELE sicherstellen
        $typeId = $this->getOrCreateExpenseType();
        if ($typeId <= 0) {
            $this->error = "Spesenkategorie TK_ELE konnte nicht angelegt werden";
            if (!empty($this->error_sql)) {
                $this->error .= " (DB-Fehler: ".$this->error_sql.")";
            }
            dol_syslog("WallboxBilling Error: ".$this->error, LOG_ERR);
            return -1;
        }

        // M-2 Entwürfe validieren (auto-cycle)
        $m2month = $billingMonth - 1;
        $m2year  = $billingYear;
        if ($m2month == 0) { $m2month = 12; $m2year--; }
        $this->validateDraftReportsForMonth($m2month, $m2year, $user);

        // Sessions für M-1 laden (je Session eine Zeile, nach Benutzer sortiert)
        $defaultPrice = (float) getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', '0.30');
        $sql = "SELECT s.rowid, s.fk_user, s.wallbox_id, s.start_time, s.kwh,"
             . " COALESCE(r.price_kwh, ".$defaultPrice.") AS eff_price"
             . " FROM ".MAIN_DB_PREFIX."wallbox_sessions s"
             . " LEFT JOIN ".MAIN_DB_PREFIX."wallbox_rfid r"
             . "   ON r.fk_user = s.fk_user AND r.entity = ".(int)$conf->entity
             . " WHERE s.start_time >= '".$this->db->escape($dr['start'])."'"
             . " AND s.start_time <= '".$this->db->escape($dr['end'])."'"
             . " AND s.status = 'completed'"
             . " AND s.fk_user > 0"
             . " ORDER BY s.fk_user, s.start_time";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            dol_syslog("WallboxBilling Error: ".$this->error, LOG_ERR);
            return -1;
        }

        // Nach Benutzer gruppieren
        $byUser = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $byUser[(int)$obj->fk_user][] = $obj;
        }
        $this->db->free($resql);

        if (empty($byUser)) {
            dol_syslog("WallboxBilling: Keine Sessions in ".$billingMonth."/".$billingYear, LOG_INFO);
            return array();
        }

        $results = array();

        foreach ($byUser as $fkUser => $sessions) {
            // Bestehende Abrechnung suchen oder neu anlegen
            $reportId = $this->findExpenseReport($fkUser, $billingMonth, $billingYear);
            if ($reportId <= 0) {
                $reportId = $this->createExpenseReport($fkUser, $billingMonth, $billingYear, $user);
            }
            if ($reportId <= 0) {
                $this->errors[] = "Spesenabrechnung für User $fkUser nicht erstellbar";
                dol_syslog("WallboxBilling: Überspringe User $fkUser — Report-Erstellung fehlgeschlagen", LOG_WARNING);
                continue;
            }

            // Sessions als Zeilen eintragen (Duplikatschutz via [sid:X])
            $added = 0;
            foreach ($sessions as $session) {
                if ($this->addSessionLine($reportId, $session, $typeId)) {
                    $added++;
                }
            }

            // Summen in Report-Header aktualisieren
            $this->updateExpenseReportTotals($reportId);

            $results[] = array(
                'user_id'   => $fkUser,
                'report_id' => $reportId,
                'sessions'  => count($sessions),
                'added'     => $added,
            );

            dol_syslog(
                "WallboxBilling: User $fkUser → Report #$reportId, $added neue Zeilen aus ".count($sessions)." Sessions",
                LOG_INFO
            );
        }

        dol_syslog("WallboxBilling: Abgeschlossen. ".count($results)." Benutzer abgerechnet", LOG_INFO);
        return $results;
    }

    // -------------------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------------------

    /**
     * Sucht bestehende Spesenabrechnung für Benutzer/Monat/Jahr
     *
     * @param  int  $fkUser
     * @param  int  $month
     * @param  int  $year
     * @return int  rowid oder 0
     */
    private function findExpenseReport($fkUser, $month, $year)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."expensereport"
             . " WHERE fk_user_author = ".(int)$fkUser
             . " AND YEAR(date_debut) = ".(int)$year
             . " AND MONTH(date_debut) = ".(int)$month
             . " ORDER BY rowid DESC LIMIT 1";

        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res))) {
            return (int) $obj->rowid;
        }
        return 0;
    }

    /**
     * Erstellt neue Spesenabrechnung (Entwurf) für Benutzer/Monat/Jahr
     *
     * @param  int   $fkUser
     * @param  int   $month
     * @param  int   $year
     * @param  User  $user    Ausführender Benutzer
     * @return int   rowid oder 0
     */
    private function createExpenseReport($fkUser, $month, $year, $user)
    {
        global $conf;

        $lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

        $er = new ExpenseReport($this->db);
        $er->fk_user_author = (int) $fkUser;
        $er->fk_user_valid  = (int) $user->id;
        $er->date_debut     = dol_mktime(0,  0,  0, $month, 1,       $year);
        $er->date_fin       = dol_mktime(23, 59, 59, $month, $lastDay, $year);
        $er->status         = ExpenseReport::STATUS_DRAFT;
        $er->entity         = (int) $conf->entity;

        $id = $er->create($user);
        return ($id > 0) ? (int) $id : 0;
    }

    /**
     * Trägt eine Ladesession als Zeile in die Spesenabrechnung ein.
     * Verhindert Duplikate via [sid:X]-Marker im Kommentar.
     *
     * @param  int    $reportId
     * @param  object $session    DB-Objekt aus wallbox_sessions (rowid, wallbox_id, start_time, kwh, eff_price)
     * @param  int    $typeId     fk_c_type_fees (TK_ELE)
     * @return bool
     */
    private function addSessionLine($reportId, $session, $typeId)
    {
        $sid = (int) $session->rowid;

        // Duplikat-Check
        $chk = "SELECT rowid FROM ".MAIN_DB_PREFIX."expensereport_det"
             . " WHERE fk_expensereport = ".(int)$reportId
             . " AND comments LIKE '%[sid:".$sid."]%'";
        $res = $this->db->query($chk);
        if ($res && $this->db->num_rows($res) > 0) {
            return false;
        }

        $qty       = (float) $session->kwh;
        $unitPrice = (float) $session->eff_price;
        $total     = round($qty * $unitPrice, 2);
        $ts        = strtotime($session->start_time);
        $comment   = $this->db->escape(
            trim($session->wallbox_id).' '.date('d.m.Y H:i', $ts).' [sid:'.$sid.']'
        );

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."expensereport_det"
             . " (fk_expensereport, fk_c_type_fees, comments, qty, value_unit,"
             . "  total_ht, tva_tx, total_tva, total_ttc, date, fk_projet, rule_warning_ignored)"
             . " VALUES ("
             . (int)$reportId.", "
             . (int)$typeId.", "
             . "'".$comment."', "
             . $qty.", "
             . $unitPrice.", "
             . $total.", "
             . "0, 0, ".$total.", "
             . "'".$this->db->idate($ts)."', "
             . "0, 1)";

        return (bool) $this->db->query($sql);
    }

    /**
     * Aktualisiert Summen in llx_expensereport nach Zeilen-Änderungen
     *
     * @param int $reportId
     */
    private function updateExpenseReportTotals($reportId)
    {
        $id = (int) $reportId;
        // Dolibarr speichert total_ht und total_ttc im Report-Header
        $sql = "UPDATE ".MAIN_DB_PREFIX."expensereport er"
             . " SET er.total_ht  = (SELECT COALESCE(SUM(d.total_ht),  0) FROM ".MAIN_DB_PREFIX."expensereport_det d WHERE d.fk_expensereport = $id),"
             . "     er.total_ttc = (SELECT COALESCE(SUM(d.total_ttc), 0) FROM ".MAIN_DB_PREFIX."expensereport_det d WHERE d.fk_expensereport = $id)"
             . " WHERE er.rowid = $id";
        $this->db->query($sql);
    }

    /**
     * Validiert alle Entwurfs-Spesenabrechnungen eines Monats (M-2 auto-cycle)
     *
     * @param int  $month
     * @param int  $year
     * @param User $user
     */
    private function validateDraftReportsForMonth($month, $year, $user)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."expensereport"
             . " WHERE status = ".ExpenseReport::STATUS_DRAFT
             . " AND YEAR(date_debut) = ".(int)$year
             . " AND MONTH(date_debut) = ".(int)$month;

        $res = $this->db->query($sql);
        if (!$res) return;

        while ($obj = $this->db->fetch_object($res)) {
            $er = new ExpenseReport($this->db);
            if ($er->fetch((int) $obj->rowid) > 0) {
                $ret = $er->validate($user);
                if ($ret < 0) {
                    dol_syslog(
                        "WallboxBilling: Validierung Report #".$obj->rowid." fehlgeschlagen: ".$er->error,
                        LOG_WARNING
                    );
                }
            }
        }
    }

    /**
     * Stellt Spesenkategorie TK_ELE (Stromkosten) sicher und gibt ihre ID zurück
     *
     * @return int rowid oder 0
     */
    private function getOrCreateExpenseType()
    {
        global $conf;

        // ACHTUNG: llx_c_type_fees verwendet `id` als PK, nicht `rowid` wie
        // andere Dolibarr-Tabellen. SELECT auf rowid würde "Unknown column"
        // werfen und der SELECT-Pfad würde übersprungen → INSERT scheitert
        // am UNIQUE-Constraint auf `code`.
        $sql = "SELECT id, active FROM ".MAIN_DB_PREFIX."c_type_fees WHERE code = 'TK_ELE'";
        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res))) {
            if ((int) $obj->active !== 1) {
                $this->db->query("UPDATE ".MAIN_DB_PREFIX."c_type_fees SET active = 1 WHERE id = ".(int) $obj->id);
                dol_syslog("WallboxBilling: TK_ELE (id=".(int)$obj->id.") reaktiviert", LOG_INFO);
            }
            return (int) $obj->id;
        }

        // Neu anlegen
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."c_type_fees"
             . " (code, label, active)"
             . " VALUES ('TK_ELE', 'Stromkosten (Wallbox)', 1)";
        if ($this->db->query($sql)) {
            return (int) $this->db->last_insert_id(MAIN_DB_PREFIX."c_type_fees");
        }

        // INSERT fehlgeschlagen — echte SQL-Fehlermeldung loggen + in $this->error speichern
        $sqlErr = $this->db->lasterror();
        dol_syslog("WallboxBilling: TK_ELE INSERT failed: ".$sqlErr." | SQL: ".$sql, LOG_ERR);
        $this->error_sql = $sqlErr;
        return 0;
    }

    // -------------------------------------------------------------------------
    // Öffentliche Hilfsmethoden (auch von bill.php / Templates genutzt)
    // -------------------------------------------------------------------------

    /**
     * Berechnet Start- und End-Datum eines Monats
     *
     * @param  int   $month  1-12, 0 = Vormonat
     * @param  int   $year   0 = aktuelles Jahr
     * @return array  ['start', 'end', 'month', 'year']
     */
    public function getMonthDateRange($month = 0, $year = 0)
    {
        if ($month == 0) {
            $month = (int) date('n') - 1;
            if ($month == 0) {
                $month = 12;
                $year  = (int) date('Y') - 1;
            }
        }
        if ($year == 0) {
            $year = (int) date('Y');
        }

        $lastDay = date('t', mktime(0, 0, 0, $month, 1, $year));

        return array(
            'start' => sprintf('%04d-%02d-01 00:00:00', $year, $month),
            'end'   => sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $lastDay),
            'month' => $month,
            'year'  => $year,
        );
    }

    /** @param string $timestamp SQL datetime */
    public function formatDate($timestamp)
    {
        if (empty($timestamp)) return '';
        return (new DateTime($timestamp))->format('d.m.Y');
    }

    /** @param string $str */
    public function escape($str)
    {
        return $this->db->escape($str);
    }
}
?>

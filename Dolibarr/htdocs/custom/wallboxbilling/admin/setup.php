<?php
/**
 * Wallbox Billing — Admin/Setup-Seite
 */

// Fehler sichtbar machen statt 500 (kann nach Debugging entfernt werden)
@ini_set('display_errors', 0);
@ini_set('log_errors', 1);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp  = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, $i + 1).'/main.inc.php')) {
    $res = @include substr($tmp, 0, $i + 1).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, $i + 1)).'/main.inc.php')) {
    $res = @include dirname(substr($tmp, 0, $i + 1)).'/main.inc.php';
}
if (!$res && file_exists('../../../../main.inc.php')) {
    $res = @include '../../../../main.inc.php';
}
if (!$res && file_exists('../../../../../main.inc.php')) {
    $res = @include '../../../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/wallboxbilling.lib.php';

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array('admin', 'wallboxbilling@wallboxbilling'));

// --- Auto-Migration: fehlende Spalten nachrüsten ---
$resql_cols = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."wallbox_rfid");
if ($resql_cols) {
    $existing = array();
    while ($col = $db->fetch_object($resql_cols)) {
        $existing[] = strtolower($col->Field);
    }
    $db->free($resql_cols);
    if (!in_array('price_kwh', $existing)) {
        $db->query("ALTER TABLE ".MAIN_DB_PREFIX."wallbox_rfid ADD COLUMN price_kwh DECIMAL(10,4) DEFAULT NULL AFTER label");
    }
    if (!in_array('entity', $existing)) {
        $db->query("ALTER TABLE ".MAIN_DB_PREFIX."wallbox_rfid ADD COLUMN entity INTEGER DEFAULT 1 AFTER active");
        $db->query("UPDATE ".MAIN_DB_PREFIX."wallbox_rfid SET entity = 1 WHERE entity IS NULL");
    }
}

$action = GETPOST('action', 'aZ09');

// --- CSRF-Token manuell prüfen (ohne checkToken() — Dolibarr-versionsunabhängig) ---
$submitted_token = GETPOST('token', 'alpha');
$token_ok = (empty($_SESSION['newtoken']) || $submitted_token === $_SESSION['newtoken']);

// --- Aktionen verarbeiten ---
$save_error = '';
try {
    if ($action === 'update' && !empty($submitted_token) && $token_ok) {
        $price = price2num(GETPOST('WALLBOXBILLING_DEFAULT_PRICE', 'alpha'));
        dolibarr_set_const($db, 'WALLBOXBILLING_DEFAULT_PRICE', $price, 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
    }

    // RFID-Tag zu User HINZUFÜGEN — reaktiviert deaktivierte Karte desselben Users
    if ($action === 'add_rfid' && !empty($submitted_token) && $token_ok) {
        $user_id  = GETPOST('user_id', 'int');
        $rfid_hex = trim(GETPOST('rfid_hex', 'alpha'));

        if ($user_id > 0 && !empty($rfid_hex)) {
            $rfid_hash = hash('sha256', strtoupper($rfid_hex));

            // Existiert dieser Hash bereits? (egal ob active oder inactive)
            $resExist = $db->query("SELECT rowid, fk_user, active FROM ".MAIN_DB_PREFIX."wallbox_rfid"
                ." WHERE rfid_hash = '".$db->escape($rfid_hash)."'");
            if ($resExist && ($oExist = $db->fetch_object($resExist))) {
                if ((int) $oExist->fk_user === (int) $user_id) {
                    if ((int) $oExist->active === 1) {
                        setEventMessages('Diese Karte ist bereits aktiv zugeordnet.', null, 'warnings');
                    } else {
                        // Reaktivieren — Karte gehörte schon diesem User
                        $db->query("UPDATE ".MAIN_DB_PREFIX."wallbox_rfid SET active = 1"
                            ." WHERE rowid = ".(int)$oExist->rowid);
                        dol_syslog('wallboxbilling: RFID reaktiviert user='.$user_id.' hash='.substr($rfid_hash, 0, 16).'...', LOG_INFO);
                        setEventMessages('Karte '.dol_escape_htmltag(strtoupper($rfid_hex)).' reaktiviert.', null, 'mesgs');
                    }
                } else {
                    // Hash gehört einem ANDEREN User — Aufbewahrungspflicht: nie überschreiben
                    setEventMessages(
                        'Diese Karte ist bereits Benutzer #'.(int)$oExist->fk_user
                        .' zugeordnet ('.((int)$oExist->active === 1 ? 'aktiv' : 'deaktiviert')
                        .'). Wegen Aufbewahrungspflicht (10 Jahre) kann das Mapping nicht überschrieben werden.',
                        null, 'errors'
                    );
                }
            } else {
                $defPrice = getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', '0.30');
                $resPrice = $db->query("SELECT price_kwh FROM ".MAIN_DB_PREFIX."wallbox_rfid"
                    ." WHERE fk_user = ".(int)$user_id." AND entity = ".(int)$conf->entity
                    ." AND price_kwh IS NOT NULL LIMIT 1");
                if ($resPrice && ($oPrice = $db->fetch_object($resPrice))) {
                    $defPrice = $oPrice->price_kwh;
                }

                $sql = "INSERT INTO ".MAIN_DB_PREFIX."wallbox_rfid"
                    ." (fk_user, rfid_hash, label, price_kwh, active, entity, date_creation)"
                    ." VALUES (".(int)$user_id
                    .", '".$db->escape($rfid_hash)."'"
                    .", '".$db->escape(strtoupper($rfid_hex))."'"
                    .", ".(float)$defPrice
                    .", 1, ".(int)$conf->entity
                    .", '".$db->idate(dol_now())."')";
                if ($db->query($sql)) {
                    dol_syslog('wallboxbilling: RFID hinzugefügt user='.$user_id.' hash='.substr($rfid_hash, 0, 16).'...', LOG_INFO);
                    setEventMessages('Karte '.dol_escape_htmltag(strtoupper($rfid_hex)).' hinzugefügt.', null, 'mesgs');
                } else {
                    setEventMessages($db->lasterror(), null, 'errors');
                }
            }
        }
    }

    // Einzelnen RFID-Tag DEAKTIVIEREN (Soft-Delete) — Mapping bleibt für
    // Steuerprüfung erhalten (10 Jahre Aufbewahrungspflicht §147 AO).
    if ($action === 'delete_rfid' && !empty($submitted_token) && $token_ok) {
        $rfid_id = GETPOST('rfid_id', 'int');
        if ($rfid_id > 0) {
            if ($db->query("UPDATE ".MAIN_DB_PREFIX."wallbox_rfid SET active = 0"
                ." WHERE rowid = ".(int)$rfid_id." AND entity = ".(int)$conf->entity)) {
                dol_syslog('wallboxbilling: RFID deaktiviert rowid='.$rfid_id, LOG_INFO);
                setEventMessages('Karte deaktiviert (Mapping bleibt für Aufbewahrungspflicht erhalten).', null, 'mesgs');
            } else {
                setEventMessages($db->lasterror(), null, 'errors');
            }
        }
    }

    // Deaktivierte Karte REAKTIVIEREN
    if ($action === 'reactivate_rfid' && !empty($submitted_token) && $token_ok) {
        $rfid_id = GETPOST('rfid_id', 'int');
        if ($rfid_id > 0) {
            if ($db->query("UPDATE ".MAIN_DB_PREFIX."wallbox_rfid SET active = 1"
                ." WHERE rowid = ".(int)$rfid_id." AND entity = ".(int)$conf->entity)) {
                setEventMessages('Karte reaktiviert.', null, 'mesgs');
            } else {
                setEventMessages($db->lasterror(), null, 'errors');
            }
        }
    }

    // Preis-pro-kWh für ALLE AKTIVEN Tags eines Users aktualisieren —
    // historische (deaktivierte) Mappings bleiben unverändert.
    if ($action === 'update_price' && !empty($submitted_token) && $token_ok) {
        $user_id = GETPOST('user_id', 'int');
        $price   = price2num(GETPOST('price_kwh', 'alpha'));
        if ($user_id > 0) {
            if ($db->query("UPDATE ".MAIN_DB_PREFIX."wallbox_rfid"
                ." SET price_kwh = ".(float)$price
                ." WHERE fk_user = ".(int)$user_id." AND entity = ".(int)$conf->entity
                ." AND active = 1")) {
                setEventMessages('Preis aktualisiert.', null, 'mesgs');
            } else {
                setEventMessages($db->lasterror(), null, 'errors');
            }
        }
    }
    // Test-Abrechnung und Diagnose-Test-Session entfernt in 1.1.0 —
    // Sessions werden jetzt vom HA-Addon direkt in die Spesenabrechnung
    // geschrieben, kein manueller Anstoß mehr nötig.

    // GHOST-SESSIONS BEREINIGEN: löscht 0-kWh-Zeilen die durch fehlerhafte
    // Phantom-Ladungen (Karte gelesen ohne tatsächliche Ladung) in
    // Spesenabrechnungen gelandet sind. Filter: qty=0 + unser [wbx:]-Marker
    // im Comment → garantiert eine vom Modul erzeugte Geisterzeile.
    if ($action === 'cleanup_ghost_lines' && !empty($submitted_token) && $token_ok) {
        // Erst die betroffenen Reports zwischenspeichern für Summen-Update
        $resReports = $db->query(
            "SELECT DISTINCT fk_expensereport FROM ".MAIN_DB_PREFIX."expensereport_det"
            ." WHERE qty = 0 AND comments LIKE '%[wbx:%'"
        );
        $affectedReports = array();
        if ($resReports) {
            while ($r = $db->fetch_object($resReports)) {
                $affectedReports[] = (int) $r->fk_expensereport;
            }
        }

        // Ghost-Zeilen zählen + löschen
        $resCount = $db->query(
            "SELECT COUNT(*) AS cnt FROM ".MAIN_DB_PREFIX."expensereport_det"
            ." WHERE qty = 0 AND comments LIKE '%[wbx:%'"
        );
        $deleted = 0;
        if ($resCount && ($oC = $db->fetch_object($resCount))) {
            $deleted = (int) $oC->cnt;
        }

        if ($deleted > 0) {
            $db->query(
                "DELETE FROM ".MAIN_DB_PREFIX."expensereport_det"
                ." WHERE qty = 0 AND comments LIKE '%[wbx:%'"
            );

            // Summen in den betroffenen Reports neu berechnen
            foreach ($affectedReports as $rid) {
                $db->query(
                    "UPDATE ".MAIN_DB_PREFIX."expensereport er"
                    ." SET er.total_ht  = (SELECT COALESCE(SUM(d.total_ht),  0) FROM ".MAIN_DB_PREFIX."expensereport_det d WHERE d.fk_expensereport = ".(int)$rid."),"
                    ."     er.total_ttc = (SELECT COALESCE(SUM(d.total_ttc), 0) FROM ".MAIN_DB_PREFIX."expensereport_det d WHERE d.fk_expensereport = ".(int)$rid.")"
                    ." WHERE er.rowid = ".(int)$rid
                );
            }

            dol_syslog('wallboxbilling: '.$deleted.' Ghost-Zeilen aus '.count($affectedReports).' Reports entfernt', LOG_INFO);
            setEventMessages(
                $deleted.' Ghost-Zeile(n) (0 kWh) aus '.count($affectedReports).' Spesenabrechnung(en) entfernt. '
                .'Summen wurden neu berechnet.',
                null, 'mesgs'
            );
        } else {
            setEventMessages('Keine Ghost-Zeilen gefunden — Spesenabrechnungen sind sauber.', null, 'mesgs');
        }
    }

    if ($action === 'uninstall_module' && !empty($submitted_token) && $token_ok) {
        $confirm = GETPOST('confirm_uninstall', 'alpha');
        if ($confirm !== 'JA') {
            setEventMessages('Bitte "JA" eingeben um die Deaktivierung zu bestätigen.', null, 'warnings');
        } else {
            $db->begin();
            $err = 0;

            // WICHTIG: llx_wallbox_rfid wird NICHT gedroppt — Steuerprüfungs-
            // Aufbewahrungspflicht (§147 AO, 10 Jahre). Das Mapping muss
            // nachvollziehbar bleiben welche Karte zu welchem Mitarbeiter
            // gehörte. Stattdessen werden alle Tags soft-deaktiviert.
            if (!$db->query("UPDATE ".MAIN_DB_PREFIX."wallbox_rfid SET active = 0"
                ." WHERE entity = ".(int)$conf->entity)) {
                $err++;
                setEventMessages('Deaktivierung wallbox_rfid: '.$db->lasterror(), null, 'errors');
            }

            // Modul-Konstanten entfernen (sind nicht aufbewahrungspflichtig)
            $db->query("DELETE FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'WALLBOXBILLING_%' AND entity = ".(int)$conf->entity);

            // Modul-Aktivierung entfernen
            $db->query("DELETE FROM ".MAIN_DB_PREFIX."const WHERE name = 'MAIN_MODULE_WALLBOXBILLING' AND entity = ".(int)$conf->entity);

            // TK_ELE bleibt — könnte in alten Spesenabrechnungen referenziert sein

            if ($err === 0) {
                $db->commit();
                setEventMessages(
                    'Modul deaktiviert. Aufbewahrungspflichtige Daten (RFID-Mapping, Spesenabrechnungen, TK_ELE) bleiben erhalten. '
                    .'PHP-Dateien unter custom/wallboxbilling/ können manuell entfernt werden.',
                    null, 'mesgs'
                );
            } else {
                $db->rollback();
            }
        }
    }
} catch (Throwable $e) {
    $save_error = get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine();
    dol_syslog('wallboxbilling setup error: '.$save_error, LOG_ERR);
}

// --- Ausgabe ---
$token = newToken();

$page_title = $langs->trans('WallboxBillingSetup');
llxHeader('', $page_title);

$head = wallboxbillingPrepareHead();
print dol_get_fiche_head($head, 'setup', $page_title, -1, 'fa-bolt');

if (!empty($save_error)) {
    print '<div class="error"><b>Debug-Fehler (bitte melden):</b><br>'.dol_escape_htmltag($save_error).'</div><br>';
}
if (!$token_ok && !empty($submitted_token)) {
    print '<div class="warning">Token abgelaufen – bitte Seite neu laden und erneut speichern.</div><br>';
}

// --- Konfiguration ---
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$token.'">';
print '<input type="hidden" name="action" value="update">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('WallboxConfiguration').'</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('DefaultPricePerKwh').'</td>';
print '<td><input type="text" name="WALLBOXBILLING_DEFAULT_PRICE" class="flat" size="8"'
    .' value="'.getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', '0.30').'">'
    .' &euro;/kWh</td></tr>';
print '</table>';
print '<div class="center" style="margin-top:10px">';
print '<input type="submit" class="button" value="'.$langs->trans('Save').'">';
print '</div></form>';

print '<br>';

// --- RFID-Verwaltung (Multi-Tag pro Benutzer) ---
print load_fiche_titre($langs->trans('WallboxUserRFIDManagement'));

// Benutzer laden
$sqlUsers = "SELECT rowid, login, lastname, firstname"
          ." FROM ".MAIN_DB_PREFIX."user"
          ." WHERE statut = 1 AND entity = ".(int)$conf->entity
          ." ORDER BY login";
$resUsers = $db->query($sqlUsers);

// RFID-Tags pro User vorladen — getrennt nach aktiv/inaktiv
$tagsByUser = array();
$resTags = $db->query("SELECT rowid, fk_user, rfid_hash, label, price_kwh, active"
    ." FROM ".MAIN_DB_PREFIX."wallbox_rfid"
    ." WHERE entity = ".(int)$conf->entity
    ." ORDER BY fk_user, active DESC, rowid");
if ($resTags) {
    while ($t = $db->fetch_object($resTags)) {
        $tagsByUser[(int)$t->fk_user][] = $t;
    }
    $db->free($resTags);
}

$defaultPrice = getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', '0.30');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td style="width:20%">'.$langs->trans('User').'</td>';
print '<td>RFID-Karten</td>';
print '<td style="width:18%">'.$langs->trans('PricePerKWh').'</td>';
print '</tr>';

if ($resUsers) {
    while ($u = $db->fetch_object($resUsers)) {
        $uid       = (int) $u->rowid;
        $allTags   = isset($tagsByUser[$uid]) ? $tagsByUser[$uid] : array();
        $activeTags   = array_filter($allTags, function($t) { return (int)$t->active === 1; });
        $inactiveTags = array_filter($allTags, function($t) { return (int)$t->active !== 1; });
        $userPrice = !empty($activeTags) && !empty(reset($activeTags)->price_kwh)
            ? price2num(reset($activeTags)->price_kwh) : $defaultPrice;

        print '<tr class="oddeven">';
        // Spalte 1: Benutzer
        print '<td style="vertical-align:top;padding-top:8px"><b>'.dol_escape_htmltag($u->login).'</b><br>';
        print '<span class="opacitymedium small">'.dol_escape_htmltag(trim($u->firstname.' '.$u->lastname)).'</span></td>';

        // Spalte 2: aktive Karten + Add-Form + (Toggle) inaktive Karten
        print '<td style="vertical-align:top">';
        if (empty($activeTags)) {
            print '<span class="opacitymedium small">— keine aktiven Karten —</span><br>';
        } else {
            foreach ($activeTags as $t) {
                print '<div style="margin-bottom:4px;padding:4px 8px;background:#eaf6ea;border-radius:3px;display:inline-block;margin-right:6px">';
                print '<code>'.dol_escape_htmltag((string)$t->label).'</code>';
                print ' <span class="opacitymedium small" title="'.dol_escape_htmltag($t->rfid_hash).'">('.substr($t->rfid_hash, 0, 12).'…)</span>';
                print ' <form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;margin-left:6px">';
                print '<input type="hidden" name="token" value="'.$token.'">';
                print '<input type="hidden" name="action" value="delete_rfid">';
                print '<input type="hidden" name="rfid_id" value="'.(int)$t->rowid.'">';
                print '<button type="submit" class="button smallpaddingimp" style="padding:1px 6px;color:#c00"'
                    .' onclick="return confirm(\'Karte '.dol_escape_js($t->label).' deaktivieren? Das Mapping bleibt für Aufbewahrungspflicht erhalten.\');"'
                    .' title="Deaktivieren (Soft-Delete)">×</button>';
                print '</form>';
                print '</div>';
            }
            print '<br>';
        }
        // Add-Form für neuen Tag
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline-block;margin-top:4px">';
        print '<input type="hidden" name="token" value="'.$token.'">';
        print '<input type="hidden" name="action" value="add_rfid">';
        print '<input type="hidden" name="user_id" value="'.$uid.'">';
        print '<input type="text" name="rfid_hex" class="flat" size="14" placeholder="USER TAG ID" style="text-transform:uppercase">';
        print ' <input type="submit" class="button smallpaddingimp" value="+ Karte hinzufügen">';
        print '</form>';

        // Deaktivierte (Historie): ausklappbar
        if (!empty($inactiveTags)) {
            $detailsId = 'inactive_'.$uid;
            print '<div style="margin-top:6px">';
            print '<details><summary class="opacitymedium small" style="cursor:pointer">'
                .'Historie: '.count($inactiveTags).' deaktivierte Karte(n)</summary>';
            print '<div style="margin-top:4px">';
            foreach ($inactiveTags as $t) {
                print '<div style="margin-bottom:4px;padding:4px 8px;background:#f3f3f3;border-radius:3px;display:inline-block;margin-right:6px;color:#888">';
                print '<code style="text-decoration:line-through">'.dol_escape_htmltag((string)$t->label).'</code>';
                print ' <span class="opacitymedium small">('.substr($t->rfid_hash, 0, 12).'…)</span>';
                print ' <form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;margin-left:6px">';
                print '<input type="hidden" name="token" value="'.$token.'">';
                print '<input type="hidden" name="action" value="reactivate_rfid">';
                print '<input type="hidden" name="rfid_id" value="'.(int)$t->rowid.'">';
                print '<button type="submit" class="button smallpaddingimp" style="padding:1px 6px" title="Reaktivieren">↻</button>';
                print '</form>';
                print '</div>';
            }
            print '</div></details></div>';
        }
        print '</td>';

        // Spalte 3: Preis
        print '<td style="vertical-align:top">';
        if (!empty($activeTags)) {
            print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
            print '<input type="hidden" name="token" value="'.$token.'">';
            print '<input type="hidden" name="action" value="update_price">';
            print '<input type="hidden" name="user_id" value="'.$uid.'">';
            print '<input type="text" name="price_kwh" class="flat" size="6"'
                .' value="'.dol_escape_htmltag((string)$userPrice).'"> &euro;/kWh';
            print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
            print '</form>';
        } else {
            print '<span class="opacitymedium small">'.$defaultPrice.' &euro;/kWh (Default)</span>';
        }
        print '</td>';
        print '</tr>';
    }
    $db->free($resUsers);
}
print '</table>';

// --- Ghost-Sessions aus Spesenabrechnungen bereinigen ---
// Zählt vorab wie viele Ghost-Zeilen existieren um den Button informativ zu beschriften
$ghostCount = 0;
$resGhostCount = $db->query(
    "SELECT COUNT(*) AS cnt FROM ".MAIN_DB_PREFIX."expensereport_det"
    ." WHERE qty = 0 AND comments LIKE '%[wbx:%'"
);
if ($resGhostCount && ($oG = $db->fetch_object($resGhostCount))) {
    $ghostCount = (int) $oG->cnt;
}

print '<br>';
print load_fiche_titre('Spesenabrechnungen bereinigen');
print '<p class="opacitymedium small" style="margin:0 0 10px 0">'
    .'Entfernt 0-kWh-Zeilen die vor 1.2.2 durch fehlerhaft erfasste Phantom-Ladungen '
    .'(Karte gelesen ohne Ladung) in den Spesenabrechnungen gelandet sind. '
    .'Erkannt am <code>[wbx:...]</code>-Marker im Kommentar — andere Positionen bleiben unangetastet. '
    .'Summen der betroffenen Reports werden automatisch neu berechnet.'
    .'</p>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin-bottom:8px">';
print '<input type="hidden" name="token" value="'.$token.'">';
print '<input type="hidden" name="action" value="cleanup_ghost_lines">';
if ($ghostCount > 0) {
    print '<input type="submit" class="button" value="'.$ghostCount.' Ghost-Zeile(n) jetzt entfernen"';
    print ' onclick="return confirm(\''.$ghostCount.' 0-kWh-Zeile(n) wirklich aus den Spesenabrechnungen löschen?\');">';
} else {
    print '<input type="submit" class="button" value="Keine Ghost-Zeilen vorhanden" disabled style="opacity:0.5;cursor:not-allowed">';
}
print '</form>';

// --- Modul deaktivieren ---
print '<br>';
print load_fiche_titre('Modul deaktivieren');
print '<p class="opacitymedium small" style="margin:0 0 10px 0">'
    .'Alle aktiven RFID-Karten werden deaktiviert, Modulkonstanten und Aktivierung werden entfernt. '
    .'<b>Das RFID→User-Mapping bleibt aus Aufbewahrungsgründen (§147 AO, 10 Jahre) erhalten.</b> '
    .'Spesenabrechnungen und TK_ELE werden nicht angetastet.'
    .'</p>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$token.'">';
print '<input type="hidden" name="action" value="uninstall_module">';
print '<table class="noborder" style="width:auto">';
print '<tr><td style="padding-right:10px"><label>Sicherheitsbestätigung:</label></td>';
print '<td><input type="text" name="confirm_uninstall" class="flat" size="6" placeholder="JA"';
print ' style="border:2px solid #e05353;font-weight:bold;text-transform:uppercase">';
print ' <span class="opacitymedium small">→ Tippe <b>JA</b> um zu bestätigen</span></td></tr>';
print '</table>';
print '<div style="margin-top:8px">';
print '<input type="submit" class="button buttonDelete" value="Modul deaktivieren"';
print ' onclick="return confirm(\'Modul deaktivieren? RFID-Mappings bleiben für Aufbewahrungspflicht erhalten.\');">';
print '</div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
?>

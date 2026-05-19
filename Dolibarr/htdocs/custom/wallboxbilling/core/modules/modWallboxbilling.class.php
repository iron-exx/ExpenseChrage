<?php
/**
 *  modWallboxbilling.class.php - Wallbox Billing Modul Descriptor
 *
 *  @author    Wallbox-Dolibarr Team
 *  @version   1.0.0
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Klasse für Wallbox Billing Modul
 */
class modWallboxbilling extends DolibarrModules
{
    /**
     * Konstruktor
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;
        $this->numero = 104000; // Modul-Nummer (frei wählbar, > 100000)
        $this->rights_class = 'wallboxbilling';
        $this->family = "financial"; // Familie: Finanzen
        $this->module_position = 80; // Position im Menü

        // name muss ein einfacher String sein — kein Array (Dolibarr-Pflicht)
        $this->name = preg_replace('/^mod/i', '', get_class($this)); // → 'Wallboxbilling'
        $this->description = 'WallboxbillingDescription'; // wird über lang-Datei aufgelöst

        $this->version = '1.0.6';
        // const_name muss ein gültiger SQL/PHP-Konstanten-Name sein —
        // strtoupper(name) enthält Leerzeichen, daher fix auf den Modul-Slug.
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name); // MAIN_MODULE_WALLBOXBILLING
        $this->special = 0;
        $this->picto = 'fa-bolt'; // FontAwesome-Picto (universell verfügbar)
        $this->editor_name = 'Wallbox-Dolibarr';
        $this->editor_url = '';

        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(19, 0);
        $this->module_parts = array(
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'theme' => 0,
            'css' => array(),
            'js' => array(),
            'hooks' => array(),
            'moduleforexternal' => 0,
            'apis' => 1,
        );
        $this->config_page_url = array('setup.php@wallboxbilling');

        // Abhängigkeiten
        $this->depends = array(); // Keine besonderen Abhängigkeiten
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("wallboxbilling@wallboxbilling");

        // API-Endpunkt Registrierung (API-01, API-02)
        // Der API-Endpoint wird über Dolibarr REST API exponiert:
        // POST /api/index.php/wallboxbilling/session
        $this->api_class = array('WallboxbillingApi');

        // Berechtigungen definieren (D-08, SEC-04)
        $this->rights = array();

        $r = 0;

        $this->rights[$r][0] = 104001;
        $this->rights[$r][1] = 'View own charging sessions';
        $this->rights[$r][4] = 'session';
        $this->rights[$r][5] = 'read';
        $r++;

        $this->rights[$r][0] = 104002;
        $this->rights[$r][1] = 'Manage all charging sessions and users';
        $this->rights[$r][4] = 'session';
        $this->rights[$r][5] = 'write';
        $r++;

        $this->rights[$r][0] = 104003;
        $this->rights[$r][1] = 'Create monthly billing and invoices';
        $this->rights[$r][4] = 'billing';
        $this->rights[$r][5] = 'write';
        $r++;

        // Cron-Jobs registrieren (BIL-01)
        $this->cronjobs = array(
            0 => array(
                'entity' => 0,
                'label' => 'Wallbox Monthly Billing',
                'jobtype' => 'method',
                'class' => 'wallboxbilling/class/billing.class.php',
                'objectname' => 'WallboxBillingCron',
                'method' => 'runMonthlyBilling',
                'parameters' => '',
                'comment' => 'Monatliche Wallbox-Abrechnung ausführen',
                'frequency' => 1,
                'unitfrequency' => 3600 * 24 * 30,  // ~30 Tage (Monat)
                'priority' => 50,
                'status' => 1,  // Aktiviert
                'test' => '$conf->wallboxbilling->enabled'
            )
        );

        // Menüs (Hauptmenü + Untermenüs)
        $this->menu = array();
        $m = 0;
        $this->menu[$m++] = array(
            'fk_menu' => 0,
            'type' => 'top',
            'titre' => 'WallboxBilling',
            'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
            'mainmenu' => 'wallboxbilling',
            'leftmenu' => '',
            'url' => '/custom/wallboxbilling/index.php',
            'langs' => 'wallboxbilling@wallboxbilling',
            'position' => 1000 + $m,
            'enabled' => '$conf->wallboxbilling->enabled',
            'perms' => '1',
            'target' => '',
            'user' => 2,
        );
        $this->menu[$m++] = array(
            'fk_menu' => 'fk_mainmenu=wallboxbilling',
            'type' => 'left',
            'titre' => 'WallboxSessions',
            'mainmenu' => 'wallboxbilling',
            'leftmenu' => 'wallboxbilling_sessions',
            'url' => '/custom/wallboxbilling/index.php',
            'langs' => 'wallboxbilling@wallboxbilling',
            'position' => 1000 + $m,
            'enabled' => '$conf->wallboxbilling->enabled',
            'perms' => '$user->hasRight("wallboxbilling","user")',
            'target' => '',
            'user' => 2,
        );
        $this->menu[$m++] = array(
            'fk_menu' => 'fk_mainmenu=wallboxbilling',
            'type' => 'left',
            'titre' => 'MonthlyBilling',
            'mainmenu' => 'wallboxbilling',
            'leftmenu' => 'wallboxbilling_bill',
            'url' => '/custom/wallboxbilling/bill.php',
            'langs' => 'wallboxbilling@wallboxbilling',
            'position' => 1000 + $m,
            'enabled' => '$conf->wallboxbilling->enabled',
            'perms' => '$user->hasRight("wallboxbilling","billing")',
            'target' => '',
            'user' => 2,
        );
        $this->menu[$m++] = array(
            'fk_menu' => 'fk_mainmenu=wallboxbilling',
            'type' => 'left',
            'titre' => 'WallboxBillingSetup',
            'mainmenu' => 'wallboxbilling',
            'leftmenu' => 'wallboxbilling_setup',
            'url' => '/custom/wallboxbilling/admin/setup.php',
            'langs' => 'wallboxbilling@wallboxbilling',
            'position' => 1000 + $m,
            'enabled' => '$conf->wallboxbilling->enabled',
            'perms' => '$user->admin',
            'target' => '',
            'user' => 2,
        );

        // Export-Module registrieren (EXT-02, EXT-03)
        $this->export_modules = array(
            0 => array(
                'label' => 'Wallbox Billing',
                'type' => 'export',
                'export_label' => 'Wallbox Abrechnungen',
                'icon' => 'wallboxbilling@wallboxbilling',
                'class' => 'wallboxbilling/class/export.class.php'
            )
        );

        // WICHTIG: init() darf NICHT im Konstruktor aufgerufen werden — die
        // Methode wird von Dolibarr's Modul-Aktivierung explizit getriggert.
    }

    /**
     * Modul-Aktivierung (Dolibarr ruft das beim Aktivieren auf).
     *
     * Legt die Datenbank-Tabellen + Indizes an, registriert Cronjobs,
     * Berechtigungen, Menüs und Konstanten via _init().
     *
     * @param string $options Optionen (z.B. 'noboxes')
     * @return int 1 = OK, 0 = KO
     */
    public function init($options = '')
    {
        // SQL-Statements für Tabellen + Indizes (D-07, DB-03)
        $sql = array();

        // Haupt-Sessions Tabelle
        $sql[] = "CREATE TABLE IF NOT EXISTS `llx_wallbox_sessions` (
            `rowid` INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
            `fk_user` INTEGER NOT NULL DEFAULT 0,
            `rfid_hash` VARCHAR(64) NOT NULL,
            `wallbox_id` VARCHAR(50) NOT NULL DEFAULT 'alfen_eve',
            `start_time` DATETIME NOT NULL,
            `end_time` DATETIME NULL,
            `kwh` REAL NOT NULL DEFAULT 0.0,
            `price_per_kwh` REAL NOT NULL DEFAULT 0.30,
            `total_cost` REAL NOT NULL DEFAULT 0.0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `date_creation` DATETIME NOT NULL,
            `transmitted_at` DATETIME NULL,
            `tms` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        // RFID-Tabelle für User-Zuordnung
        $sql[] = "CREATE TABLE IF NOT EXISTS `llx_wallbox_rfid` (
            `rowid` INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
            `fk_user` INTEGER NOT NULL,
            `rfid_hash` VARCHAR(64) NOT NULL,
            `label` VARCHAR(255) DEFAULT '',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_creation` DATETIME NOT NULL,
            `tms` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_rfid_hash` (`rfid_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        // Billing History Tabelle (BIL-01, BIL-02, BIL-03)
        $sql[] = "CREATE TABLE IF NOT EXISTS `llx_wallbox_billing_history` (
            `rowid` INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
            `fk_user` INTEGER NOT NULL,
            `billing_month` INTEGER NOT NULL,
            `billing_year` INTEGER NOT NULL,
            `total_kwh` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `price_per_kwh` DECIMAL(10,4) NOT NULL,
            `total_cost` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `session_count` INTEGER NOT NULL DEFAULT 0,
            `session_details` LONGTEXT,
            `fk_user_creator` INTEGER NOT NULL,
            `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `status` INTEGER NOT NULL DEFAULT 1,
            UNIQUE KEY `uk_user_month_year` (`fk_user`, `billing_month`, `billing_year`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // Indizes erstellen — _init() ignoriert "duplicate key" Fehler beim
        // erneuten Aktivieren, daher reicht plain CREATE INDEX.
        $sql[] = "CREATE INDEX idx_wallbox_sessions_rfid ON llx_wallbox_sessions(rfid_hash)";
        $sql[] = "CREATE INDEX idx_wallbox_sessions_user ON llx_wallbox_sessions(fk_user)";
        $sql[] = "CREATE INDEX idx_wallbox_sessions_status ON llx_wallbox_sessions(status)";
        $sql[] = "CREATE INDEX idx_wallbox_sessions_transmitted ON llx_wallbox_sessions(transmitted_at)";

        // Dolibarr-Standard: _init kümmert sich um Permissions, Konstanten,
        // Cronjobs und legt die Tabellen via $sql[] an.
        return $this->_init($sql, $options);
    }

    /**
     * Modul-Deaktivierung (Dolibarr Standard).
     *
     * @param string $options Optionen
     * @return int 1 = OK
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }

    /**
     * Legacy-Install-Routine (von älteren Versionen aufrufbar).
     * Wird beim normalen Dolibarr-Workflow nicht mehr verwendet — init()/remove()
     * sind jetzt die offiziellen Einstiegspunkte. Kann manuell aufgerufen
     * werden, um die transmitted_at-Spalte auf alten Installationen nachzurüsten.
     */
    public function install_legacy()
    {
        global $db, $conf;

        $error = 0;

        // SQL-Tabellen erstellen
        $sql = array();

        // Haupt-Sessions Tabelle (falls noch nicht vorhanden)
        $sql[] = "CREATE TABLE IF NOT EXISTS `llx_wallbox_sessions` (
            `rowid` INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
            `fk_user` INTEGER NOT NULL DEFAULT 0,
            `rfid_hash` VARCHAR(64) NOT NULL,
            `wallbox_id` VARCHAR(50) NOT NULL DEFAULT 'alfen_eve',
            `start_time` DATETIME NOT NULL,
            `end_time` DATETIME NULL,
            `kwh` REAL NOT NULL DEFAULT 0.0,
            `price_per_kwh` REAL NOT NULL DEFAULT 0.30,
            `total_cost` REAL NOT NULL DEFAULT 0.0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `date_creation` DATETIME NOT NULL,
            `transmitted_at` DATETIME NULL,
            `tms` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        // RFID-Tabelle für User-Zuordnung
        $sql[] = "CREATE TABLE IF NOT EXISTS `llx_wallbox_rfid` (
            `rowid` INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
            `fk_user` INTEGER NOT NULL,
            `rfid_hash` VARCHAR(64) NOT NULL,
            `label` VARCHAR(255) DEFAULT '',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_creation` DATETIME NOT NULL,
            `tms` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_rfid_hash` (`rfid_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        // Billing History Tabelle (BIL-01, BIL-02, BIL-03)
        $sql[] = "CREATE TABLE IF NOT EXISTS `llx_wallbox_billing_history` (
            `rowid` INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
            `fk_user` INTEGER NOT NULL,
            `billing_month` INTEGER NOT NULL,
            `billing_year` INTEGER NOT NULL,
            `total_kwh` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `price_per_kwh` DECIMAL(10,4) NOT NULL,
            `total_cost` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `session_count` INTEGER NOT NULL DEFAULT 0,
            `session_details` LONGTEXT,
            `fk_user_creator` INTEGER NOT NULL,
            `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `status` INTEGER NOT NULL DEFAULT 1,
            UNIQUE KEY `uk_user_month_year` (`fk_user`, `billing_month`, `billing_year`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // Indizes (Fehler bei bereits existierendem Index sind harmlos)
        $sql[] = "CREATE INDEX idx_wallbox_sessions_rfid ON llx_wallbox_sessions(rfid_hash)";
        $sql[] = "CREATE INDEX idx_wallbox_sessions_user ON llx_wallbox_sessions(fk_user)";
        $sql[] = "CREATE INDEX idx_wallbox_sessions_status ON llx_wallbox_sessions(status)";
        $sql[] = "CREATE INDEX idx_wallbox_sessions_transmitted ON llx_wallbox_sessions(transmitted_at)";

        foreach ($sql as $query) {
            $result = $db->query($query);
            if (!$result) {
                dol_syslog("WallboxBilling SQL error: ".$db->lasterror, LOG_ERR);
                // Non-fatal - table might already exist
            }
        }

        // transmitted_at Spalte hinzufügen falls nicht vorhanden (API-05, D-03)
        $check_col = "SHOW COLUMNS FROM llx_wallbox_sessions LIKE 'transmitted_at'";
        $res = $db->query($check_col);
        if (!$res || $db->num_rows($res) == 0) {
            $db->query("ALTER TABLE llx_wallbox_sessions ADD COLUMN transmitted_at DATETIME NULL AFTER date_creation");
        }

        // Billing History Tabelle prüfen und hinzufügen (BIL-01)
        $check_table = "SHOW TABLES LIKE 'llx_wallbox_billing_history'";
        $resTable = $db->query($check_table);
        if (!$resTable || $db->num_rows($resTable) == 0) {
            $db->query("CREATE TABLE IF NOT EXISTS `llx_wallbox_billing_history` (
                `rowid` INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
                `fk_user` INTEGER NOT NULL,
                `billing_month` INTEGER NOT NULL,
                `billing_year` INTEGER NOT NULL,
                `total_kwh` DECIMAL(10,2) NOT NULL DEFAULT 0,
                `price_per_kwh` DECIMAL(10,4) NOT NULL,
                `total_cost` DECIMAL(10,2) NOT NULL DEFAULT 0,
                `session_count` INTEGER NOT NULL DEFAULT 0,
                `session_details` LONGTEXT,
                `fk_user_creator` INTEGER NOT NULL,
                `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `status` INTEGER NOT NULL DEFAULT 1,
                UNIQUE KEY `uk_user_month_year` (`fk_user`, `billing_month`, `billing_year`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // Berechtigungen einrichten (D-08, SEC-04)
        $this->insert_permissions();

        return ($error == 0) ? 1 : 0;
    }

    /**
     * Modul-Upgrade (für bestehende Installationen)
     */
    public function upgrade($version_from, $version_to)
    {
        global $db;

        // Upgrade 3.0.0: transmitted_at Feld hinzufügen
        $check_col = "SHOW COLUMNS FROM llx_wallbox_sessions LIKE 'transmitted_at'";
        $res = $db->query($check_col);
        if (!$res || $db->num_rows($res) == 0) {
            $db->query("ALTER TABLE llx_wallbox_sessions ADD COLUMN transmitted_at DATETIME NULL");
            $db->query("CREATE INDEX idx_wallbox_sessions_transmitted ON llx_wallbox_sessions(transmitted_at)");
        }

        return 1;
    }

    /**
     * Modul-Deinstallation
     */
    public function uninstall()
    {
        global $db;

        $error = 0;

        // Berechtigungen entfernen
        $this->delete_permissions();

        return ($error == 0) ? 1 : 0;
    }
}
?>

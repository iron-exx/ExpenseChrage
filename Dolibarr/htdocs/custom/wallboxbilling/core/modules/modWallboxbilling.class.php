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

        $this->version = '1.1.0';
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
        // API-Endpoint: receive.php (custom PHP, kein REST-Routing über api_class)
        $this->api_class = array();

        // Berechtigungen — minimal in 1.1.0 (nur Konfigurationszugriff)
        $this->rights = array();
        $r = 0;
        $this->rights[$r][0] = 104001;
        $this->rights[$r][1] = 'Manage RFID mapping and pricing';
        $this->rights[$r][4] = 'config';
        $this->rights[$r][5] = 'write';
        $r++;

        // Ab 1.1.0: kein Cronjob mehr — Sessions werden direkt beim Empfang
        // in die Spesenabrechnung des Mitarbeiters geschrieben (siehe receive.php).
        $this->cronjobs = array();

        // Menüs (Hauptmenü + Untermenüs)
        // Ab 1.1.0: Sessions werden direkt in Spesenabrechnungen geschrieben.
        // Nur noch die Konfigurations-Seite ist erreichbar (RFID-Mapping, Preise).
        $this->menu = array();
        $m = 0;
        $this->menu[$m++] = array(
            'fk_menu' => 0,
            'type' => 'top',
            'titre' => 'WallboxBilling',
            'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
            'mainmenu' => 'wallboxbilling',
            'leftmenu' => '',
            'url' => '/custom/wallboxbilling/admin/setup.php',
            'langs' => 'wallboxbilling@wallboxbilling',
            'position' => 1000 + $m,
            'enabled' => '$conf->wallboxbilling->enabled',
            'perms' => '$user->admin',
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

        // Ab 1.1.0: kein Export-Modul mehr — die Ladevorgänge stecken in
        // den Standard-Spesenabrechnungen, die Dolibarr von Haus aus exportieren kann.
        $this->export_modules = array();

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
        // Alte Menü-Einträge zuerst löschen — verhindert "already exists" bei Neuinstallation
        $this->delete_menus();

        // SQL-Statements für Tabellen + Indizes
        // Ab 1.1.0: nur noch wallbox_rfid (RFID → User Mapping). Sessions werden
        // direkt in llx_expensereport_det geschrieben — keine eigene Session-Tabelle.
        $sql = array();

        $sql[] = "CREATE TABLE IF NOT EXISTS `llx_wallbox_rfid` (
            `rowid` INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
            `fk_user` INTEGER NOT NULL,
            `rfid_hash` VARCHAR(64) NOT NULL,
            `label` VARCHAR(255) DEFAULT '',
            `price_kwh` DECIMAL(10,4) DEFAULT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `entity` INTEGER DEFAULT 1,
            `date_creation` DATETIME NOT NULL,
            `tms` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_rfid_hash` (`rfid_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";

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
     * Modul-Deinstallation
     */
    public function uninstall()
    {
        global $db;
        $this->delete_permissions();
        return 1;
    }
}
?>

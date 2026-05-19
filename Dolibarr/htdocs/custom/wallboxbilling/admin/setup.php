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

    if ($action === 'save_rfid' && !empty($submitted_token) && $token_ok) {
        $user_id  = GETPOST('user_id', 'int');
        $rfid_hex = trim(GETPOST('rfid_hex', 'alpha'));
        $price    = price2num(GETPOST('price_kwh', 'alpha'));

        if ($user_id > 0 && !empty($rfid_hex)) {
            $rfid_hash = hash('sha256', strtoupper($rfid_hex));
            dol_syslog('wallboxbilling: save RFID user='.$user_id.' hash='.substr($rfid_hash, 0, 16).'...', LOG_INFO);

            $db->begin();
            $db->query("DELETE FROM ".MAIN_DB_PREFIX."wallbox_rfid WHERE fk_user = ".(int)$user_id." AND entity = ".(int)$conf->entity);
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."wallbox_rfid"
                ." (fk_user, rfid_hash, label, price_kwh, active, entity, date_creation)"
                ." VALUES (".(int)$user_id
                .", '".$db->escape($rfid_hash)."'"
                .", '".$db->escape($rfid_hex)."'"
                .", ".(float)$price
                .", 1"
                .", ".(int)$conf->entity
                .", '".$db->idate(dol_now())."'"
                .")";
            if ($db->query($sql)) {
                $db->commit();
                setEventMessages($langs->trans('RFIDHashSaved'), null, 'mesgs');
            } else {
                $db->rollback();
                setEventMessages($db->lasterror(), null, 'errors');
            }
        } elseif ($user_id > 0) {
            $db->query("DELETE FROM ".MAIN_DB_PREFIX."wallbox_rfid WHERE fk_user = ".(int)$user_id." AND entity = ".(int)$conf->entity);
            setEventMessages($langs->trans('RFIDHashSaved'), null, 'mesgs');
        }
    }
    // Test-Abrechnung und Diagnose-Test-Session entfernt in 1.1.0 —
    // Sessions werden jetzt vom HA-Addon direkt in die Spesenabrechnung
    // geschrieben, kein manueller Anstoß mehr nötig.

    if ($action === 'uninstall_module' && !empty($submitted_token) && $token_ok) {
        $confirm = GETPOST('confirm_uninstall', 'alpha');
        if ($confirm !== 'JA') {
            setEventMessages('Bitte "JA" eingeben um die Deinstallation zu bestätigen.', null, 'warnings');
        } else {
            $db->begin();
            $err = 0;

            // Tabellen löschen
            foreach (array('wallbox_sessions', 'wallbox_rfid') as $tbl) {
                if (!$db->query("DROP TABLE IF EXISTS ".MAIN_DB_PREFIX.$tbl)) {
                    $err++;
                    setEventMessages('DROP TABLE '.$tbl.': '.$db->lasterror(), null, 'errors');
                }
            }

            // Modul-Konstanten entfernen
            if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'WALLBOXBILLING_%' AND entity = ".(int)$conf->entity)) {
                $err++;
                setEventMessages('DELETE const: '.$db->lasterror(), null, 'errors');
            }

            // Modul-Aktivierung deaktivieren
            $db->query("DELETE FROM ".MAIN_DB_PREFIX."const WHERE name = 'MAIN_MODULE_WALLBOXBILLING' AND entity = ".(int)$conf->entity);

            // TK_ELE Spesentyp entfernen (nur wenn von diesem Modul angelegt)
            $db->query("DELETE FROM ".MAIN_DB_PREFIX."c_type_fees WHERE code = 'TK_ELE'");

            if ($err === 0) {
                $db->commit();
                setEventMessages('Modul-Daten erfolgreich gelöscht. Bitte PHP-Dateien manuell vom Server unter custom/wallboxbilling/ entfernen.', null, 'mesgs');
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

// --- RFID-Verwaltung ---
print load_fiche_titre($langs->trans('WallboxUserRFIDManagement'));
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('User').'</td>';
print '<td>'.$langs->trans('RFIDHex').'</td>';
print '<td>'.$langs->trans('RFIDHash').'</td>';
print '<td>'.$langs->trans('PricePerKWh').'</td>';
print '<td>'.$langs->trans('Action').'</td>';
print '</tr>';

$sql = "SELECT u.rowid, u.login, u.lastname, u.firstname,"
    ." r.rfid_hash, r.label as rfid_hex, r.price_kwh"
    ." FROM ".MAIN_DB_PREFIX."user u"
    ." LEFT JOIN ".MAIN_DB_PREFIX."wallbox_rfid r ON r.fk_user = u.rowid AND r.entity = ".(int)$conf->entity
    ." WHERE u.statut = 1 AND u.entity = ".(int)$conf->entity
    ." ORDER BY u.login";

$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $cur_price = !empty($obj->price_kwh) ? price2num($obj->price_kwh) : getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', '0.30');
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
        print '<input type="hidden" name="token" value="'.$token.'">';
        print '<input type="hidden" name="action" value="save_rfid">';
        print '<input type="hidden" name="user_id" value="'.(int)$obj->rowid.'">';
        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($obj->login).' ('.dol_escape_htmltag($obj->firstname.' '.$obj->lastname).')</td>';
        print '<td><input type="text" name="rfid_hex" class="flat" size="16"'
            .' value="'.dol_escape_htmltag((string)$obj->rfid_hex).'" placeholder="EFCD083E"></td>';
        print '<td><span class="opacitymedium small">';
        print !empty($obj->rfid_hash) ? substr($obj->rfid_hash, 0, 16).'…' : '—';
        print '</span></td>';
        print '<td><input type="text" name="price_kwh" class="flat" size="6"'
            .' value="'.dol_escape_htmltag((string)$cur_price).'" placeholder="0.30"> &euro;/kWh</td>';
        print '<td><input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'"></td>';
        print '</tr></form>';
    }
    $db->free($resql);
} else {
    print '<tr><td colspan="5" class="error">'.$db->lasterror().'</td></tr>';
}
print '</table>';

// Hinweis-Box: Erklärung der neuen Architektur
print '<br>';
print '<div class="info" style="padding:10px">';
print '<b>Ablauf ab 1.1.0:</b> Sobald das HA-Addon einen Ladevorgang überträgt, '
    .'wird er automatisch in die Spesenabrechnung des Mitarbeiters für den '
    .'jeweiligen Monat eingetragen. Existiert für den Monat noch keine Abrechnung, '
    .'wird ein neuer Entwurf angelegt. Voraussetzung: die RFID-Karte muss oben '
    .'einem Benutzer zugeordnet sein.';
print '</div>';

// --- Modul deinstallieren ---
print '<br>';
print load_fiche_titre('Modul deinstallieren');
print '<div class="warning" style="padding:10px;margin-bottom:12px">';
print '<b>Achtung:</b> Diese Aktion löscht unwiderruflich alle Wallbox-Daten aus der Datenbank:<br>';
print '&bull; Tabellen <code>llx_wallbox_sessions</code> und <code>llx_wallbox_rfid</code><br>';
print '&bull; Alle Modulkonstanten (WALLBOXBILLING_*)<br>';
print '&bull; Spesentyp TK_ELE<br>';
print 'Die PHP-Dateien unter <code>custom/wallboxbilling/</code> müssen danach manuell vom Server gelöscht werden.';
print '</div>';
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
print '<input type="submit" class="button buttonDelete" value="Modul-Daten löschen"';
print ' onclick="return confirm(\'LETZTE WARNUNG: Alle Wallbox-Daten werden gelöscht. Fortfahren?\');">';
print '</div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
?>

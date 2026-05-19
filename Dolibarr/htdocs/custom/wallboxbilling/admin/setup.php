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

// --- Auto-Heal: fk_user in wallbox_sessions aus wallbox_rfid nachtragen ---
// Bestehende Sessions mit fk_user=0 die ein gültiges RFID-Mapping haben,
// werden nachträglich verknüpft — sonst werden sie von der Abrechnung übersprungen.
$db->query("UPDATE ".MAIN_DB_PREFIX."wallbox_sessions s"
    ." INNER JOIN ".MAIN_DB_PREFIX."wallbox_rfid r ON r.rfid_hash = s.rfid_hash"
    ." SET s.fk_user = r.fk_user"
    ." WHERE (s.fk_user = 0 OR s.fk_user IS NULL) AND r.fk_user > 0");

$action = GETPOST('action', 'aZ09');

// --- CSRF-Token manuell prüfen (ohne checkToken() — Dolibarr-versionsunabhängig) ---
$submitted_token = GETPOST('token', 'alpha');
$token_ok = (empty($_SESSION['newtoken']) || $submitted_token === $_SESSION['newtoken']);

// --- Aktionen verarbeiten ---
$save_error      = '';
$billing_results = array();
$billing_info    = '';
$billing_error   = '';
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
    if ($action === 'run_billing' && !empty($submitted_token) && $token_ok) {
        require_once __DIR__.'/../class/billing.class.php';
        $billingMonth = (int) GETPOST('billing_month', 'int');
        $billingYear  = (int) GETPOST('billing_year', 'int');
        $cron   = new WallboxBillingCron($db);
        $result = $cron->runMonthlyBilling($user, $billingMonth, $billingYear);
        if ($result === -1) {
            $billing_error = $cron->error;
        } elseif (is_array($result) && empty($result)) {
            $billing_info = 'Keine abgeschlossenen Sessions im gewählten Zeitraum gefunden.';
        } elseif (is_array($result)) {
            $billing_results = $result;
        }
    }

    if ($action === 'insert_test_session' && !empty($submitted_token) && $token_ok) {
        $now   = $db->idate(dol_now());
        $start = $db->idate(dol_now() - 3600);
        $sqlT  = "INSERT INTO ".MAIN_DB_PREFIX."wallbox_sessions"
               . " (fk_user, rfid_hash, wallbox_id, start_time, end_time, kwh,"
               . "  price_per_kwh, total_cost, status, date_creation, transmitted_at)"
               . " VALUES (".(int)$user->id.", '0000000000000000000000000000000000000000000000000000000000000000',"
               . " 'test_wallbox', '".$start."', '".$now."', 5.0, 0.30, 1.50, 'completed', '".$now."', '".$now."')";
        if ($db->query($sqlT)) {
            setEventMessages('Test-Session eingefügt (ID: '.(int)$db->last_insert_id(MAIN_DB_PREFIX.'wallbox_sessions').'). Jetzt Ladevorgänge öffnen.', null, 'mesgs');
        } else {
            setEventMessages('Fehler: '.$db->lasterror(), null, 'errors');
        }
    }

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

// --- Test-Abrechnung ---
print '<br>';
print load_fiche_titre('Test-Abrechnung');

// Ergebnis-Anzeige
if (!empty($billing_error)) {
    print '<div class="error">'.dol_escape_htmltag($billing_error).'</div><br>';
}
if (!empty($billing_info)) {
    print '<div class="warning">'.dol_escape_htmltag($billing_info).'</div><br>';
}
if (!empty($billing_results)) {
    print '<div class="ok">';
    print '<b>Abrechnung erfolgreich:</b> '.count($billing_results).' Benutzer abgerechnet<br>';
    print '<ul style="margin:6px 0 0 18px">';
    foreach ($billing_results as $r) {
        print '<li>User #'.(int)$r['user_id'].': Report #'.(int)$r['report_id']
            .' &mdash; <b>'.(int)$r['added'].' neue Zeilen</b>'
            .' ('.(int)$r['sessions'].' Sessions gesamt)</li>';
    }
    print '</ul></div><br>';
}

// Standard-Vormonat berechnen
$defMonth = (int)date('n') - 1;
$defYear  = (int)date('Y');
if ($defMonth == 0) { $defMonth = 12; $defYear--; }

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$token.'">';
print '<input type="hidden" name="action" value="run_billing">';
print '<table class="noborder" style="width:auto">';
print '<tr>';

// Monat-Auswahl
print '<td style="padding-right:10px"><label>'.$langs->trans('Month').'</label>';
print '<select name="billing_month" class="flat">';
$monthNames = array(1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',
                    6=>'Juni',7=>'Juli',8=>'August',9=>'September',
                    10=>'Oktober',11=>'November',12=>'Dezember');
foreach ($monthNames as $m => $mname) {
    $sel = ($m == $defMonth) ? ' selected' : '';
    print '<option value="'.$m.'"'.$sel.'>'.$mname.'</option>';
}
print '</select></td>';

// Jahr-Auswahl
print '<td style="padding-right:10px"><label>'.$langs->trans('Year').'</label>';
print '<select name="billing_year" class="flat">';
for ($y = (int)date('Y'); $y >= (int)date('Y') - 3; $y--) {
    $sel = ($y == $defYear) ? ' selected' : '';
    print '<option value="'.$y.'"'.$sel.'>'.$y.'</option>';
}
print '</select></td>';

print '<td style="vertical-align:bottom">';
print '<input type="submit" class="button" value="Abrechnung jetzt ausführen">';
print '</td>';
print '</tr>';
print '</table>';
print '</form>';

// --- Diagnose: Test-Session ---
print '<br>';
print load_fiche_titre('Diagnose');
print '<p style="color:#666">Eine Test-Session direkt in die Datenbank einfügen — prüft ob die DB-Seite funktioniert.</p>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$token.'">';
print '<input type="hidden" name="action" value="insert_test_session">';
print '<input type="submit" class="button smallpaddingimp" value="Test-Session einfügen" onclick="return confirm(\'Test-Session (5 kWh) einfügen?\');">';
print ' <span class="opacitymedium small">→ danach &quot;Ladevorgänge&quot; prüfen</span>';
print '</form>';

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

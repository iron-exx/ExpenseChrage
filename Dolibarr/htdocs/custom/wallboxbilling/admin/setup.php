<?php
/**
 * Wallbox Billing — Admin/Setup-Seite
 *
 * Konfiguration: Standard-kWh-Preis, RFID-Karten-Zuordnung, Berechtigungen.
 */

// Dolibarr-Bootstrap (dynamisch, unabhängig von Installationspfad)
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
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res && file_exists('../../../../main.inc.php')) {
    $res = @include '../../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/wallboxbilling.lib.php';

// Nur Admins dürfen diese Seite sehen
if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array('admin', 'wallboxbilling@wallboxbilling'));

$action = GETPOST('action', 'aZ09');

// --- Aktionen verarbeiten ---
if ($action === 'update' && !empty($_POST['token']) && checkToken()) {
    $price = price2num(GETPOST('WALLBOXBILLING_DEFAULT_PRICE', 'alpha'));
    dolibarr_set_const($db, 'WALLBOXBILLING_DEFAULT_PRICE', $price, 'chaine', 0, '', $conf->entity);
    setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
}

if ($action === 'save_rfid' && !empty($_POST['token']) && checkToken()) {
    $user_id  = GETPOST('user_id', 'int');
    $rfid_hex = GETPOST('rfid_hex', 'alpha');
    $price    = price2num(GETPOST('price_kwh', 'alpha'));

    if ($user_id > 0 && !empty($rfid_hex)) {
        $rfid_hash = hash('sha256', strtoupper(trim($rfid_hex)));
        dol_syslog('wallboxbilling: save RFID hash for user '.$user_id.' hash='.substr($rfid_hash, 0, 16).'...', LOG_INFO);

        // RFID in llx_wallbox_rfid speichern (INSERT OR REPLACE)
        $db->begin();
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."wallbox_rfid WHERE fk_user = ".(int)$user_id;
        $db->query($sql);
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."wallbox_rfid (fk_user, rfid_hash, label, active, date_creation)"
            ." VALUES (".(int)$user_id.", '".$db->escape($rfid_hash)."', '".$db->escape($rfid_hex)."', 1, NOW())";
        if ($db->query($sql)) {
            $db->commit();
            setEventMessages($langs->trans('RFIDHashSaved'), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages($db->lasterror(), null, 'errors');
        }
    }
}

// --- Ausgabe ---
$page_title = $langs->trans('WallboxBillingSetup');
llxHeader('', $page_title);

$head = wallboxbillingPrepareHead();
print dol_get_fiche_head($head, 'setup', $page_title, -1, 'fa-bolt');

// --- Konfiguration ---
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
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
    ." r.rfid_hash, r.label as rfid_hex"
    ." FROM ".MAIN_DB_PREFIX."user u"
    ." LEFT JOIN ".MAIN_DB_PREFIX."wallbox_rfid r ON r.fk_user = u.rowid"
    ." WHERE u.statut = 1 AND u.entity = ".(int)$conf->entity
    ." ORDER BY u.login";

$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="save_rfid">';
        print '<input type="hidden" name="user_id" value="'.(int)$obj->rowid.'">';
        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($obj->login).' ('.dol_escape_htmltag($obj->firstname.' '.$obj->lastname).')</td>';
        print '<td><input type="text" name="rfid_hex" class="flat" size="16"'
            .' value="'.dol_escape_htmltag((string)$obj->rfid_hex).'" placeholder="EFCD083E"></td>';
        print '<td><span class="opacitymedium small">';
        print !empty($obj->rfid_hash) ? substr($obj->rfid_hash, 0, 16).'…' : '—';
        print '</span></td>';
        print '<td><input type="text" name="price_kwh" class="flat" size="6" placeholder="0.30"> &euro;/kWh</td>';
        print '<td><input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'"></td>';
        print '</tr></form>';
    }
    $db->free($resql);
}
print '</table>';

print dol_get_fiche_end();
llxFooter();
?>

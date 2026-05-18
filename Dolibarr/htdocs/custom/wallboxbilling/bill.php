<?php
/**
 * Wallbox Billing — Monatliche Abrechnung
 */

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

require_once __DIR__.'/lib/wallboxbilling.lib.php';

if (!$user->hasRight('wallboxbilling', 'billing', 'write') && !$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array('wallboxbilling@wallboxbilling'));

$page_title = $langs->trans('MonthlyBilling');
llxHeader('', $page_title);

$head = wallboxbillingPrepareHead();
print dol_get_fiche_head($head, 'billing', $page_title, -1, 'fa-bolt');

// Abrechnungen aus DB lesen
$sql = "SELECT b.rowid, b.billing_month, b.billing_year, b.total_kwh, b.total_amount,"
    ." b.status, u.login, u.firstname, u.lastname"
    ." FROM ".MAIN_DB_PREFIX."wallbox_billing b"
    ." LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = b.fk_user"
    ." WHERE b.entity = ".(int)$conf->entity
    ." ORDER BY b.billing_year DESC, b.billing_month DESC, u.login ASC";

$resql = $db->query($sql);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('User').'</td>';
print '<td>'.$langs->trans('Month').'</td>';
print '<td class="right">kWh</td>';
print '<td class="right">'.$langs->trans('Amount').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '</tr>';

if ($resql && $db->num_rows($resql) > 0) {
    while ($obj = $db->fetch_object($resql)) {
        $login  = dol_escape_htmltag($obj->login.' '.$obj->firstname.' '.$obj->lastname);
        $period = str_pad((int)$obj->billing_month, 2, '0', STR_PAD_LEFT).'/'.((int)$obj->billing_year);
        $kwh    = $obj->total_kwh !== null ? price((float)$obj->total_kwh) : '—';
        $amount = $obj->total_amount !== null ? price((float)$obj->total_amount).' €' : '—';
        $status = $obj->status == 1 ? '<span class="badge badge-status4 badge-status">Abgerechnet</span>'
                                    : '<span class="badge badge-status1 badge-status">Offen</span>';
        print '<tr class="oddeven">';
        print '<td>'.$login.'</td>';
        print '<td>'.$period.'</td>';
        print '<td class="right">'.$kwh.'</td>';
        print '<td class="right">'.$amount.'</td>';
        print '<td>'.$status.'</td>';
        print '</tr>';
    }
    $db->free($resql);
} else {
    print '<tr><td colspan="5" class="opacitymedium center">'.$langs->trans('BillingPreviewWillBeHere').'</td></tr>';
}

print '</table>';

print dol_get_fiche_end();
llxFooter();
?>

<?php
/**
 * Wallbox Billing — Ladevorgänge (Übersicht)
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

if (!$user->hasRight('wallboxbilling', 'session', 'read') && !$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array('wallboxbilling@wallboxbilling'));

$page_title = $langs->trans('WallboxSessions');
llxHeader('', $page_title);

$head = wallboxbillingPrepareHead();
print dol_get_fiche_head($head, 'sessions', $page_title, -1, 'fa-bolt');

// Ladevorgänge aus DB lesen
$sql = "SELECT s.rowid, s.rfid_hash, s.start_time, s.end_time, s.kwh,"
    ." s.wallbox_id, u.login, u.firstname, u.lastname"
    ." FROM ".MAIN_DB_PREFIX."wallbox_sessions s"
    ." LEFT JOIN ".MAIN_DB_PREFIX."wallbox_rfid r ON r.rfid_hash = s.rfid_hash AND r.entity = ".(int)$conf->entity
    ." LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = r.fk_user"
    ." WHERE 1=1";

if (!$user->admin && !$user->hasRight('wallboxbilling', 'session', 'write')) {
    $sql .= " AND r.fk_user = ".(int)$user->id;
}
$sql .= " ORDER BY s.start_time DESC";

$resql = $db->query($sql);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('User').'</td>';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans('Duration').'</td>';
print '<td class="right">kWh</td>';
print '<td>'.$langs->trans('WallboxId').'</td>';
print '</tr>';

if ($resql && $db->num_rows($resql) > 0) {
    while ($obj = $db->fetch_object($resql)) {
        $login = !empty($obj->login) ? dol_escape_htmltag($obj->login) : '<em class="opacitymedium">'.substr($obj->rfid_hash, 0, 8).'…</em>';
        $start = $obj->start_time ? dol_print_date($db->jdate($obj->start_time), 'dayhour') : '—';
        $end   = $obj->end_time   ? dol_print_date($db->jdate($obj->end_time),   'dayhour') : '—';
        $kwh   = $obj->kwh !== null ? price((float)$obj->kwh).' kWh' : '—';
        print '<tr class="oddeven">';
        print '<td>'.$login.'</td>';
        print '<td>'.$start.'</td>';
        print '<td>'.$end.'</td>';
        print '<td class="right">'.$kwh.'</td>';
        print '<td>'.dol_escape_htmltag((string)$obj->wallbox_id).'</td>';
        print '</tr>';
    }
    $db->free($resql);
} else {
    print '<tr><td colspan="5" class="opacitymedium center">'.$langs->trans('SessionsWillBeDisplayedHere').'</td></tr>';
}

print '</table>';

print dol_get_fiche_end();
llxFooter();
?>

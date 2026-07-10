<?php
/**
 *  index.php - Liste der Ladevorgänge (Skeleton)
 */

$res = false;
if (!$res && isset($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res) {
    $tmp = isset($_SERVER["SCRIPT_FILENAME"]) ? $_SERVER["SCRIPT_FILENAME"] : __FILE__;
    $i = strlen($tmp);
    while (!$res && $i > 0) {
        $i = strrpos($tmp, '/', $i - strlen($tmp) - 1);
        if ($i !== false && file_exists(substr($tmp, 0, ($i + 1))."main.inc.php")) {
            $res = @include substr($tmp, 0, ($i + 1))."main.inc.php";
        }
    }
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die('Unable to load Dolibarr main.inc.php');
}
$langs->load('wallboxbilling@wallboxbilling');

llxHeader('', $langs->trans('WallboxSessions'));

print load_fiche_titre($langs->trans('WallboxSessions'), '', 'wallbox.png@wallboxbilling');

// Vorbereitung für Phase 2: Sessions anzeigen
print '<p>'.$langs->trans('SessionsWillBeDisplayedHere').'</p>';

llxFooter();
?>

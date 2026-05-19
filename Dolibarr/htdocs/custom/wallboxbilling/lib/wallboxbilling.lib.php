<?php
/**
 * Helper functions for wallboxbilling module.
 *
 * @ingroup wallboxbilling
 */

/**
 * Prepare tab headings for wallboxbilling pages.
 *
 * Ab 1.1.0: nur noch der Konfigurations-Tab. Sessions werden direkt
 * in die Spesenabrechnung des jeweiligen Mitarbeiters geschrieben —
 * eigene Ladevorgänge- und Abrechnungs-Ansichten gibt es nicht mehr.
 *
 * @return array
 */
function wallboxbillingPrepareHead()
{
    global $langs, $user;

    $langs->load('wallboxbilling@wallboxbilling');

    $head = array();
    $h = 0;

    if (!empty($user->admin)) {
        $head[$h][0] = dol_buildpath('/custom/wallboxbilling/admin/setup.php', 1);
        $head[$h][1] = $langs->trans('WallboxBillingSetup');
        $head[$h][2] = 'setup';
        $h++;
    }

    return $head;
}

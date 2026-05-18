<?php
/**
 * Helper functions for wallboxbilling module.
 *
 * @ingroup wallboxbilling
 */

/**
 * Prepare tab headings for wallboxbilling pages.
 *
 * @return array
 */
function wallboxbillingPrepareHead()
{
    global $langs, $user;

    $langs->load('wallboxbilling@wallboxbilling');

    $head = array();
    $h = 0;

    $head[$h][0] = dol_buildpath('/custom/wallboxbilling/index.php', 1);
    $head[$h][1] = $langs->trans('WallboxSessions');
    $head[$h][2] = 'sessions';
    $h++;

    $head[$h][0] = dol_buildpath('/custom/wallboxbilling/bill.php', 1);
    $head[$h][1] = $langs->trans('MonthlyBilling');
    $head[$h][2] = 'billing';
    $h++;

    if (!empty($user->admin)) {
        $head[$h][0] = dol_buildpath('/custom/wallboxbilling/admin/setup.php', 1);
        $head[$h][1] = $langs->trans('WallboxBillingSetup');
        $head[$h][2] = 'setup';
        $h++;
    }

    return $head;
}

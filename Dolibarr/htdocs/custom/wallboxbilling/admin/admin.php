<?php
/**
 * admin.php — Wallbox Billing Administration v2
 *
 * 2 Tabs: Konfiguration | RFID-Verwaltung
 * Sessions werden direkt in Spesenabrechnung eingetragen (kein Status-Tab).
 */

// Dolibarr main.inc.php — robuste Pfad-Erkennung (analog BSP-Modul)
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
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
    $res = @include "../../../../main.inc.php";
}
if (!$res) {
    die('Unable to load Dolibarr main.inc.php');
}
require_once '../core/modules/modWallboxbilling.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

if (!$user->rights->wallboxbilling->admin) {
    accessforbidden();
}

$langs->load('wallboxbilling@wallboxbilling');

$action = GETPOST('action', 'alpha');

// WR-09: Tab gegen Whitelist validieren
$allowed_tabs = array('config', 'rfid');
$tab = GETPOST('tab', 'aZ09');
if (!in_array($tab, $allowed_tabs, true)) $tab = 'config';

// Diagnose-Wrapper: fängt auch \Error (z.B. "Call to undefined function") ab
// und zeigt die Meldung sichtbar an, statt einer leeren HTTP-500-Seite.
// TODO nach erfolgreicher Diagnose wieder entfernen.
try {

    // --- Action: Konfiguration speichern ---
    if ($action == 'update') {
        // CSRF: Dolibarr prüft den 'token' automatisch in main.inc.php (kein checkToken() — existiert nicht)
        dolibarr_set_const($db, 'WALLBOXBILLING_DEFAULT_PRICE',
            GETPOST('WALLBOXBILLING_DEFAULT_PRICE', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'WALLBOXBILLING_ADMIN_EMAIL',
            GETPOST('WALLBOXBILLING_ADMIN_EMAIL', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'WALLBOXBILLING_API_TOKEN',
            GETPOST('WALLBOXBILLING_API_TOKEN', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans('Saved'), null, 'mesgs');
    }

    // RFID-Verwaltung: jetzt pro TAG (rowid), nicht mehr pro Benutzer —
    // ein Benutzer kann mehrere Tags (Haupt- + Ersatzkarte) besitzen.
    if ($action == 'update_rfid') {

        // --- Action: einzelnen Tag löschen ---
        $delete_rowid = 0;
        foreach ($_POST as $key => $val) {
            if (preg_match('/^delrow_(\d+)$/', $key, $m)) {
                $delete_rowid = (int)$m[1];
                break;
            }
        }
        if ($delete_rowid > 0) {
            $sql = "DELETE FROM ".MAIN_DB_PREFIX."wallbox_rfid WHERE rowid=".(int)$delete_rowid;
            if ($db->query($sql)) {
                setEventMessages($langs->trans('WallboxRFIDDeleted'), null, 'mesgs');
            } else {
                setEventMessages($langs->trans('DatabaseError').': '.$db->lasterror(), null, 'errors');
                dol_syslog("Wallbox delrow error for rowid=$delete_rowid: ".$db->lasterror(), LOG_ERR);
            }
        }

        // --- Action: bestehenden Tag aktualisieren (Preis/Kostenstelle/Label) ---
        $save_rowid = 0;
        foreach ($_POST as $key => $val) {
            if (preg_match('/^saverow_(\d+)$/', $key, $m)) {
                $save_rowid = (int)$m[1];
                break;
            }
        }
        if ($save_rowid > 0) {
            $cost_center = GETPOST('cost_center_row_'.$save_rowid, 'alphanohtml');
            $label       = GETPOST('label_row_'.$save_rowid, 'alphanohtml');
            $price_raw   = trim(GETPOST('price_kwh_row_'.$save_rowid, 'none'));
            if (!preg_match('/^\d+(\.\d{1,4})?$/', $price_raw)) {
                setEventMessages($langs->trans('WallboxInvalidPrice'), null, 'errors');
            } else {
                $sql = "UPDATE ".MAIN_DB_PREFIX."wallbox_rfid"
                     ." SET price_kwh='".$db->escape($price_raw)."',"
                     ."     cost_center='".$db->escape($cost_center)."',"
                     ."     label='".$db->escape($label)."'"
                     ." WHERE rowid=".(int)$save_rowid;
                if ($db->query($sql)) {
                    setEventMessages($langs->trans('Saved'), null, 'mesgs');
                } else {
                    setEventMessages($langs->trans('DatabaseError').': '.$db->lasterror(), null, 'errors');
                    dol_syslog("Wallbox saverow error for rowid=$save_rowid: ".$db->lasterror(), LOG_ERR);
                }
            }
        }

        // --- Action: neuen Tag für einen Benutzer anlegen ---
        $add_uid = 0;
        foreach ($_POST as $key => $val) {
            if (preg_match('/^addtag_(\d+)$/', $key, $m)) {
                $add_uid = (int)$m[1];
                break;
            }
        }
        if ($add_uid > 0) {
            $rfid_hex    = trim(GETPOST('rfid_hex_new_'.$add_uid, 'aZ09'));
            $cost_center = GETPOST('cost_center_new_'.$add_uid, 'alphanohtml');
            $label       = GETPOST('label_new_'.$add_uid, 'alphanohtml');
            $price_raw   = trim(GETPOST('price_kwh_new_'.$add_uid, 'none'));

            if (empty($rfid_hex)) {
                setEventMessages($langs->trans('WallboxNoRFIDToUpdate'), null, 'warnings');
            } elseif (!preg_match('/^\d+(\.\d{1,4})?$/', $price_raw)) {
                setEventMessages($langs->trans('WallboxInvalidPrice'), null, 'errors');
            } else {
                // Hash berechnen — SEC-01: rfid_hash NICHT loggen/anzeigen
                $rfid_hash = hash('sha256', $rfid_hex);
                dol_syslog("Wallbox: adding new RFID tag for user_id=$add_uid", LOG_INFO);

                $res_owner = $db->query(
                    "SELECT fk_user FROM ".MAIN_DB_PREFIX."wallbox_rfid"
                   ." WHERE rfid_hash='".$db->escape($rfid_hash)."' LIMIT 1"
                );
                $owner_uid = ($res_owner && ($o = $db->fetch_object($res_owner))) ? (int) $o->fk_user : 0;

                if ($owner_uid > 0) {
                    setEventMessages($langs->trans('WallboxRFIDAlreadyAssigned'), null, 'errors');
                } else {
                    $now_sql = $db->idate(dol_now());
                    $sql = "INSERT INTO ".MAIN_DB_PREFIX."wallbox_rfid"
                         ." (fk_user, rfid_hash, price_kwh, cost_center, label, date_creation)"
                         ." VALUES (".(int)$add_uid.", '".$db->escape($rfid_hash)."',"
                         ." '".$db->escape($price_raw)."', '".$db->escape($cost_center)."',"
                         ." '".$db->escape($label)."', '".$now_sql."')";
                    if ($db->query($sql)) {
                        setEventMessages($langs->trans('RFIDHashSaved'), null, 'mesgs');
                    } else {
                        setEventMessages($langs->trans('DatabaseError').': '.$db->lasterror(), null, 'errors');
                        dol_syslog("Wallbox addtag INSERT error for user_id=$add_uid: ".$db->lasterror(), LOG_ERR);
                    }
                }
            }
        }
    }

} catch (\Throwable $e) {
    setEventMessages(
        'PHP-Fehler in admin.php: '.$e->getMessage().' (Zeile '.$e->getLine().' in '.basename($e->getFile()).')',
        null,
        'errors'
    );
    dol_syslog('WallboxBilling admin.php exception: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine(), LOG_ERR);
}

// --- HTML Output ---
llxHeader('', $langs->trans('WallboxBillingSetup'));

$form = new Form($db);

// Design-System Styles
print '<style>
.wb-card{background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:24px;margin-bottom:20px}
.wb-card-title{font-size:14px;font-weight:700;color:#0F172A;margin:0 0 18px;padding-bottom:12px;
  border-bottom:1px solid #F1F5F9;display:flex;align-items:center;gap:8px}
.wb-form-row{display:grid;grid-template-columns:200px 1fr;gap:12px 16px;align-items:center;margin-bottom:12px}
.wb-form-label{font-size:13px;font-weight:500;color:#374151}
.wb-input{padding:8px 12px;border:1px solid #D1D5DB;border-radius:6px;font-size:13px;
  color:#1E293B;background:#fff;box-sizing:border-box;width:100%;max-width:320px;
  transition:border-color 150ms,box-shadow 150ms}
.wb-input:focus{border-color:#14B8A6;outline:none;box-shadow:0 0 0 3px rgba(20,184,166,.15)}
.wb-input-sm{max-width:140px}
.wb-input-xs{max-width:100px}
.wb-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:6px;
  font-size:12.5px;font-weight:500;cursor:pointer;border:1px solid transparent;
  transition:filter 150ms,background 150ms,border-color 150ms;white-space:nowrap;text-decoration:none}
.wb-btn:focus{outline:2px solid #14B8A6;outline-offset:2px}
.wb-btn-save{background:linear-gradient(135deg,#22C55E,#14B8A6);color:#fff}
.wb-btn-save:hover{filter:brightness(.92)}
.wb-btn-delete{background:#fff;color:#DC2626;border-color:#FCA5A5}
.wb-btn-delete:hover{background:#FEF2F2;border-color:#DC2626}
.wb-btn svg{flex-shrink:0}
.wb-wrap{overflow-x:auto;margin-bottom:24px}
.wb-t{width:100%;border-collapse:collapse;font-size:13.5px;white-space:nowrap}
.wb-t thead th{background:#F8FAFC;color:#475569;font-weight:700;font-size:11.5px;
  text-transform:uppercase;letter-spacing:.05em;padding:10px 14px;
  border-bottom:2px solid #E2E8F0;text-align:left}
.wb-t tbody tr{border-bottom:1px solid #F1F5F9;transition:background 120ms}
.wb-t tbody tr:hover{background:#F8FAFC}
.wb-t tbody td{padding:10px 14px;color:#1E293B;vertical-align:middle}
.wb-badge-has{display:inline-block;padding:2px 9px;border-radius:20px;
  font-size:11.5px;font-weight:700;background:#ECFDF5;color:#059669}
.wb-badge-none{display:inline-block;padding:2px 9px;border-radius:20px;
  font-size:11.5px;font-weight:700;background:#F1F5F9;color:#94A3B8}
.wb-code{display:inline-block;padding:2px 7px;background:#ECFDF5;color:#0D9488;
  border-radius:4px;font-family:monospace;font-size:11.5px;font-weight:600}
.wb-brand{margin-bottom:18px}
.wb-brand-name{font-size:22px;font-weight:800;letter-spacing:-.02em}
.wb-brand-name .wb-brand-a{color:#0F172A}
.wb-brand-name .wb-brand-b{background:linear-gradient(135deg,#22C55E,#14B8A6);
  -webkit-background-clip:text;background-clip:text;color:transparent}
.wb-brand-tagline{font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;
  color:#94A3B8;margin-top:2px}
.wb-empty{text-align:center;padding:48px 20px;color:#94A3B8;font-size:13.5px}
.wb-add-row{background:#F8FAFC}
.wb-add-row td{vertical-align:middle}
.wb-btn-add{background:#fff;color:#0D9488;border-color:#99F6E4}
.wb-btn-add:hover{background:#F0FDFA;border-color:#0D9488}
.wb-user-cell{vertical-align:top!important;padding-top:14px!important}
.wb-section-title{font-size:13px;font-weight:700;color:#64748B;text-transform:uppercase;
  letter-spacing:.06em;padding:16px 0 8px;border-bottom:1px solid #F1F5F9;
  display:flex;align-items:center;gap:6px;margin-bottom:8px}
@media(max-width:768px){.wb-form-row{grid-template-columns:1fr}.wb-input{max-width:100%}}
@media(prefers-reduced-motion:reduce){.wb-t tbody tr,.wb-btn,.wb-input{transition:none}}
</style>';

print '<div class="wb-brand">';
print '<div class="wb-brand-name"><span class="wb-brand-a">Expense</span><span class="wb-brand-b">Charge</span></div>';
print '<div class="wb-brand-tagline">Ladevorgänge · Spesen · Abgerechnet</div>';
print '</div>';

// Tabs
$head = array();
$head[0][0] = $_SERVER['PHP_SELF'].'?tab=config';
$head[0][1] = $langs->trans('WallboxConfiguration');
$head[0][2] = 'config';
$head[1][0] = $_SERVER['PHP_SELF'].'?tab=rfid';
$head[1][1] = $langs->trans('WallboxUserRFIDManagement');
$head[1][2] = 'rfid';

print dol_get_fiche_head($head, $tab, $langs->trans('WallboxBillingSetup'), -1, 'title_setup');


// =====================================================================
// TAB: KONFIGURATION
// =====================================================================
if ($tab == 'config') {

    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?tab=config">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update">';

    print '<div class="wb-card">';
    print '<h3 class="wb-card-title">';
    print '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>';
    print $langs->trans('WallboxConfiguration');
    print '</h3>';

    // Standardpreis
    print '<div class="wb-form-row">';
    print '<label class="wb-form-label" for="wb_price">'.$langs->trans('DefaultPricePerKwh').'</label>';
    print '<div style="display:flex;align-items:center;gap:8px">';
    print '<input type="text" id="wb_price" name="WALLBOXBILLING_DEFAULT_PRICE" class="wb-input wb-input-sm"';
    print ' value="'.htmlspecialchars(getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE'), ENT_QUOTES, 'UTF-8').'"';
    print ' placeholder="0.30" pattern="\d+(\.\d{1,4})?" title="Dezimalzahl, z.B. 0.30">';
    print '<span style="font-size:13px;color:#64748B">€/kWh</span>';
    print '</div></div>';

    // Admin-E-Mail
    print '<div class="wb-form-row">';
    print '<label class="wb-form-label" for="wb_email">'.$langs->trans('WallboxAdminEmail').'</label>';
    print '<input type="email" id="wb_email" name="WALLBOXBILLING_ADMIN_EMAIL" class="wb-input"';
    print ' value="'.htmlspecialchars(getDolGlobalString('WALLBOXBILLING_ADMIN_EMAIL'), ENT_QUOTES, 'UTF-8').'"';
    print ' placeholder="admin@example.com">';
    print '</div>';

    // API-Token — muss identisch im HA-Addon (api_token) hinterlegt sein
    print '<div class="wb-form-row">';
    print '<label class="wb-form-label" for="wb_token">'.$langs->trans('WallboxApiToken').'</label>';
    print '<div>';
    print '<input type="text" id="wb_token" name="WALLBOXBILLING_API_TOKEN" class="wb-input"';
    print ' value="'.htmlspecialchars(getDolGlobalString('WALLBOXBILLING_API_TOKEN'), ENT_QUOTES, 'UTF-8').'"';
    print ' placeholder="langes-zufaelliges-token" autocomplete="off">';
    print '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px">'.$langs->trans('WallboxApiTokenHelp').'</div>';
    print '</div>';
    print '</div>';

    print '<div style="margin-top:20px;padding-top:16px;border-top:1px solid #F1F5F9">';
    print '<button type="submit" class="wb-btn wb-btn-save">';
    print '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    print ' '.$langs->trans('Save');
    print '</button>';
    print '</div>';

    print '</div>';

    // Info-Box: API-Endpoint
    print '<div class="wb-card" style="background:#F8FAFC">';
    print '<h3 class="wb-card-title">';
    print '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    print 'API-Endpoint';
    print '</h3>';
    print '<p style="font-size:13px;color:#475569;margin:0 0 8px">'.$langs->trans('WallboxAPIInfo').'</p>';
    print '<code style="font-size:12px;background:#ECFDF5;color:#0D9488;padding:4px 10px;border-radius:4px;display:inline-block">';
    print 'POST '.htmlspecialchars(DOL_MAIN_URL_ROOT, ENT_QUOTES, 'UTF-8').'/custom/wallboxbilling/receive.php';
    print '</code>';
    print '</div>';

    print '</form>';


// =====================================================================
// TAB: RFID-VERWALTUNG
// =====================================================================
} elseif ($tab == 'rfid') {

    // CR-05/SEC-01: rfid_hash NICHT in SELECT laden — nur rowid für UPDATE/DELETE-Referenz.
    // Ein Benutzer kann mehrere Tags haben → Liste statt Einzelwert je Benutzer.
    $existing = array();
    $res_rfid = $db->query(
        "SELECT rowid, fk_user, price_kwh, cost_center, label, date_creation"
       ." FROM ".MAIN_DB_PREFIX."wallbox_rfid ORDER BY fk_user, date_creation"
    );
    if ($res_rfid) {
        while ($obj_rfid = $db->fetch_object($res_rfid)) {
            $existing[(int)$obj_rfid->fk_user][] = array(
                'rowid'         => (int)$obj_rfid->rowid,
                'price_kwh'     => $obj_rfid->price_kwh,
                'cost_center'   => $obj_rfid->cost_center,
                'label'         => $obj_rfid->label,
                'date_creation' => $obj_rfid->date_creation,
            );
        }
        $db->free($res_rfid);
    }

    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?tab=rfid">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_rfid">';

    print '<div class="wb-wrap">';
    print '<table class="wb-t">';
    print '<thead><tr>';
    print '<th>'.$langs->trans('User').'</th>';
    print '<th>RFID</th>';
    print '<th>'.$langs->trans('WallboxLabel').'</th>';
    print '<th>'.$langs->trans('PricePerKWh').'</th>';
    print '<th>'.$langs->trans('CostCenter').'</th>';
    print '<th>'.$langs->trans('Action').'</th>';
    print '</tr></thead>';
    print '<tbody>';

    $res_users = $db->query(
        "SELECT rowid, login, lastname, firstname FROM ".MAIN_DB_PREFIX."user"
       ." WHERE statut=1 ORDER BY login"
    );
    if ($res_users) {
        $num = $db->num_rows($res_users);
        if ($num == 0) {
            print '<tr><td colspan="6"><div class="wb-empty">'.$langs->trans('WallboxNoActiveUsers').'</div></td></tr>';
        }
        while ($obj = $db->fetch_object($res_users)) {
            $uid  = (int)$obj->rowid;
            $tags = isset($existing[$uid]) ? $existing[$uid] : array();
            $rowspan = count($tags) + 1; // + 1 für die "Tag hinzufügen"-Zeile
            $user_cell_printed = false;

            $user_cell = '<td class="wb-user-cell" rowspan="'.$rowspan.'">'
                .'<span style="font-weight:500">'.htmlspecialchars(trim($obj->firstname.' '.$obj->lastname), ENT_QUOTES, 'UTF-8').'</span>'
                .'<br><span style="font-size:11.5px;color:#94A3B8">'.htmlspecialchars($obj->login, ENT_QUOTES, 'UTF-8').'</span>'
                .'</td>';

            // Eine Zeile pro bestehendem Tag
            foreach ($tags as $t) {
                $rowid      = $t['rowid'];
                $cur_price  = htmlspecialchars($t['price_kwh'], ENT_QUOTES, 'UTF-8');
                $cur_center = htmlspecialchars($t['cost_center'], ENT_QUOTES, 'UTF-8');
                $cur_label  = htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8');
                $cur_since  = !empty($t['date_creation'])
                    ? dol_print_date($db->jdate($t['date_creation']), 'day') : '';

                print '<tr>';
                if (!$user_cell_printed) {
                    print $user_cell;
                    $user_cell_printed = true;
                }

                // RFID-Status — CR-05/SEC-01: Kein rfid_hash im HTML, nur Status + Datum
                print '<td>';
                print '<span class="wb-badge-has">'.$langs->trans('WallboxRFIDAssigned').'</span>';
                if ($cur_since !== '') {
                    print '<br><span style="font-size:11px;color:#64748B;display:block;margin-top:3px">'
                        .$langs->trans('WallboxAssignedSince').' '.$cur_since.'</span>';
                }
                print '</td>';

                // Label
                print '<td><input type="text" name="label_row_'.$rowid.'" class="wb-input wb-input-sm"';
                print ' value="'.$cur_label.'" placeholder="'.htmlspecialchars($langs->trans('WallboxLabelPlaceholder'), ENT_QUOTES, 'UTF-8').'">';
                print '</td>';

                // Preis
                print '<td><div style="display:flex;align-items:center;gap:6px">';
                print '<input type="text" name="price_kwh_row_'.$rowid.'" class="wb-input wb-input-xs"';
                print ' value="'.$cur_price.'" placeholder="0.30"';
                print ' pattern="\d+(\.\d{1,4})?" title="Dezimalzahl, z.B. 0.30">';
                print '<span style="font-size:12px;color:#64748B">€/kWh</span>';
                print '</div></td>';

                // Kostenstelle
                print '<td><input type="text" name="cost_center_row_'.$rowid.'" class="wb-input wb-input-sm"';
                print ' value="'.$cur_center.'" placeholder="Projekt ABC">';
                print '</td>';

                // Speichern / Löschen
                print '<td><div style="display:flex;gap:6px">';
                print '<button type="submit" name="saverow_'.$rowid.'" class="wb-btn wb-btn-save">';
                print '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                print ' '.$langs->trans('Save');
                print '</button>';
                print '<button type="submit" name="delrow_'.$rowid.'" class="wb-btn wb-btn-delete"';
                print ' onclick="return confirm('.json_encode($langs->trans('WallboxConfirmDeleteRFID')).')">';
                print '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';
                print ' '.$langs->trans('Delete');
                print '</button>';
                print '</div></td>';

                print '</tr>';
            }

            // Abschließende Zeile: neuen Tag hinzufügen
            print '<tr class="wb-add-row">';
            if (!$user_cell_printed) {
                print $user_cell;
                $user_cell_printed = true;
            }

            print '<td>';
            if (empty($tags)) {
                print '<span class="wb-badge-none">'.$langs->trans('WallboxRFIDNotAssigned').'</span><br>';
            }
            print '<input type="text" name="rfid_hex_new_'.$uid.'" class="wb-input wb-input-sm"';
            print ' value="" placeholder="'.htmlspecialchars($langs->trans('WallboxRFIDPlaceholder'), ENT_QUOTES, 'UTF-8').'" style="margin-top:4px"';
            print ' autocomplete="off">';
            print '</td>';

            print '<td><input type="text" name="label_new_'.$uid.'" class="wb-input wb-input-sm"';
            print ' value="" placeholder="'.htmlspecialchars($langs->trans('WallboxLabelPlaceholder'), ENT_QUOTES, 'UTF-8').'">';
            print '</td>';

            print '<td><div style="display:flex;align-items:center;gap:6px">';
            print '<input type="text" name="price_kwh_new_'.$uid.'" class="wb-input wb-input-xs"';
            print ' value="" placeholder="0.30"';
            print ' pattern="\d+(\.\d{1,4})?" title="Dezimalzahl, z.B. 0.30">';
            print '<span style="font-size:12px;color:#64748B">€/kWh</span>';
            print '</div></td>';

            print '<td><input type="text" name="cost_center_new_'.$uid.'" class="wb-input wb-input-sm"';
            print ' value="" placeholder="Projekt ABC">';
            print '</td>';

            print '<td>';
            print '<button type="submit" name="addtag_'.$uid.'" class="wb-btn wb-btn-add">';
            print '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            print ' '.$langs->trans('WallboxAddTag');
            print '</button>';
            print '</td>';

            print '</tr>';
        }
        $db->free($res_users);
    }

    print '</tbody></table></div>';
    print '</form>';

    // Berechtigungen
    print '<div class="wb-section-title">';
    print '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    print $langs->trans('Permissions');
    print '</div>';

    print '<div class="wb-wrap">';
    print '<table class="wb-t">';
    print '<thead><tr>';
    print '<th>'.$langs->trans('Permission').'</th>';
    print '<th>'.$langs->trans('Description').'</th>';
    print '</tr></thead><tbody>';
    print '<tr><td><span class="wb-code">wallboxbilling.user</span></td><td>'.$langs->trans('ViewOwnSessions').'</td></tr>';
    print '<tr><td><span class="wb-code">wallboxbilling.admin</span></td><td>'.$langs->trans('ManageAllSessions').'</td></tr>';
    print '</tbody></table></div>';
}

print dol_fiche_end();
llxFooter();
?>

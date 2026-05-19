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

    // RFID-Tag zu User HINZUFÜGEN (ein User kann mehrere Tags haben)
    if ($action === 'add_rfid' && !empty($submitted_token) && $token_ok) {
        $user_id  = GETPOST('user_id', 'int');
        $rfid_hex = trim(GETPOST('rfid_hex', 'alpha'));

        if ($user_id > 0 && !empty($rfid_hex)) {
            $rfid_hash = hash('sha256', strtoupper($rfid_hex));

            // Prüfen ob dieser Hash bereits einem User gehört (UNIQUE-Constraint)
            $resExist = $db->query("SELECT fk_user FROM ".MAIN_DB_PREFIX."wallbox_rfid"
                ." WHERE rfid_hash = '".$db->escape($rfid_hash)."'");
            if ($resExist && ($oExist = $db->fetch_object($resExist))) {
                if ((int) $oExist->fk_user === (int) $user_id) {
                    setEventMessages('Dieser RFID-Tag ist bereits diesem Benutzer zugeordnet.', null, 'warnings');
                } else {
                    setEventMessages('Dieser RFID-Tag ist bereits einem anderen Benutzer (#'.(int)$oExist->fk_user.') zugeordnet.', null, 'errors');
                }
            } else {
                // Aktuellen User-Preis übernehmen (vom ersten existierenden Tag) oder Default
                $defPrice = getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', '0.30');
                $resPrice = $db->query("SELECT price_kwh FROM ".MAIN_DB_PREFIX."wallbox_rfid"
                    ." WHERE fk_user = ".(int)$user_id." AND entity = ".(int)$conf->entity
                    ." AND price_kwh IS NOT NULL LIMIT 1");
                if ($resPrice && ($oPrice = $db->fetch_object($resPrice))) {
                    $defPrice = $oPrice->price_kwh;
                }

                $sql = "INSERT INTO ".MAIN_DB_PREFIX."wallbox_rfid"
                    ." (fk_user, rfid_hash, label, price_kwh, active, entity, date_creation)"
                    ." VALUES (".(int)$user_id
                    .", '".$db->escape($rfid_hash)."'"
                    .", '".$db->escape(strtoupper($rfid_hex))."'"
                    .", ".(float)$defPrice
                    .", 1, ".(int)$conf->entity
                    .", '".$db->idate(dol_now())."')";
                if ($db->query($sql)) {
                    dol_syslog('wallboxbilling: RFID hinzugefügt user='.$user_id.' hash='.substr($rfid_hash, 0, 16).'...', LOG_INFO);
                    setEventMessages('RFID-Tag '.dol_escape_htmltag(strtoupper($rfid_hex)).' hinzugefügt.', null, 'mesgs');
                } else {
                    setEventMessages($db->lasterror(), null, 'errors');
                }
            }
        }
    }

    // Einzelnen RFID-Tag LÖSCHEN
    if ($action === 'delete_rfid' && !empty($submitted_token) && $token_ok) {
        $rfid_id = GETPOST('rfid_id', 'int');
        if ($rfid_id > 0) {
            if ($db->query("DELETE FROM ".MAIN_DB_PREFIX."wallbox_rfid WHERE rowid = ".(int)$rfid_id." AND entity = ".(int)$conf->entity)) {
                setEventMessages('RFID-Tag gelöscht.', null, 'mesgs');
            } else {
                setEventMessages($db->lasterror(), null, 'errors');
            }
        }
    }

    // Preis-pro-kWh für ALLE Tags eines Users aktualisieren
    if ($action === 'update_price' && !empty($submitted_token) && $token_ok) {
        $user_id = GETPOST('user_id', 'int');
        $price   = price2num(GETPOST('price_kwh', 'alpha'));
        if ($user_id > 0) {
            if ($db->query("UPDATE ".MAIN_DB_PREFIX."wallbox_rfid"
                ." SET price_kwh = ".(float)$price
                ." WHERE fk_user = ".(int)$user_id." AND entity = ".(int)$conf->entity)) {
                setEventMessages('Preis aktualisiert.', null, 'mesgs');
            } else {
                setEventMessages($db->lasterror(), null, 'errors');
            }
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

// --- RFID-Verwaltung (Multi-Tag pro Benutzer) ---
print load_fiche_titre($langs->trans('WallboxUserRFIDManagement'));

// Benutzer laden
$sqlUsers = "SELECT rowid, login, lastname, firstname"
          ." FROM ".MAIN_DB_PREFIX."user"
          ." WHERE statut = 1 AND entity = ".(int)$conf->entity
          ." ORDER BY login";
$resUsers = $db->query($sqlUsers);

// RFID-Tags pro User vorladen — eine Query statt N
$tagsByUser = array();
$resTags = $db->query("SELECT rowid, fk_user, rfid_hash, label, price_kwh"
    ." FROM ".MAIN_DB_PREFIX."wallbox_rfid"
    ." WHERE entity = ".(int)$conf->entity
    ." ORDER BY fk_user, rowid");
if ($resTags) {
    while ($t = $db->fetch_object($resTags)) {
        $tagsByUser[(int)$t->fk_user][] = $t;
    }
    $db->free($resTags);
}

$defaultPrice = getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', '0.30');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td style="width:20%">'.$langs->trans('User').'</td>';
print '<td>RFID-Karten</td>';
print '<td style="width:18%">'.$langs->trans('PricePerKWh').'</td>';
print '</tr>';

if ($resUsers) {
    while ($u = $db->fetch_object($resUsers)) {
        $uid       = (int) $u->rowid;
        $userTags  = isset($tagsByUser[$uid]) ? $tagsByUser[$uid] : array();
        $userPrice = !empty($userTags) && !empty($userTags[0]->price_kwh)
            ? price2num($userTags[0]->price_kwh) : $defaultPrice;

        print '<tr class="oddeven">';
        // Spalte 1: Benutzer
        print '<td style="vertical-align:top;padding-top:8px"><b>'.dol_escape_htmltag($u->login).'</b><br>';
        print '<span class="opacitymedium small">'.dol_escape_htmltag(trim($u->firstname.' '.$u->lastname)).'</span></td>';

        // Spalte 2: RFID-Tags + Add-Form
        print '<td style="vertical-align:top">';
        if (empty($userTags)) {
            print '<span class="opacitymedium small">— keine Karten zugeordnet —</span><br>';
        } else {
            foreach ($userTags as $t) {
                print '<div style="margin-bottom:4px;padding:4px 8px;background:#f8f8f8;border-radius:3px;display:inline-block;margin-right:6px">';
                print '<code>'.dol_escape_htmltag((string)$t->label).'</code>';
                print ' <span class="opacitymedium small" title="'.dol_escape_htmltag($t->rfid_hash).'">('.substr($t->rfid_hash, 0, 12).'…)</span>';
                print ' <form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;margin-left:6px">';
                print '<input type="hidden" name="token" value="'.$token.'">';
                print '<input type="hidden" name="action" value="delete_rfid">';
                print '<input type="hidden" name="rfid_id" value="'.(int)$t->rowid.'">';
                print '<button type="submit" class="button smallpaddingimp" style="padding:1px 6px;color:#c00"'
                    .' onclick="return confirm(\'Karte '.dol_escape_js($t->label).' wirklich löschen?\');" title="Löschen">×</button>';
                print '</form>';
                print '</div>';
            }
            print '<br>';
        }
        // Add-Form für neuen Tag
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline-block;margin-top:4px">';
        print '<input type="hidden" name="token" value="'.$token.'">';
        print '<input type="hidden" name="action" value="add_rfid">';
        print '<input type="hidden" name="user_id" value="'.$uid.'">';
        print '<input type="text" name="rfid_hex" class="flat" size="14" placeholder="EFCD083E" style="text-transform:uppercase">';
        print ' <input type="submit" class="button smallpaddingimp" value="+ Karte hinzufügen">';
        print '</form>';
        print '</td>';

        // Spalte 3: Preis
        print '<td style="vertical-align:top">';
        if (!empty($userTags)) {
            print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
            print '<input type="hidden" name="token" value="'.$token.'">';
            print '<input type="hidden" name="action" value="update_price">';
            print '<input type="hidden" name="user_id" value="'.$uid.'">';
            print '<input type="text" name="price_kwh" class="flat" size="6"'
                .' value="'.dol_escape_htmltag((string)$userPrice).'"> &euro;/kWh';
            print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
            print '</form>';
        } else {
            print '<span class="opacitymedium small">'.$defaultPrice.' &euro;/kWh (Default)</span>';
        }
        print '</td>';
        print '</tr>';
    }
    $db->free($resUsers);
}
print '</table>';

// --- Modul deinstallieren ---
print '<br>';
print load_fiche_titre('Modul deinstallieren');
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

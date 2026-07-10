<?php
/**
 * receive.php — Wallbox Billing Empfangsendpunkt (Token-Auth, kein Login)
 *
 * POST /custom/wallboxbilling/receive.php
 * Header: X-Wallbox-Token: <WALLBOXBILLING_API_TOKEN>
 * Body (JSON): {rfid_hash, wallbox_id, start_time, end_time, kwh}
 *
 * Schreibt die Session direkt als Zeile in die Spesenabrechnung
 * (llx_expensereport_det) des per RFID-Hash zugeordneten Benutzers.
 * SEC-01: rfid_hash NIEMALS in Response/Logs.
 */

// Kein Dolibarr-Login nötig — Auth erfolgt über eigenes Shared-Token
if (!defined('NOLOGIN')) {
    define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', 1);
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', 1);
}

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
    http_response_code(500);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function wb_json_exit($code, $body)
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

// --- Auth: Shared-Token aus Header oder Query prüfen ---------------------
// HA-Addon (api_client.py) sendet den Header "DOLAPIKEY" — daher zuerst prüfen
$expected_token = getDolGlobalString('WALLBOXBILLING_API_TOKEN');
$given_token = '';
foreach (array('HTTP_DOLAPIKEY', 'HTTP_X_WALLBOX_TOKEN', 'HTTP_X_WALLBOXBILLING_TOKEN') as $hkey) {
    if (!empty($_SERVER[$hkey])) {
        $given_token = $_SERVER[$hkey];
        break;
    }
}
if ($given_token === '' && !empty($_GET['token'])) {
    $given_token = $_GET['token'];
}

if (empty($expected_token) || !hash_equals($expected_token, (string) $given_token)) {
    dol_syslog('WallboxBilling receive.php: unauthorized attempt', LOG_WARNING);
    wb_json_exit(401, array('success' => false, 'error' => 'Unauthorized'));
}

// --- Payload lesen ----------------------------------------------------------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    wb_json_exit(400, array('success' => false, 'error' => 'Invalid JSON body'));
}

foreach (array('wallbox_id', 'start_time', 'end_time', 'kwh') as $field) {
    if (!isset($data[$field]) || $data[$field] === '') {
        wb_json_exit(400, array('success' => false, 'error' => "Missing field: $field"));
    }
}

// Entweder rfid_hash (physischer Tap) ODER login (manuelle Admin-Erfassung) nötig
$has_hash  = !empty($data['rfid_hash']);
$has_login = !empty($data['login']);
if (!$has_hash && !$has_login) {
    wb_json_exit(400, array('success' => false, 'error' => 'Missing field: rfid_hash or login'));
}
if ($has_hash && $has_login) {
    wb_json_exit(400, array('success' => false, 'error' => 'Provide either rfid_hash or login, not both'));
}

$wallbox_id = (string) $data['wallbox_id'];
$kwh        = (float) $data['kwh'];

try {
    $start_ts = (new DateTime((string) $data['start_time']))->getTimestamp();
    $end_ts   = (new DateTime((string) $data['end_time']))->getTimestamp();
} catch (Exception $e) {
    wb_json_exit(400, array('success' => false, 'error' => 'Invalid start_time or end_time (ISO 8601 required)'));
}

if (!preg_match('/^[\w\-\.]{1,50}$/', $wallbox_id)) {
    wb_json_exit(400, array('success' => false, 'error' => 'wallbox_id invalid (alphanumeric, hyphen, dot; max 50 chars)'));
}
if ($end_ts <= $start_ts) {
    wb_json_exit(400, array('success' => false, 'error' => 'end_time must be after start_time'));
}
if ($kwh <= 0 || !is_finite($kwh)) {
    wb_json_exit(400, array('success' => false, 'error' => 'kwh must be greater than 0'));
}

if ($has_hash) {
    // Physischer Tap: Benutzer + Preis aus RFID-Zuordnung (SEC-01: Hash nicht loggen/ausgeben)
    $rfid_hash = (string) $data['rfid_hash'];
    if (!preg_match('/^[a-f0-9]{64}$/i', $rfid_hash)) {
        wb_json_exit(400, array('success' => false, 'error' => 'Invalid rfid_hash (64-char hex SHA-256 required)'));
    }

    $res_rfid = $db->query(
        "SELECT fk_user, price_kwh FROM ".MAIN_DB_PREFIX."wallbox_rfid"
       ." WHERE rfid_hash='".$db->escape($rfid_hash)."' LIMIT 1"
    );
    if (!$res_rfid || $db->num_rows($res_rfid) == 0) {
        wb_json_exit(404, array('success' => false, 'error' => 'RFID not registered in Dolibarr'));
    }
    $row     = $db->fetch_object($res_rfid);
    $fk_user = (int) $row->fk_user;

    $price_kwh = ($row->price_kwh !== null)
        ? (float) $row->price_kwh
        : (float) getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', 0.30);
} else {
    // Manuelle Admin-Erfassung: Benutzer direkt per Login identifizieren
    // (vertrauenswürdig, da bereits per Shared-Token authentifiziert)
    $login = (string) $data['login'];
    if (!preg_match('/^[\w\.\-@]{1,255}$/', $login)) {
        wb_json_exit(400, array('success' => false, 'error' => 'Invalid login'));
    }

    $res_user = $db->query(
        "SELECT rowid FROM ".MAIN_DB_PREFIX."user"
       ." WHERE login='".$db->escape($login)."' AND statut=1 LIMIT 1"
    );
    if (!$res_user || $db->num_rows($res_user) == 0) {
        wb_json_exit(404, array('success' => false, 'error' => 'User not found or inactive'));
    }
    $fk_user = (int) $db->fetch_object($res_user)->rowid;

    // Preis aus dem (ersten) zugeordneten Tag des Users übernehmen, sonst Default
    $res_price = $db->query(
        "SELECT price_kwh FROM ".MAIN_DB_PREFIX."wallbox_rfid"
       ." WHERE fk_user=".(int) $fk_user." ORDER BY date_creation LIMIT 1"
    );
    $price_row = ($res_price && $db->num_rows($res_price) > 0) ? $db->fetch_object($res_price) : null;
    $price_kwh = ($price_row && $price_row->price_kwh !== null)
        ? (float) $price_row->price_kwh
        : (float) getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', 0.30);
}

$total_ht = round($kwh * $price_kwh, 2);

// Idempotenz: identische Session (User + Start + Ende) nicht doppelt anlegen
$date_sql_check = $db->idate($end_ts);
$res_dup = $db->query(
    "SELECT ed.rowid FROM ".MAIN_DB_PREFIX."expensereport_det ed"
   ." INNER JOIN ".MAIN_DB_PREFIX."expensereport er ON er.rowid = ed.fk_expensereport"
   ." WHERE er.fk_user_author=".(int)$fk_user
   ."   AND ed.date='".$date_sql_check."'"
   ."   AND ed.comments LIKE '".$db->escape('Wallbox '.$wallbox_id.':')."%'"
   ." LIMIT 1"
);
if ($res_dup && $db->num_rows($res_dup) > 0) {
    wb_json_exit(200, array('success' => false, 'message' => 'Session already exists'));
}

$db->begin();

$report_id = wb_find_or_create_report($db, $fk_user, (int) date('Y', $end_ts), (int) date('n', $end_ts));
if (!$report_id) {
    $db->rollback();
    dol_syslog('WallboxBilling receive.php: could not find/create expense report', LOG_ERR);
    wb_json_exit(500, array('success' => false, 'error' => 'Could not find or create expense report'));
}

$fk_type = wb_get_expense_type_id($db);

$res_rank = $db->query(
    "SELECT COALESCE(MAX(rang), 0) + 1 AS n FROM ".MAIN_DB_PREFIX."expensereport_det"
   ." WHERE fk_expensereport=".(int) $report_id
);
$rang = ($res_rank && ($r = $db->fetch_object($res_rank))) ? (int) $r->n : 1;

$comment  = $db->escape('Wallbox '.$wallbox_id.': '.number_format($kwh, 2, '.', '').' kWh');
$date_sql = $db->idate($end_ts);

$resql = $db->query(
    "INSERT INTO ".MAIN_DB_PREFIX."expensereport_det"
   ." (fk_expensereport, date, fk_c_type_fees, rang, comments,"
   ."  qty, value_unit, total_ht, tva_tx, total_tva, total_ttc)"
   ." VALUES ("
   .(int) $report_id.", '".$date_sql."', ".(int) $fk_type.", ".(int) $rang.", '".$comment."',"
   .(float) $kwh.", ".(float) $price_kwh.", ".(float) $total_ht.","
   ." 0, 0, ".(float) $total_ht.")"
);
if (!$resql) {
    $db->rollback();
    $db_err = $db->lasterror();
    dol_syslog('WallboxBilling receive.php: expensereport_det INSERT failed: '.$db_err, LOG_ERR);
    // TODO: DB-Fehlertext nach erfolgreicher Diagnose wieder entfernen (nur intern, addon<->dolibarr)
    wb_json_exit(500, array('success' => false, 'error' => 'Internal server error: '.$db_err));
}

$line_id = (int) $db->last_insert_id(MAIN_DB_PREFIX.'expensereport_det');
if ($line_id <= 0) {
    $db->rollback();
    dol_syslog('WallboxBilling receive.php: last_insert_id returned 0 after INSERT', LOG_ERR);
    wb_json_exit(500, array('success' => false, 'error' => 'Internal error: could not retrieve inserted line ID'));
}

$now = $db->idate(dol_now());
$res_upd = $db->query(
    "UPDATE ".MAIN_DB_PREFIX."expensereport"
   ." SET total_ht  = total_ht  + ".(float) $total_ht.","
   ."     total_ttc = total_ttc + ".(float) $total_ht.","
   ."     date_modif = '".$now."'"
   ." WHERE rowid=".(int) $report_id
);
if (!$res_upd) {
    $db->rollback();
    dol_syslog('WallboxBilling receive.php: expensereport SUM UPDATE failed: '.$db->lasterror(), LOG_ERR);
    wb_json_exit(500, array('success' => false, 'error' => 'Internal error updating expense report totals'));
}

$db->commit();

dol_syslog(
    'WallboxBilling receive.php: session recorded'
   .' expensereport_id='.$report_id.' line_id='.$line_id
   .' user='.$fk_user.' kwh='.$kwh.' total='.$total_ht,
    LOG_INFO
);

wb_json_exit(200, array(
    'success'          => true,
    'expensereport_id' => $report_id,
    'line_id'          => $line_id,
));

// -----------------------------------------------------------------------------

function wb_find_or_create_report($db, $fk_user, $year, $month)
{
    global $conf;

    $res = $db->query(
        "SELECT rowid FROM ".MAIN_DB_PREFIX."expensereport"
       ." WHERE fk_user_author=".(int) $fk_user
       ."   AND YEAR(date_debut)=".(int) $year
       ."   AND MONTH(date_debut)=".(int) $month
       ."   AND fk_statut=0"
       ." ORDER BY rowid DESC LIMIT 1"
    );
    if ($res && $db->num_rows($res) > 0) {
        return (int) $db->fetch_object($res)->rowid;
    }

    $date_debut_ts = mktime(0, 0, 0, $month, 1, $year);
    $last_day      = (int) date('t', $date_debut_ts);
    $date_fin_ts   = mktime(23, 59, 59, $month, $last_day, $year);
    $ref           = $db->escape('WB-'.$year.str_pad($month, 2, '0', STR_PAD_LEFT).'-'.$fk_user);
    $note          = $db->escape('Wallbox-Abrechnung '.date('Y-m', $date_debut_ts));
    $now           = $db->idate(dol_now());

    $ok = $db->query(
        "INSERT INTO ".MAIN_DB_PREFIX."expensereport"
       ." (ref, entity, fk_user_author, date_create, date_debut, date_fin,"
       ."  fk_statut, total_ht, total_ttc, total_tva, paid, note_private)"
       ." VALUES ('".$ref."', ".(int) $conf->entity.", ".(int) $fk_user.","
       ." '".$now."', '".$db->idate($date_debut_ts)."', '".$db->idate($date_fin_ts)."',"
       ." 0, 0, 0, 0, 0, '".$note."')"
    );
    if (!$ok) {
        $res2 = $db->query(
            "SELECT rowid FROM ".MAIN_DB_PREFIX."expensereport"
           ." WHERE fk_user_author=".(int) $fk_user
           ."   AND YEAR(date_debut)=".(int) $year
           ."   AND MONTH(date_debut)=".(int) $month
           ."   AND fk_statut=0"
           ." ORDER BY rowid DESC LIMIT 1"
        );
        if ($res2 && $db->num_rows($res2) > 0) {
            return (int) $db->fetch_object($res2)->rowid;
        }
        dol_syslog(
            'WallboxBilling receive.php: CREATE expensereport failed for user='.$fk_user
           .' year='.$year.' month='.$month.': '.$db->lasterror(),
            LOG_ERR
        );
        return 0;
    }
    return (int) $db->last_insert_id(MAIN_DB_PREFIX.'expensereport');
}

function wb_get_expense_type_id($db)
{
    $res = $db->query(
        "SELECT rowid FROM ".MAIN_DB_PREFIX."c_type_fees"
       ." WHERE code='TF_OTHER' AND active=1 ORDER BY rowid LIMIT 1"
    );
    if ($res && ($obj = $db->fetch_object($res))) {
        return (int) $obj->rowid;
    }
    $res = $db->query(
        "SELECT rowid FROM ".MAIN_DB_PREFIX."c_type_fees WHERE active=1 ORDER BY rowid LIMIT 1"
    );
    if ($res && ($obj = $db->fetch_object($res))) {
        return (int) $obj->rowid;
    }
    return 1;
}

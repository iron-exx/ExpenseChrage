<?php
/**
 * Wallbox Billing — Session Upload Endpoint (v1.1.0: direkt in Spesenabrechnung)
 *
 * URL:    POST /custom/wallboxbilling/receive.php
 * Header: DOLAPIKEY: <dolibarr_api_key>
 * Body:   JSON { rfid_hash, wallbox_id, start_time, end_time, kwh }
 *
 * Architektur ab 1.1.0:
 *   1. RFID-Hash → fk_user via wallbox_rfid auflösen
 *   2. Spesenabrechnung (Draft) für Session-Monat des Users finden
 *      → falls keine: neue Draft-Spesenabrechnung anlegen
 *   3. Spesentyp TK_ELE sicherstellen
 *   4. Session als Zeile in expensereport_det einfügen (Duplikat-Check via Marker)
 *   5. Spesenabrechnungs-Summen aktualisieren
 *
 * KEIN INSERT in llx_wallbox_sessions mehr.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, DOLAPIKEY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// GET: Diagnose-Endpoint — ohne Auth, zeigt ob PHP erreichbar ist
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status'   => 'ok',
        'version'  => '1.1.0',
        'mode'     => 'direct-to-expensereport',
        'endpoint' => 'wallboxbilling/receive.php',
        'message'  => 'POST with DOLAPIKEY header required for session upload',
        'php'      => PHP_VERSION,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Dolibarr laden — define()-Konstanten verhindern Login-Redirect für API-Requests
if (!defined('NOLOGIN'))         define('NOLOGIN', '1');
if (!defined('NOCSRFCHECK'))     define('NOCSRFCHECK', '1');
if (!defined('NOTOKENRENEWAL'))  define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU'))   define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML'))   define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX'))   define('NOREQUIREAJAX', '1');
if (!defined('NOIPCHECK'))       define('NOIPCHECK', '1');
$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp  = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, $i + 1).'/main.inc.php')) {
    $res = @include substr($tmp, 0, $i + 1).'/main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../main.inc.php'))  { $res = @include __DIR__.'/../../main.inc.php'; }
if (!$res && file_exists(__DIR__.'/../../../main.inc.php')) { $res = @include __DIR__.'/../../../main.inc.php'; }
if (!$res) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot load Dolibarr framework']);
    exit;
}

// ===== DOLAPIKEY Auth =====
$apiKey = '';
if (!empty($_SERVER['HTTP_DOLAPIKEY'])) {
    $apiKey = $_SERVER['HTTP_DOLAPIKEY'];
} elseif (function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'dolapikey') { $apiKey = $v; break; }
    }
}

dol_syslog('wallboxbilling receive: POST from '.$_SERVER['REMOTE_ADDR'], LOG_INFO);

if (empty($apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing DOLAPIKEY header']);
    exit;
}

$apiKeyHashed = hash('sha256', $apiKey);
$sqlAuth = "SELECT rowid FROM ".MAIN_DB_PREFIX."user"
         ." WHERE (api_key = '".$db->escape($apiKey)."' OR api_key = '".$db->escape($apiKeyHashed)."')"
         ." AND statut = 1 AND entity IN (0, ".(int)$conf->entity.")";
$resAuth = $db->query($sqlAuth);
if (!$resAuth || $db->num_rows($resAuth) == 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or inactive API key']);
    exit;
}
$objAuth = $db->fetch_object($resAuth);
$apiUserId = (int) $objAuth->rowid;

// ===== JSON Body =====
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

foreach (['rfid_hash', 'wallbox_id', 'start_time', 'end_time', 'kwh'] as $f) {
    if (!isset($data[$f]) || $data[$f] === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: '.$f]);
        exit;
    }
}

$rfidHash  = $data['rfid_hash'];
$wallboxId = $data['wallbox_id'];
$kwh       = (float) $data['kwh'];
$startStr  = preg_replace('/\.\d+/', '', $data['start_time']);
$endStr    = preg_replace('/\.\d+/', '', $data['end_time']);
$startTs   = strtotime($startStr);
$endTs     = strtotime($endStr);

if (!preg_match('/^[a-f0-9]{64}$/i', $rfidHash)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid rfid_hash (must be SHA-256 hex, 64 chars)']);
    exit;
}
if ($kwh < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'kwh must be >= 0']);
    exit;
}
if (!$startTs || !$endTs || $endTs <= $startTs) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or illogical timestamps']);
    exit;
}

// ===== RFID → fk_user (mit Entity-Fallback) =====
$fkUser = 0;
$userPrice = null;
$resUser = $db->query("SELECT fk_user, price_kwh FROM ".MAIN_DB_PREFIX."wallbox_rfid"
    ." WHERE rfid_hash = '".$db->escape($rfidHash)."' AND entity = ".(int)$conf->entity);
if ($resUser && ($obj = $db->fetch_object($resUser))) {
    $fkUser    = (int) $obj->fk_user;
    $userPrice = $obj->price_kwh;
}
if ($fkUser <= 0) {
    $resUser2 = $db->query("SELECT fk_user, price_kwh FROM ".MAIN_DB_PREFIX."wallbox_rfid"
        ." WHERE rfid_hash = '".$db->escape($rfidHash)."' LIMIT 1");
    if ($resUser2 && ($obj = $db->fetch_object($resUser2))) {
        $fkUser    = (int) $obj->fk_user;
        $userPrice = $obj->price_kwh;
    }
}
if ($fkUser <= 0) {
    // RFID unbekannt — Admin muss erst mappen. HA-Addon retried beim nächsten Lauf.
    dol_syslog('wallboxbilling receive: RFID unbekannt hash='.substr($rfidHash, 0, 16).'... — Mapping fehlt', LOG_WARNING);
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'RFID nicht zugeordnet (Hash-Prefix '.substr($rfidHash, 0, 16).'...). Admin muss RFID einem Benutzer zuordnen unter Wallbox-Abrechnung Konfiguration.',
        'code'    => 'RFID_NOT_MAPPED',
    ]);
    exit;
}

// Effektiven Preis bestimmen: user-spezifisch oder Default
$defaultPrice = (float) getDolGlobalString('WALLBOXBILLING_DEFAULT_PRICE', '0.30');
$effPrice     = !empty($userPrice) ? (float) $userPrice : $defaultPrice;

// ===== Spesentyp TK_ELE sicherstellen =====
// llx_c_type_fees verwendet `id` als PK (nicht rowid)
$typeId = 0;
$resType = $db->query("SELECT id, active FROM ".MAIN_DB_PREFIX."c_type_fees WHERE code = 'TK_ELE'");
if ($resType && ($obj = $db->fetch_object($resType))) {
    $typeId = (int) $obj->id;
    if ((int) $obj->active !== 1) {
        $db->query("UPDATE ".MAIN_DB_PREFIX."c_type_fees SET active = 1 WHERE id = ".$typeId);
    }
} else {
    $db->query("INSERT INTO ".MAIN_DB_PREFIX."c_type_fees (code, label, active)"
             ." VALUES ('TK_ELE', 'Stromkosten (Wallbox)', 1)");
    $typeId = (int) $db->last_insert_id(MAIN_DB_PREFIX."c_type_fees");
}
if ($typeId <= 0) {
    dol_syslog('wallboxbilling receive: TK_ELE konnte nicht angelegt werden: '.$db->lasterror(), LOG_ERR);
    http_response_code(500);
    echo json_encode(['error' => 'Spesentyp TK_ELE konnte nicht angelegt werden: '.$db->lasterror()]);
    exit;
}

// ===== Spesenabrechnung für Session-Monat finden oder anlegen =====
// Monat aus start_time der Session (nicht "heute"), damit Sessions vom Vormonat
// in der korrekten Abrechnung landen wenn sie verspätet ankommen.
$sessionYear  = (int) date('Y', $startTs);
$sessionMonth = (int) date('n', $startTs);
$lastDay      = (int) date('t', mktime(0, 0, 0, $sessionMonth, 1, $sessionYear));
$periodStart  = sprintf('%04d-%02d-01 00:00:00', $sessionYear, $sessionMonth);
$periodEnd    = sprintf('%04d-%02d-%02d 23:59:59', $sessionYear, $sessionMonth, $lastDay);

$reportId = 0;
$sqlFind = "SELECT rowid FROM ".MAIN_DB_PREFIX."expensereport"
         ." WHERE fk_user_author = ".$fkUser
         ." AND fk_statut = 0"
         ." AND date_debut <= '".$periodEnd."'"
         ." AND date_fin   >= '".$periodStart."'"
         ." ORDER BY rowid ASC LIMIT 1";
$resFind = $db->query($sqlFind);
if ($resFind && ($obj = $db->fetch_object($resFind))) {
    $reportId = (int) $obj->rowid;
    dol_syslog('wallboxbilling receive: bestehende Draft-Spesenabrechnung #'.$reportId.' für User '.$fkUser.' verwendet', LOG_INFO);
}

if ($reportId <= 0) {
    // Neue Draft-Spesenabrechnung anlegen
    require_once DOL_DOCUMENT_ROOT.'/expensereport/class/expensereport.class.php';
    $apiUser = new User($db);
    $apiUser->fetch($apiUserId);

    $er = new ExpenseReport($db);
    $er->fk_user_author = $fkUser;
    $er->fk_user_valid  = $apiUserId;
    $er->date_debut     = dol_mktime(0,  0,  0, $sessionMonth, 1,       $sessionYear);
    $er->date_fin       = dol_mktime(23, 59, 59, $sessionMonth, $lastDay, $sessionYear);
    $er->status         = ExpenseReport::STATUS_DRAFT;
    $er->entity         = (int) $conf->entity;

    $reportId = (int) $er->create($apiUser);
    if ($reportId <= 0) {
        dol_syslog('wallboxbilling receive: Spesenabrechnung-Anlage fehlgeschlagen für User '.$fkUser.': '.$er->error, LOG_ERR);
        http_response_code(500);
        echo json_encode(['error' => 'Spesenabrechnung konnte nicht angelegt werden: '.$er->error]);
        exit;
    }
    dol_syslog('wallboxbilling receive: neue Draft-Spesenabrechnung #'.$reportId.' für User '.$fkUser.' angelegt', LOG_INFO);
}

// ===== Duplikat-Check via Marker in comments =====
// Marker: [wbx:RFID_PREFIX:START_UNIX] — eindeutig pro Session
$marker  = '[wbx:'.substr($rfidHash, 0, 16).':'.$startTs.']';
$sqlDup  = "SELECT rowid FROM ".MAIN_DB_PREFIX."expensereport_det"
         ." WHERE fk_expensereport = ".$reportId
         ." AND comments LIKE '%".$db->escape($marker)."%'";
$resDup = $db->query($sqlDup);
if ($resDup && $db->num_rows($resDup) > 0) {
    echo json_encode([
        'success'   => true,
        'duplicate' => true,
        'report_id' => $reportId,
        'message'   => 'Session bereits in Abrechnung enthalten',
    ]);
    exit;
}

// ===== Zeile in Spesenabrechnung einfügen =====
$qty       = round($kwh, 3);
$unitPrice = $effPrice;
$total     = round($qty * $unitPrice, 2);
$comment   = $db->escape(
    trim($wallboxId).' '.date('d.m.Y H:i', $startTs).' '.$marker
);
$sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."expensereport_det"
        ." (fk_expensereport, fk_c_type_fees, comments, qty, value_unit,"
        ."  total_ht, tva_tx, total_tva, total_ttc, date, fk_projet)"
        ." VALUES ("
        .$reportId.", ".$typeId.", '".$comment."', ".$qty.", ".$unitPrice.","
        ." ".$total.", 0, 0, ".$total.","
        ." '".$db->idate($startTs)."', 0)";

if (!$db->query($sqlIns)) {
    dol_syslog('wallboxbilling receive: INSERT expensereport_det failed: '.$db->lasterror().' | SQL: '.$sqlIns, LOG_ERR);
    http_response_code(500);
    echo json_encode(['error' => 'DB error: '.$db->lasterror()]);
    exit;
}

$lineId = (int) $db->last_insert_id(MAIN_DB_PREFIX.'expensereport_det');

// Spesenabrechnungs-Summen aktualisieren
$db->query("UPDATE ".MAIN_DB_PREFIX."expensereport er"
    ." SET er.total_ht  = (SELECT COALESCE(SUM(d.total_ht),  0) FROM ".MAIN_DB_PREFIX."expensereport_det d WHERE d.fk_expensereport = ".$reportId."),"
    ."     er.total_ttc = (SELECT COALESCE(SUM(d.total_ttc), 0) FROM ".MAIN_DB_PREFIX."expensereport_det d WHERE d.fk_expensereport = ".$reportId.")"
    ." WHERE er.rowid = ".$reportId);

dol_syslog('wallboxbilling receive: Zeile #'.$lineId.' (kwh='.$qty.', total='.$total.') in Spesenabrechnung #'.$reportId.' für User '.$fkUser.' eingefügt', LOG_INFO);

echo json_encode([
    'success'      => true,
    'report_id'    => $reportId,
    'line_id'      => $lineId,
    'fk_user'      => $fkUser,
    'kwh'          => $qty,
    'unit_price'   => $unitPrice,
    'total'        => $total,
    'message'      => 'Session in Spesenabrechnung eingetragen',
]);

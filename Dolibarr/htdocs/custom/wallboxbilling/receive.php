<?php
/**
 * Wallbox Billing — Session Upload Endpoint
 *
 * URL:    POST /custom/wallboxbilling/receive.php
 * Header: DOLAPIKEY: <dolibarr_api_key>
 * Body:   JSON { rfid_hash, wallbox_id, start_time, end_time, kwh }
 *
 * Kein /api/ im Pfad — wird direkt von PHP ausgeführt, nicht durch Restler geroutet.
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
        'version'  => '1.0.6',
        'nologin'  => 'active',
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

// Dolibarr laden — $nologin=1 verhindert Login-Redirect für API-Requests
$nologin     = 1;
$nocsrfcheck = 1;
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

// DOLAPIKEY aus Header lesen
$apiKey = '';
if (!empty($_SERVER['HTTP_DOLAPIKEY'])) {
    $apiKey = $_SERVER['HTTP_DOLAPIKEY'];
} elseif (function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'dolapikey') { $apiKey = $v; break; }
    }
}

dol_syslog('wallboxbilling receive: POST request from '.$_SERVER['REMOTE_ADDR'], LOG_INFO);

if (empty($apiKey)) {
    dol_syslog('wallboxbilling receive: missing DOLAPIKEY header', LOG_WARNING);
    http_response_code(401);
    echo json_encode(['error' => 'Missing DOLAPIKEY header']);
    exit;
}

// User per API-Key authentifizieren
// Dolibarr 20+ speichert api_key als SHA-256-Hash → beide Varianten prüfen
$apiKeyHashed = hash('sha256', $apiKey);
$sqlAuth = "SELECT rowid FROM ".MAIN_DB_PREFIX."user"
         ." WHERE (api_key = '".$db->escape($apiKey)."' OR api_key = '".$db->escape($apiKeyHashed)."')"
         ." AND statut = 1 AND entity IN (0, ".(int)$conf->entity.")";
$resAuth = $db->query($sqlAuth);
if (!$resAuth || $db->num_rows($resAuth) == 0) {
    dol_syslog('wallboxbilling receive: auth failed — key='.substr($apiKey, 0, 8).'*** hash='.substr($apiKeyHashed, 0, 8).'***', LOG_WARNING);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or inactive API key']);
    exit;
}

// JSON Body lesen
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    dol_syslog('wallboxbilling receive: invalid JSON body', LOG_WARNING);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// Pflichtfelder prüfen
foreach (['rfid_hash', 'wallbox_id', 'start_time', 'end_time', 'kwh'] as $f) {
    if (!isset($data[$f]) || $data[$f] === '') {
        dol_syslog('wallboxbilling receive: missing field '.$f, LOG_WARNING);
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: '.$f]);
        exit;
    }
}

$rfidHash  = $data['rfid_hash'];
$wallboxId = $data['wallbox_id'];
$kwh       = (float) $data['kwh'];
// Mikrosekunden entfernen bevor strtotime() — Python isoformat() sendet .123456
$startStr  = preg_replace('/\.\d+/', '', $data['start_time']);
$endStr    = preg_replace('/\.\d+/', '', $data['end_time']);
$startTs   = strtotime($startStr);
$endTs     = strtotime($endStr);

if (!preg_match('/^[a-f0-9]{64}$/i', $rfidHash)) {
    dol_syslog('wallboxbilling receive: invalid rfid_hash='.$rfidHash, LOG_WARNING);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid rfid_hash (must be SHA-256 hex, 64 chars)']);
    exit;
}
if ($kwh < 0) {
    dol_syslog('wallboxbilling receive: negative kwh='.$kwh, LOG_WARNING);
    http_response_code(400);
    echo json_encode(['error' => 'kwh must be >= 0']);
    exit;
}
if (!$startTs || !$endTs || $endTs <= $startTs) {
    dol_syslog('wallboxbilling receive: bad timestamps start='.$data['start_time'].' end='.$data['end_time'], LOG_WARNING);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or illogical timestamps']);
    exit;
}

// Duplikatsprüfung
$sqlCheck = "SELECT rowid FROM ".MAIN_DB_PREFIX."wallbox_sessions"
          ." WHERE rfid_hash = '".$db->escape($rfidHash)."'"
          ." AND start_time = '".$db->idate($startTs)."'"
          ." AND end_time = '".$db->idate($endTs)."'";
$resCheck = $db->query($sqlCheck);
if ($resCheck && $db->num_rows($resCheck) > 0) {
    echo json_encode(['success' => false, 'message' => 'Session already exists']);
    exit;
}

// RFID-Hash → fk_user auflösen
$fkUser = 0;
$resUser = $db->query("SELECT fk_user FROM ".MAIN_DB_PREFIX."wallbox_rfid"
    ." WHERE rfid_hash = '".$db->escape($rfidHash)."' AND entity = ".(int)$conf->entity);
if ($resUser && ($obj = $db->fetch_object($resUser))) {
    $fkUser = (int) $obj->fk_user;
}

// Session einfügen
$now = $db->idate(dol_now());
$sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."wallbox_sessions"
        ." (fk_user, rfid_hash, wallbox_id, start_time, end_time,"
        ."  kwh, price_per_kwh, total_cost, status, date_creation, transmitted_at)"
        ." VALUES ("
        .$fkUser.", '".$db->escape($rfidHash)."', '".$db->escape($wallboxId)."',"
        ." '".$db->idate($startTs)."', '".$db->idate($endTs)."',"
        ." ".round($kwh, 3).", 0.30, 0.00, 'completed', '".$now."', '".$now."')";

if (!$db->query($sqlIns)) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error: '.$db->lasterror()]);
    exit;
}

$id = (int) $db->last_insert_id(MAIN_DB_PREFIX.'wallbox_sessions');
dol_syslog('wallboxbilling receive: session #'.$id.' imported, kwh='.round($kwh, 3), LOG_INFO);

echo json_encode(['success' => true, 'id' => $id, 'message' => 'Session stored']);

<?php
header('Content-Type: application/json; charset=utf-8');

$query = isset($_SERVER['QUERY_STRING']) ? trim($_SERVER['QUERY_STRING']) : '';
if ($query === '') {
	http_response_code(400);
	echo json_encode(['error' => '缺少参数。']);
	exit;
}

if (!preg_match('/^(\d{4})([A-Za-z0-9]{9})([A-Za-z0-9]{8})$/', $query, $matches)) {
	http_response_code(400);
	echo json_encode(['error' => '参数格式不正确。']);
	exit;
}

$monthYear = $matches[1];
$mac62 = $matches[2];
$key = $matches[3];

require_once __DIR__ . '/db.php';
$pdo = getDb();

$stmtDev = $pdo->prepare('SELECT * FROM devices WHERE month_year = ? AND mac_b62 = ?');
$stmtDev->execute([$monthYear, $mac62]);
$device = $stmtDev->fetch();

if (!$device) {
	http_response_code(404);
	echo json_encode(['error' => '设备未注册。']);
	exit;
}

$stmtEnt = $pdo->prepare('SELECT key, state FROM entries WHERE device_id = ?');
$stmtEnt->execute([$device['id']]);

$result = [];
foreach ($stmtEnt->fetchAll() as $e) {
	$result[$e['key']] = intval($e['state']);
}

$result['SETUP'] = [
	'systime'    => strval(time()),
	'sleep'      => strval($device['sleep']      ?? 15),
	'sleep_low'  => strval($device['sleep_low']  ?? 30),
	'attime'     => strval($device['attime']      ?? 0),
	'time_start' => strval($device['time_start']  ?? 0),
	'time_end'   => strval($device['time_end']    ?? 1439),
];

echo json_encode($result, JSON_UNESCAPED_UNICODE);

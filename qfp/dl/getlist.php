<?php
header('Content-Type: application/json; charset=utf-8');

$query = isset($_SERVER['QUERY_STRING']) ? trim($_SERVER['QUERY_STRING']) : '';
if ($query === '') {
	http_response_code(400);
	echo json_encode(['error' => '缺少参数。']);
	exit;
}

// 分离主参数和附加参数（如 &lbs=...）
$parts = explode('&', $query, 2);
$mainParam = $parts[0];
$extraParams = [];
if (isset($parts[1])) {
	parse_str($parts[1], $extraParams);
}

if (!preg_match('/^(\d{4})([A-Za-z0-9]{9})([A-Za-z0-9]{8})$/', $mainParam, $matches)) {
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

$now = time();
$pdo->prepare('UPDATE devices SET getlist_count = COALESCE(getlist_count, 0) + 1, getlist_at = ? WHERE id = ?')
	->execute([$now, $device['id']]);
$device['getlist_count'] = intval($device['getlist_count'] ?? 0) + 1;
$device['getlist_at'] = $now;

// 记录基站定位信息 (cell=MNC,MCC,LAC,CellID,Signal)
if (isset($extraParams['cell']) && $extraParams['cell'] !== '') {
	$cell = $extraParams['cell'];
	// 校验格式：逗号分隔的数字/十六进制值
	if (preg_match('/^[\dA-Fa-f]+(,[\dA-Fa-f]+){2,}$/', $cell)) {
		$pdo->prepare('UPDATE devices SET cell=?, cell_at=? WHERE id=?')
		    ->execute([$cell, time(), $device['id']]);
	}
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
	'volume'     => strval($device['volume']      ?? 5),
];

echo json_encode($result, JSON_UNESCAPED_UNICODE);

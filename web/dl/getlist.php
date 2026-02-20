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

$baseDir = __DIR__;
$macDir = $baseDir . DIRECTORY_SEPARATOR . $monthYear . DIRECTORY_SEPARATOR . $mac62;
$configPath = $macDir . DIRECTORY_SEPARATOR . 'config.json';

if (!is_dir($macDir) || !is_file($configPath)) {
	http_response_code(404);
	echo json_encode(['error' => '目标目录或配置不存在。']);
	exit;
}

$configRaw = file_get_contents($configPath);
$config = json_decode($configRaw, true);
if (!is_array($config)) {
	http_response_code(500);
	echo json_encode(['error' => '配置文件损坏。']);
	exit;
}

$result = [];
foreach ($config as $k => $v) {
	$result[$k] = isset($v['state']) ? intval($v['state']) : 0;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);

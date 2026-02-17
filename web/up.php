
<?php
header('Content-Type: text/plain; charset=utf-8');

$query = isset($_SERVER['QUERY_STRING']) ? trim($_SERVER['QUERY_STRING']) : '';
if ($query === '') {
	http_response_code(400);
	echo '缺少参数。';
	exit;
}

if (!preg_match('/^(\d{4})([A-Za-z0-9]{9})([A-Za-z0-9]{8})$/', $query, $matches)) {
	http_response_code(400);
	echo '参数格式不正确。';
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
	echo '目标目录或配置不存在。';
	exit;
}

$configRaw = file_get_contents($configPath);
$config = json_decode($configRaw, true);
if (!is_array($config)) {
	http_response_code(500);
	echo '配置文件损坏。';
	exit;
}

if (!array_key_exists($key, $config)) {
	http_response_code(403);
	echo '无效的 key。';
	exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
	http_response_code(400);
	echo '图片上传失败。';
	exit;
}

$imagePath = $macDir . DIRECTORY_SEPARATOR . $key . '.png';
if (!move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
	http_response_code(500);
	echo '保存图片失败。';
	exit;
}

if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
	$audioPath = $macDir . DIRECTORY_SEPARATOR . $key . '.wav';
	if (!move_uploaded_file($_FILES['audio']['tmp_name'], $audioPath)) {
		http_response_code(500);
		echo '保存音频失败。';
		exit;
	}
}

echo '上传成功。';

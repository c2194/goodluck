
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

// 获取当前 state 并 +1
$currentState = isset($config[$key]['state']) ? intval($config[$key]['state']) : 0;
$newState = $currentState + 1;

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
	http_response_code(400);
	echo '图片上传失败。';
	exit;
}

// 文件名使用 key + state，如 Oel7saVb3.png
$imagePath = $macDir . DIRECTORY_SEPARATOR . $key . $newState . '.png';
if (!move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
	http_response_code(500);
	echo '保存图片失败。';
	exit;
}

// 调用 minpng.py 压缩图片
$minpngScript = __DIR__ . DIRECTORY_SEPARATOR . 'minpng.py';
$cmdImg = escapeshellcmd('python') . ' ' . escapeshellarg($minpngScript) . ' ' . escapeshellarg($imagePath) . ' ' . escapeshellarg($imagePath);
exec($cmdImg, $imgOutput, $imgReturnCode);
if ($imgReturnCode !== 0) {
	error_log("图片压缩失败: " . implode("\n", $imgOutput));
}

if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
	$audioPath = $macDir . DIRECTORY_SEPARATOR . $key . $newState . '.wav';
	if (!move_uploaded_file($_FILES['audio']['tmp_name'], $audioPath)) {
		http_response_code(500);
		echo '保存音频失败。';
		exit;
	}
	// 调用 Python 脚本将 WAV 转换为 MP3
	$pythonScript = __DIR__ . DIRECTORY_SEPARATOR . 'enc_au_pic.py';
	$cmd = escapeshellcmd('python') . ' ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($audioPath);
	exec($cmd, $output, $returnCode);
	if ($returnCode !== 0) {
		// 转换失败，但不影响上传结果，仅记录警告
		error_log("音频转换失败: " . implode("\n", $output));
	}
}

// 更新 config.json 中的 state
$config[$key]['state'] = strval($newState);
file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo '上传成功。';

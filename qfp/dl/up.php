
<?php
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/db.php';

$clientLogs = [];

function add_client_log(string $message): void
{
	global $clientLogs;
	$clientLogs[] = $message;
}

function respond(string $message, int $statusCode = 200): void
{
	global $clientLogs;
	http_response_code($statusCode);
	echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
	if (!empty($clientLogs)) {
		echo "\n<script>\n";
		foreach ($clientLogs as $log) {
			echo 'console.log(' . json_encode($log, JSON_UNESCAPED_UNICODE) . ");\n";
		}
		echo "</script>\n";
	}
	exit;
}

function cleanup_created_files(array $paths): void
{
	foreach ($paths as $path) {
		if (is_string($path) && $path !== '' && is_file($path)) {
			@unlink($path);
		}
	}
}

$query = isset($_SERVER['QUERY_STRING']) ? trim($_SERVER['QUERY_STRING']) : '';
if ($query === '') {
	respond('缺少参数。', 400);
}

if (!preg_match('/^(\d{4})([A-Za-z0-9]{9})([A-Za-z0-9]{8})$/', $query, $matches)) {
	respond('参数格式不正确。', 400);
}

$monthYear = $matches[1];
$mac62 = $matches[2];
$key = $matches[3];

$baseDir = __DIR__;
$macDir = $baseDir . DIRECTORY_SEPARATOR . $monthYear . DIRECTORY_SEPARATOR . $mac62;
$pdo = getDb();

$stmtDevice = $pdo->prepare('SELECT id FROM devices WHERE month_year = ? AND mac_b62 = ?');
$stmtDevice->execute([$monthYear, $mac62]);
$deviceId = $stmtDevice->fetchColumn();
if ($deviceId === false) {
	respond('目标设备不存在。', 404);
}

$stmtEntry = $pdo->prepare('SELECT id FROM entries WHERE device_id = ? AND key = ?');
$stmtEntry->execute([(int)$deviceId, $key]);
$entryId = $stmtEntry->fetchColumn();
if ($entryId === false) {
	respond('无效的 key。', 403);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
	respond('图片上传失败。', 400);
}

if (!is_dir($macDir) && !mkdir($macDir, 0777, true)) {
	respond('目标目录创建失败。', 500);
}

$createdFiles = [];
$transactionStarted = false;

try {
	$pdo->beginTransaction();
	$transactionStarted = true;

	$stmtState = $pdo->prepare('SELECT state FROM entries WHERE id = ?');
	$stmtState->execute([(int)$entryId]);
	$currentState = $stmtState->fetchColumn();
	if ($currentState === false) {
		throw new RuntimeException('无效的 key。', 403);
	}
	$newState = intval($currentState) + 1;

	// 文件名使用 key + state，如 Oel7saVb3.png
	$imagePath = $macDir . DIRECTORY_SEPARATOR . $key . $newState . '.png';
	if (!move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
		throw new RuntimeException('保存图片失败。', 500);
	}
	$createdFiles[] = $imagePath;

	// 调用 minpng.py 压缩图片
	$imgStatus = '图片压缩成功';
	$minpngScript = __DIR__ . DIRECTORY_SEPARATOR . 'minpng.py';
	$cmdImg = escapeshellcmd('python3') . ' ' . escapeshellarg($minpngScript) . ' ' . escapeshellarg($imagePath) . ' ' . escapeshellarg($imagePath);
	$cmdImgWithErr = $cmdImg . ' 2>&1';
	exec($cmdImgWithErr, $imgOutput, $imgReturnCode);
	if ($imgReturnCode !== 0) {
		$imgError = "图片压缩失败 (code {$imgReturnCode})";
		error_log($imgError);
		add_client_log($imgError);
		add_client_log("图片压缩命令: {$cmdImg}");
		if (!empty($imgOutput)) {
			add_client_log("图片压缩输出:\n" . implode("\n", $imgOutput));
		}
		$imgStatus = '图片压缩失败';
	}

	$audioStatus = '音频未上传';
	if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
		$audioPath = $macDir . DIRECTORY_SEPARATOR . $key . $newState . '.wav';
		if (!move_uploaded_file($_FILES['audio']['tmp_name'], $audioPath)) {
			throw new RuntimeException('保存音频失败。', 500);
		}
		$createdFiles[] = $audioPath;
		// 调用 Python 脚本将 WAV 转换为 MP3
		$audioStatus = '音频转换成功';
		$pythonScript = __DIR__ . DIRECTORY_SEPARATOR . 'enc_au_pic.py';
		$cmd = escapeshellcmd('python3') . ' ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($audioPath);
		$cmdWithErr = $cmd . ' 2>&1';
		exec($cmdWithErr, $output, $returnCode);
		if ($returnCode !== 0) {
			// 转换失败，但不影响上传结果，仅记录警告
			$audioError = "音频转换失败 (code {$returnCode})";
			error_log($audioError);
			add_client_log($audioError);
			add_client_log("音频转换命令: {$cmd}");
			if (!empty($output)) {
				add_client_log("音频转换输出:\n" . implode("\n", $output));
			}
			$audioStatus = '音频转换失败';
		}
	}

	$stmtUpdate = $pdo->prepare('UPDATE entries SET state = ? WHERE id = ?');
	$stmtUpdate->execute([$newState, (int)$entryId]);
	$pdo->commit();
	$transactionStarted = false;

	$resultMessage = '上传成功。' . "\n" . $imgStatus . "\n" . $audioStatus;
	add_client_log($imgStatus);
	add_client_log($audioStatus);
	respond($resultMessage);
} catch (Throwable $e) {
	if ($transactionStarted && $pdo->inTransaction()) {
		$pdo->rollBack();
	}
	cleanup_created_files($createdFiles);
	$statusCode = $e->getCode();
	if (!is_int($statusCode) || $statusCode < 400 || $statusCode >= 600) {
		$statusCode = 500;
	}
	respond($e->getMessage() !== '' ? $e->getMessage() : '上传失败。', $statusCode);
}

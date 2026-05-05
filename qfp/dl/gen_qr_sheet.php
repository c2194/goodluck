<?php
/**
 * gen_qr_sheet.php
 * 接收前端 POST 的 JSON {urls: [...]}，调用 Python 脚本生成二维码图片，
 * 以 JSON {png_b64: "..."} 返回 base64 编码的 PNG 供浏览器下载。
 */

header('Content-Type: application/json; charset=utf-8');

// 只允许 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!$input || empty($input['urls']) || !is_array($input['urls'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid urls field']);
    exit;
}

// 最多取前 24 个
$urls = array_slice(array_values($input['urls']), 0, 24);

// 写 URLs 到临时 JSON 文件
$tmpDir  = sys_get_temp_dir();
$jsonFile = tempnam($tmpDir, 'qr_urls_') . '.json';
$outFile  = tempnam($tmpDir, 'qr_sheet_') . '.png';

if (file_put_contents($jsonFile, json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write temp file']);
    exit;
}

$pyScript = __DIR__ . '/gen_qr_sheet.py';
$cmd = 'python3 ' . escapeshellarg($pyScript)
     . ' ' . escapeshellarg($jsonFile)
     . ' ' . escapeshellarg($outFile)
     . ' 2>&1';

exec($cmd, $output, $retCode);

@unlink($jsonFile);

if ($retCode !== 0 || !file_exists($outFile) || filesize($outFile) === 0) {
    http_response_code(500);
    echo json_encode(['error' => implode("\n", $output)]);
    exit;
}

$pngData = file_get_contents($outFile);
@unlink($outFile);

echo json_encode(['png_b64' => base64_encode($pngData)]);

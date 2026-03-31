<?php
header('Content-Type: application/json; charset=utf-8');

function jsonExit(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function parseHexString(string $value): ?int {
    $value = trim($value);
    if ($value === '' || !preg_match('/^[0-9A-Fa-f]+$/', $value)) {
        return null;
    }
    return intval(hexdec($value));
}

function fetchRemoteJson(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'qfp-cell-location/1.0',
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response !== false && $status >= 200 && $status < 300) {
            return $response;
        }
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header' => "Accept: application/json\r\nUser-Agent: qfp-cell-location/1.0\r\n",
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    return $response === false ? null : $response;
}

$cell = isset($_GET['cell']) ? trim((string) $_GET['cell']) : '';
if ($cell === '') {
    jsonExit(['success' => false, 'error' => '缺少 cell 参数']);
}

$parts = explode(',', $cell);
if (count($parts) < 4) {
    jsonExit(['success' => false, 'error' => 'cell 参数格式错误']);
}

$lac = parseHexString($parts[2]);
$ci = parseHexString($parts[3]);
if ($lac === null || $ci === null) {
    jsonExit(['success' => false, 'error' => 'LAC 或 CI 不是有效十六进制']);
}

$url = 'http://api.cellocation.com:84/cell/?mcc=460&mnc=0&lac=' . rawurlencode((string) $lac)
    . '&ci=' . rawurlencode((string) $ci)
    . '&output=json';

$response = fetchRemoteJson($url);
if ($response === null) {
    jsonExit(['success' => false, 'error' => '远程定位服务不可用']);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    jsonExit(['success' => false, 'error' => '定位服务返回格式错误']);
}

if (($data['errcode'] ?? -1) !== 0 || empty($data['lat']) || empty($data['lon'])) {
    jsonExit([
        'success' => false,
        'error' => '未查询到定位结果',
        'errcode' => $data['errcode'] ?? null,
    ]);
}

jsonExit([
    'success' => true,
    'lat' => (string) $data['lat'],
    'lon' => (string) $data['lon'],
    'radius' => (string) ($data['radius'] ?? ''),
    'address' => (string) ($data['address'] ?? ''),
    'lac' => $lac,
    'ci' => $ci,
]);
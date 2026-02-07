<?php
/**
 * 动态字体子集生成器
 * 
 * 使用方法：dfont.php?font=字体名称&text=需要的文字
 * 
 * 可用字体：
 * - AlimamaDaoLiTi (阿里妈妈刀隶体)
 * - AlimamaDongFangDaKai (阿里妈妈东方大楷)
 * - AlimamaShuHeiTi (阿里妈妈数黑体)
 * - DingTalkJinBuTi (钉钉进步体)
 * - TaoBaoMaiCaiTi (淘宝买菜体)
 */

// 关闭错误直接输出，避免破坏二进制字体流
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// 字体映射表
$fonts = [
    'AlimamaDaoLiTi' => [
        'file' => 'AlimamaDaoLiTi.woff2',
        'type' => 'woff2',
        'name' => '阿里妈妈刀隶体'
    ],
    'AlimamaDongFangDaKai' => [
        'file' => 'AlimamaDongFangDaKai-Regular.woff2',
        'type' => 'woff2',
        'name' => '阿里妈妈东方大楷'
    ],
    'AlimamaShuHeiTi' => [
        'file' => 'AlimamaShuHeiTi-Bold.woff2',
        'type' => 'woff2',
        'name' => '阿里妈妈数黑体'
    ],
    'DingTalkJinBuTi' => [
        'file' => 'DingTalkJinBuTi-Regular.ttf',
        'type' => 'ttf',
        'name' => '钉钉进步体'
    ],
    'TaoBaoMaiCaiTi' => [
        'file' => 'TaoBaoMaiCaiTi-Regular.woff2',
        'type' => 'woff2',
        'name' => '淘宝买菜体'
    ]
];

// 获取参数
$fontName = $_GET['font'] ?? '';
$text = $_GET['text'] ?? '';

// 如果请求字体列表
if (isset($_GET['list'])) {
    header('Content-Type: application/json; charset=utf-8');
    $list = [];
    foreach ($fonts as $key => $info) {
        $list[] = [
            'id' => $key,
            'name' => $info['name'],
            'type' => $info['type']
        ];
    }
    echo json_encode(['fonts' => $list], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 验证参数
if (empty($fontName) || empty($text)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'error' => '缺少参数',
        'usage' => 'dfont.php?font=字体名称&text=需要的文字',
        'list' => 'dfont.php?list 获取可用字体列表'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证字体是否存在
if (!isset($fonts[$fontName])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode([
        'error' => '字体不存在',
        'available' => array_keys($fonts)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$fontInfo = $fonts[$fontName];
$fontFile = __DIR__ . '/' . $fontInfo['file'];

// 检查字体文件是否存在
if (!file_exists($fontFile)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => '字体文件不存在: ' . $fontInfo['file']], JSON_UNESCAPED_UNICODE);
    exit;
}

// 去重文字
$chars = array_unique(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY));
$uniqueText = implode('', $chars);

// 生成缓存文件名
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$cacheKey = md5($fontName . '_' . $uniqueText);
$cacheFile = $cacheDir . '/' . $cacheKey . '.' . $fontInfo['type'];

// 如果缓存存在，直接返回
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
    outputFont($cacheFile, $fontInfo['type']);
    exit;
}

// 检查是否安装了 pyftsubset (fonttools)
$pyftsubset = findPyftsubset();
if (!$pyftsubset) {
    // 没有 pyftsubset，返回完整字体
    header('X-Subset-Status: full-font');
    outputFont($fontFile, $fontInfo['type']);
    exit;
}

// 创建临时文本文件
$textFile = tempnam(sys_get_temp_dir(), 'font_text_');
file_put_contents($textFile, $uniqueText);

// 使用 pyftsubset 生成子集
$outputFile = tempnam(sys_get_temp_dir(), 'font_subset_');
$outputExt = $fontInfo['type'] === 'woff2' ? '.woff2' : '.ttf';
$outputPath = $outputFile . $outputExt;

// 构建命令
$flavor = $fontInfo['type'] === 'woff2' ? '--flavor=woff2' : '';
$cmd = sprintf(
    '%s %s --text-file=%s --output-file=%s %s --no-hinting --desubroutinize 2>&1',
    escapeshellcmd($pyftsubset),
    escapeshellarg($fontFile),
    escapeshellarg($textFile),
    escapeshellarg($outputPath),
    $flavor
);

exec($cmd, $output, $returnCode);

// 清理临时文本文件
unlink($textFile);

if ($returnCode !== 0 || !file_exists($outputPath)) {
    // 子集化失败，返回完整字体
    header('X-Subset-Status: failed');
    header('X-Subset-Error: ' . implode(' ', $output));
    if (file_exists($outputFile)) unlink($outputFile);
    outputFont($fontFile, $fontInfo['type']);
    exit;
}

// 保存到缓存
copy($outputPath, $cacheFile);

// 输出子集字体
header('X-Subset-Status: success');
header('X-Subset-Chars: ' . mb_strlen($uniqueText, 'UTF-8'));
outputFont($outputPath, $fontInfo['type']);

// 清理临时文件
unlink($outputPath);
if (file_exists($outputFile)) unlink($outputFile);

/**
 * 输出字体文件
 */
function outputFont($file, $type) {
    // 清理所有输出缓冲，避免混入 HTML/警告
    while (ob_get_level()) {
        ob_end_clean();
    }

    $mimeTypes = [
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf'
    ];
    
    $mime = $mimeTypes[$type] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=31536000');
    header('Content-Disposition: inline; filename="subset.' . $type . '"');
    
    readfile($file);
    exit;
}

/**
 * 查找 pyftsubset 命令
 */
function findPyftsubset() {
    // Windows
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $paths = [
            'pyftsubset',
            'pyftsubset.exe',
            getenv('LOCALAPPDATA') . '\\Programs\\Python\\Python*\\Scripts\\pyftsubset.exe',
            getenv('APPDATA') . '\\Python\\Python*\\Scripts\\pyftsubset.exe',
        ];
    } else {
        // Linux/Mac
        $paths = [
            'pyftsubset',
            '/usr/local/bin/pyftsubset',
            '/usr/bin/pyftsubset',
            getenv('HOME') . '/.local/bin/pyftsubset'
        ];
    }
    
    foreach ($paths as $path) {
        // 处理通配符
        if (strpos($path, '*') !== false) {
            $matches = glob($path);
            if (!empty($matches)) {
                $path = $matches[0];
            }
        }
        
        // 检查命令是否可用
        $check = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' 
            ? "where " . escapeshellarg($path) . " 2>nul"
            : "which " . escapeshellarg($path) . " 2>/dev/null";
        
        exec($check, $output, $returnCode);
        if ($returnCode === 0) {
            return $path;
        }
        
        // 直接检查文件是否存在
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }
    
    // 尝试直接运行
    exec('pyftsubset --help 2>&1', $output, $returnCode);
    if ($returnCode === 0) {
        return 'pyftsubset';
    }
    
    return null;
}

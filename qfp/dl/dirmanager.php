<?php
// DB API (GET)
if (isset($_GET['dbapi'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/db.php';
    $pdo = getDb();
    $dbapi = $_GET['dbapi'];
    if ($dbapi === 'devices') {
        $stmt = $pdo->query('SELECT * FROM devices ORDER BY month_year DESC, registered_at DESC');
        echo json_encode($stmt->fetchAll());
        exit;
    }
    if ($dbapi === 'entries' && isset($_GET['device_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM entries WHERE device_id = ? ORDER BY key');
        $stmt->execute([intval($_GET['device_id'])]);
        echo json_encode($stmt->fetchAll());
        exit;
    }
    echo json_encode(['error' => 'unknown dbapi']);
    exit;
}

// DB 操作 (POST)
if (isset($_POST['action']) && in_array($_POST['action'], ['db_entry', 'db_setup'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/db.php';
    $pdo = getDb();
    if ($_POST['action'] === 'db_entry') {
        $deviceId = intval($_POST['device_id'] ?? 0);
        $key      = preg_replace('/[^A-Za-z0-9]/', '', $_POST['key'] ?? '');
        $act      = $_POST['entry_action'] ?? '';
        if ($deviceId <= 0 || strlen($key) !== 8) {
            echo json_encode(['success' => false, 'error' => '参数无效']);
            exit;
        }
        if ($act === 'enable') {
            $pdo->prepare('UPDATE entries SET state=1 WHERE device_id=? AND key=?')->execute([$deviceId, $key]);
        } elseif ($act === 'disable') {
            $pdo->prepare('UPDATE entries SET state=0 WHERE device_id=? AND key=?')->execute([$deviceId, $key]);
        }
        $stmt = $pdo->prepare('SELECT state FROM entries WHERE device_id=? AND key=?');
        $stmt->execute([$deviceId, $key]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'newState' => intval($row['state'])]);
        exit;
    }
    if ($_POST['action'] === 'db_setup') {
        $deviceId = intval($_POST['device_id'] ?? 0);
        $sleepNormal = intval($_POST['sleep_normal'] ?? ($_POST['sleep'] ?? 15));
        $sleepLow    = intval($_POST['sleep_low']    ?? $sleepNormal);
        $attime     = max(0, min(1439, intval($_POST['attime']      ?? 0)));
        $timeStart  = max(0, min(1439, intval($_POST['time_start']   ?? 0)));
        $timeEnd    = max(0, min(1439, intval($_POST['time_end']     ?? 1439)));
        $volume     = max(0, min(10,   intval($_POST['volume']       ?? 5)));
        if ($deviceId <= 0) {
            echo json_encode(['success' => false, 'error' => '参数无效']);
            exit;
        }
        $pdo->prepare('UPDATE devices SET sleep=?, sleep_low=?, attime=?, time_start=?, time_end=?, volume=? WHERE id=?')->execute([$sleepNormal, $sleepLow, $attime, $timeStart, $timeEnd, $volume, $deviceId]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// API 处理
if (isset($_POST['action']) && isset($_POST['path']) && isset($_POST['key'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'];
    $path = $_POST['path'];
    $key = $_POST['key'];
    $state = isset($_POST['state']) ? intval($_POST['state']) : 0;
    
    // 安全检查
    $path = str_replace(['..', '\\'], ['', '/'], $path);
    $path = trim($path, '/');
    
    $baseDir = __DIR__;
    $fullPath = $baseDir . ($path ? DIRECTORY_SEPARATOR . $path : '');
    $configPath = $fullPath . DIRECTORY_SEPARATOR . 'config.json';
    
    if (!is_file($configPath)) {
        echo json_encode(['success' => false, 'error' => '配置文件不存在']);
        exit;
    }
    
    $config = json_decode(file_get_contents($configPath), true);
    if (!is_array($config) || !isset($config[$key])) {
        echo json_encode(['success' => false, 'error' => '无效的key']);
        exit;
    }
    
    if ($action === 'enable') {
        // 开启：state 0 -> 1
        $config[$key]['state'] = '1';
    } elseif ($action === 'disable') {
        // 禁用：state 1 -> 0
        $config[$key]['state'] = '0';
    } elseif ($action === 'close') {
        // 关闭：删除文件，state -> 0
        $mp3File = $fullPath . DIRECTORY_SEPARATOR . $key . $state . '.mp3';
        $pngFile = $fullPath . DIRECTORY_SEPARATOR . $key . $state . '.png';
        if (is_file($mp3File)) unlink($mp3File);
        if (is_file($pngFile)) unlink($pngFile);
        $config[$key]['state'] = '0';
    }
    
    file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['success' => true, 'newState' => intval($config[$key]['state'])]);
    exit;
}

// API: 更新 SETUP 配置
if (isset($_POST['action']) && $_POST['action'] === 'setup' && isset($_POST['path'])) {
    header('Content-Type: application/json; charset=utf-8');
    $path = $_POST['path'];
    $path = str_replace(['..', '\\'], ['', '/'], $path);
    $path = trim($path, '/');
    $baseDir = __DIR__;
    $fullPath = $baseDir . ($path ? DIRECTORY_SEPARATOR . $path : '');
    $configPath = $fullPath . DIRECTORY_SEPARATOR . 'config.json';

    if (!is_file($configPath)) {
        echo json_encode(['success' => false, 'error' => '配置文件不存在']);
        exit;
    }

    $config = json_decode(file_get_contents($configPath), true);
    if (!is_array($config)) {
        echo json_encode(['success' => false, 'error' => '配置文件格式错误']);
        exit;
    }

    $sleep  = isset($_POST['sleep'])  ? strval(intval($_POST['sleep']))  : '15';
    $attime = isset($_POST['attime']) ? strval(intval($_POST['attime'])) : '3000';
    $volume = isset($_POST['volume']) ? strval(max(0, min(10, intval($_POST['volume'])))) : '5';

    if (!isset($config['SETUP'])) {
        $config['SETUP'] = ['systime' => strval(time()), 'sleep' => $sleep, 'attime' => $attime, 'volume' => $volume];
    } else {
        $config['SETUP']['sleep']  = $sleep;
        $config['SETUP']['attime'] = $attime;
        $config['SETUP']['volume'] = $volume;
    }

    file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['success' => true]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>目录管理</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .breadcrumb {
            background: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .breadcrumb a {
            color: #007bff;
            text-decoration: none;
            cursor: pointer;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .breadcrumb span {
            color: #666;
            margin: 0 8px;
        }
        .container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        .dir-item {
            background: #fff;
            border-radius: 12px;
            padding: 20px 30px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dir-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background: #f8f9fa;
        }
        .dir-item::before {
            content: '📁';
            font-size: 24px;
        }
        .dir-name {
            font-size: 16px;
            color: #333;
        }
        .dir-meta {
            font-size: 12px;
            color: #999;
            margin-top: 4px;
        }
        .config-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        .config-item {
            background: #fff;
            border-radius: 16px;
            padding: 15px;
            min-width: 180px;
            max-width: 220px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        .config-img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            background: #f0f0f0;
        }
        .config-img-wrapper {
            position: relative;
            margin-bottom: 12px;
        }
        .play-btn {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 36px;
            height: 36px;
            background: rgba(0, 0, 0, 0.6);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .play-btn:hover {
            background: rgba(0, 0, 0, 0.8);
            transform: scale(1.1);
        }
        .play-btn::after {
            content: '';
            width: 0;
            height: 0;
            border-left: 12px solid #fff;
            border-top: 7px solid transparent;
            border-bottom: 7px solid transparent;
            margin-left: 3px;
        }
        .play-btn.playing::after {
            border-left: 4px solid #fff;
            border-right: 4px solid #fff;
            border-top: none;
            border-bottom: none;
            width: 10px;
            height: 14px;
            margin-left: 0;
        }
        .config-img-placeholder {
            width: 100%;
            height: 150px;
            border-radius: 10px;
            margin-bottom: 12px;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 14px;
        }
        .config-key {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            word-break: break-all;
        }
        .config-key a {
            color: #007bff;
            text-decoration: none;
        }
        .config-key a:hover {
            text-decoration: underline;
        }
        .config-state {
            font-size: 14px;
            padding: 6px 14px;
            border-radius: 20px;
            display: inline-block;
        }
        .state-disabled {
            background: #ffebee;
            color: #c62828;
        }
        .state-inactive {
            background: #fff3e0;
            color: #ef6c00;
        }
        .state-active {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .config-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }
        .action-btn {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .action-btn:hover {
            opacity: 0.8;
        }
        .btn-enable {
            background: #4caf50;
            color: #fff;
        }
        .btn-disable {
            background: #ff9800;
            color: #fff;
        }
        .btn-close {
            background: #f44336;
            color: #fff;
        }
        .empty-msg {
            color: #666;
            font-size: 16px;
            padding: 40px;
            text-align: center;
            width: 100%;
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
        }
        .setup-panel {
            margin-top: 24px;
            background: #fff;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 420px;
        }
        .setup-row {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            gap: 12px;
        }
        .setup-label {
            font-size: 13px;
            color: #555;
            min-width: 120px;
            flex-shrink: 0;
        }
        .setup-value {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        .setup-input {
            flex: 1;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .setup-input:focus {
            border-color: #007bff;
        }
        .setup-save-btn {
            margin-top: 4px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 24px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .setup-save-btn:hover {
            background: #0056b3;
        }
        .setup-save-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        /* ---- 标签切换 ---- */
        .tab-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }
        .tab-btn {
            padding: 8px 20px;
            border-radius: 8px;
            border: 1px solid #ddd;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
            color: #555;
            transition: all 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .tab-btn:hover { background: #f0f0f0; }
        .tab-active {
            background: #007bff;
            color: #fff;
            border-color: #007bff;
        }
        /* ---- 数据库设备列表 ---- */
        .db-device-row {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 12px;
            overflow: hidden;
        }
        .db-device-row-inner {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px 20px;
            padding: 14px 20px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .db-device-row-inner:hover { background: #f8f9fa; }
        .db-device-mac {
            font-weight: 700;
            font-size: 16px;
            color: #333;
            min-width: 100px;
        }
        .db-device-info {
            font-size: 13px;
            color: #666;
        }
        .db-toggle-icon {
            margin-left: auto;
            color: #999;
            font-size: 14px;
            transition: transform 0.2s;
        }
        .db-device-row.expanded .db-toggle-icon { transform: rotate(180deg); }
        .db-entries-panel {
            padding: 0 20px 16px;
            border-top: 1px solid #f0f0f0;
        }
        .db-loading {
            padding: 16px;
            color: #999;
            font-size: 14px;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="tab-bar">
    <button id="tab-dir" class="tab-btn tab-active" onclick="switchTab('dir')">📁 目录视图</button>
    <button id="tab-db"  class="tab-btn"            onclick="switchTab('db')">🗄 数据库视图</button>
</div>

<div id="dir-view">

<?php
$baseDir = __DIR__;
$currentPath = isset($_GET['path']) ? $_GET['path'] : '';

// 安全检查：防止目录遍历攻击
$currentPath = str_replace(['..', '\\'], ['', '/'], $currentPath);
$currentPath = trim($currentPath, '/');

$fullPath = $baseDir . ($currentPath ? DIRECTORY_SEPARATOR . $currentPath : '');

// 格式化第一级目录名（MMYY -> YY年M月）
function formatMonthYear($dirName) {
    if (preg_match('/^(\d{2})(\d{2})$/', $dirName, $m)) {
        $month = intval($m[1]);
        $year = $m[2];
        return $year . '年' . $month . '月';
    }
    return $dirName;
}

// 构建面包屑
$pathParts = $currentPath ? explode('/', $currentPath) : [];
?>

<div class="breadcrumb">
    <a onclick="navigate('')">根目录</a>
    <?php
    $accumulated = '';
    $index = 0;
    foreach ($pathParts as $part) {
        $accumulated .= ($accumulated ? '/' : '') . $part;
        // 第一级使用格式化显示
        $displayPart = ($index === 0) ? formatMonthYear($part) : $part;
        echo '<span>/</span>';
        echo '<a onclick="navigate(\'' . htmlspecialchars($accumulated) . '\')">' . htmlspecialchars($displayPart) . '</a>';
        $index++;
    }
    ?>
</div>

<?php
// 检查是否存在 config.json
$configPath = $fullPath . DIRECTORY_SEPARATOR . 'config.json';
$hasConfig = is_file($configPath);

// 获取子目录
$subDirs = [];
if (is_dir($fullPath)) {
    $items = scandir($fullPath);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
        if (is_dir($itemPath)) {
            $subDirs[] = $item;
        }
    }
    sort($subDirs);
}

// 显示子目录
if (!empty($subDirs)) {
    echo '<h2>目录列表</h2>';
    echo '<div class="container">';
    foreach ($subDirs as $dir) {
        $newPath = $currentPath ? $currentPath . '/' . $dir : $dir;
        // 第一级目录使用格式化显示
        $displayName = ($currentPath === '') ? formatMonthYear($dir) : $dir;
        echo '<div class="dir-item" onclick="navigate(\'' . htmlspecialchars($newPath) . '\')">';
        echo '<span class="dir-name">' . htmlspecialchars($displayName) . '</span>';
        echo '</div>';
    }
    echo '</div>';
}

// 显示 config.json 内容
if ($hasConfig) {
    $configContent = file_get_contents($configPath);
    $config = json_decode($configContent, true);
    
    if (is_array($config) && !empty($config)) {
        echo '<h2 style="margin-top: 30px;">资源列表</h2>';
        echo '<div class="config-container">';
        
        foreach ($config as $key => $value) {
            if ($key === 'SETUP') continue;
            $state = isset($value['state']) ? intval($value['state']) : 0;
            
            if ($state === 0) {
                $stateText = '已禁用';
                $stateClass = 'state-disabled';
            } elseif ($state === 1) {
                $stateText = '未启用';
                $stateClass = 'state-inactive';
            } else {
                $stateText = '上传 ' . $state . ' 次';
                $stateClass = 'state-active';
            }
            
            // 检查图片和音频文件是否存在
            $imgFileName = $key . $state . '.png';
            $mp3FileName = $key . $state . '.mp3';
            $imgPath = $fullPath . DIRECTORY_SEPARATOR . $imgFileName;
            $mp3Path = $fullPath . DIRECTORY_SEPARATOR . $mp3FileName;
            $imgUrl = ($currentPath ? $currentPath . '/' : '') . $imgFileName;
            $mp3Url = ($currentPath ? $currentPath . '/' : '') . $mp3FileName;
            
            // 操作按钮
            if ($state === 0) {
                $actionText = '开启';
                $actionClass = 'btn-enable';
                $actionType = 'enable';
            } elseif ($state === 1) {
                $actionText = '禁用';
                $actionClass = 'btn-disable';
                $actionType = 'disable';
            } else {
                $actionText = '关闭';
                $actionClass = 'btn-close';
                $actionType = 'close';
            }
            
            echo '<div class="config-item" data-key="' . htmlspecialchars($key) . '" data-state="' . $state . '" data-path="' . htmlspecialchars($currentPath) . '">';
            echo '<div class="config-img-wrapper">';
            if (is_file($imgPath)) {
                echo '<img class="config-img" src="' . htmlspecialchars($imgUrl) . '" alt="' . htmlspecialchars($key) . '">';
            } else {
                echo '<div class="config-img-placeholder">无图片</div>';
            }
            if (is_file($mp3Path)) {
                echo '<div class="play-btn" onclick="playAudio(this, \'' . htmlspecialchars($mp3Url) . '\')"></div>';
            }
            echo '</div>';
            $keyLink = '/qfp/?' . str_replace('/', '', $currentPath) . $key;
            echo '<div class="config-key"><a href="' . htmlspecialchars($keyLink, ENT_QUOTES, 'UTF-8') . '" target="_blank">' . htmlspecialchars($key) . '</a></div>';
            echo '<div class="config-footer">';
            echo '<div class="config-state ' . $stateClass . '">' . $stateText . '</div>';
            echo '<button class="action-btn ' . $actionClass . '" onclick="handleAction(this, \'' . $actionType . '\')">' . $actionText . '</button>';
            echo '</div>';
            echo '</div>';
        }
        
        echo '</div>';

        // 显示 SETUP 面板
        $setup = isset($config['SETUP']) && is_array($config['SETUP']) ? $config['SETUP'] : null;
        if ($setup) {
            $systime = isset($setup['systime']) ? intval($setup['systime']) : 0;
            $systimeFormatted = $systime > 0 ? date('Y-m-d', $systime) : '未知';
            $sleepVal  = htmlspecialchars(isset($setup['sleep'])  ? $setup['sleep']  : '', ENT_QUOTES, 'UTF-8');
            $attimeVal = htmlspecialchars(isset($setup['attime']) ? $setup['attime'] : '', ENT_QUOTES, 'UTF-8');
            $volumeVal = htmlspecialchars(isset($setup['volume']) ? $setup['volume'] : '5', ENT_QUOTES, 'UTF-8');
            echo '<div class="setup-panel" data-path="' . htmlspecialchars($currentPath, ENT_QUOTES, 'UTF-8') . '">';
            echo '<div class="setup-row"><span class="setup-label">上线时间</span><span class="setup-value">' . $systimeFormatted . '</span></div>';
            echo '<div class="setup-row"><label class="setup-label" for="inp-sleep">睡眠间隔</label><input class="setup-input" id="inp-sleep" type="number" value="' . $sleepVal . '" min="1"></div>';
            echo '<div class="setup-row"><label class="setup-label" for="inp-attime">每日更新时间</label><input class="setup-input" id="inp-attime" type="number" value="' . $attimeVal . '" min="0"></div>';
            echo '<div class="setup-row"><label class="setup-label" for="inp-volume">音量</label><input class="setup-input" id="inp-volume" type="number" value="' . $volumeVal . '" min="0" max="10"></div>';
            echo '<button class="setup-save-btn" onclick="saveSetup(this)">确定</button>';
            echo '</div>';
        }
    }
}

// 如果没有内容
if (empty($subDirs) && !$hasConfig) {
    echo '<div class="empty-msg">此目录为空</div>';
}
?>

<script>
var currentAudio = null;
var currentBtn = null;

function navigate(path) {
    window.location.href = '?path=' + encodeURIComponent(path);
}

function playAudio(btn, url) {
    // 如果点击的是当前正在播放的
    if (currentBtn === btn && currentAudio && !currentAudio.paused) {
        currentAudio.pause();
        btn.classList.remove('playing');
        return;
    }
    
    // 停止之前的播放
    if (currentAudio) {
        currentAudio.pause();
        if (currentBtn) currentBtn.classList.remove('playing');
    }
    
    // 创建新的音频并播放
    currentAudio = new Audio(url);
    currentBtn = btn;
    btn.classList.add('playing');
    
    currentAudio.play();
    
    currentAudio.onended = function() {
        btn.classList.remove('playing');
        currentAudio = null;
        currentBtn = null;
    };
}

function handleAction(btn, action) {
    var item = btn.closest('.config-item');
    var key = item.dataset.key;
    var state = parseInt(item.dataset.state);
    var path = item.dataset.path;
    
    var formData = new FormData();
    formData.append('action', action);
    formData.append('path', path);
    formData.append('key', key);
    formData.append('state', state);
    
    btn.disabled = true;
    btn.textContent = '处理中...';
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            var newState = data.newState;
            item.dataset.state = newState;
            
            // 更新状态显示
            var stateEl = item.querySelector('.config-state');
            stateEl.classList.remove('state-disabled', 'state-inactive', 'state-active');
            
            if (newState === 0) {
                stateEl.textContent = '已禁用';
                stateEl.classList.add('state-disabled');
                btn.textContent = '开启';
                btn.className = 'action-btn btn-enable';
                btn.onclick = function() { handleAction(this, 'enable'); };
            } else if (newState === 1) {
                stateEl.textContent = '未启用';
                stateEl.classList.add('state-inactive');
                btn.textContent = '禁用';
                btn.className = 'action-btn btn-disable';
                btn.onclick = function() { handleAction(this, 'disable'); };
            } else {
                stateEl.textContent = '上传 ' + newState + ' 次';
                stateEl.classList.add('state-active');
                btn.textContent = '关闭';
                btn.className = 'action-btn btn-close';
                btn.onclick = function() { handleAction(this, 'close'); };
            }
            
            // 如果是关闭操作，移除图片和播放按钮
            if (action === 'close') {
                var imgWrapper = item.querySelector('.config-img-wrapper');
                var img = imgWrapper.querySelector('.config-img');
                var playBtn = imgWrapper.querySelector('.play-btn');
                
                if (img) {
                    var placeholder = document.createElement('div');
                    placeholder.className = 'config-img-placeholder';
                    placeholder.textContent = '无图片';
                    img.replaceWith(placeholder);
                }
                if (playBtn) {
                    playBtn.remove();
                }
            }
        } else {
            alert('操作失败: ' + (data.error || '未知错误'));
        }
        btn.disabled = false;
    })
    .catch(function(err) {
        alert('请求失败: ' + err.message);
        btn.disabled = false;
        // 恢复按钮文字
        if (action === 'enable') btn.textContent = '开启';
        else if (action === 'disable') btn.textContent = '禁用';
        else btn.textContent = '关闭';
    });
}

function saveSetup(btn) {
    var panel = btn.closest('.setup-panel');
    var path   = panel.dataset.path;
    var sleep  = panel.querySelector('#inp-sleep').value;
    var attime = panel.querySelector('#inp-attime').value;
    var volume = panel.querySelector('#inp-volume').value;

    var formData = new FormData();
    formData.append('action', 'setup');
    formData.append('path', path);
    formData.append('sleep', sleep);
    formData.append('attime', attime);
    formData.append('volume', volume);

    btn.disabled = true;
    btn.textContent = '保存中...';

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                btn.textContent = '已保存';
                setTimeout(function() { btn.textContent = '确定'; btn.disabled = false; }, 1500);
            } else {
                alert('保存失败: ' + (data.error || '未知错误'));
                btn.textContent = '确定';
                btn.disabled = false;
            }
        })
        .catch(function(err) {
            alert('请求失败: ' + err.message);
            btn.textContent = '确定';
            btn.disabled = false;
        });
}

function escapeHtml(text) {
    return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderCellLocationResult(target, data) {
    var mapUrl = 'https://uri.amap.com/marker?position=' +
        encodeURIComponent(data.lon + ',' + data.lat) +
        '&name=' + encodeURIComponent('设备位置');

    target.innerHTML =
        '<a href="' + mapUrl + '" target="_blank">' +
        escapeHtml(data.lon + ', ' + data.lat) +
        '</a>' +
        '，误差约 ' + escapeHtml(data.radius) + ' 米' +
        '<br>' + escapeHtml(data.address || '');
}

function loadCellLocation(cell, targetId) {
    var target = document.getElementById(targetId);
    if (!target) return;

    var url = new URL('cell_location.php', window.location.href);
    url.searchParams.set('cell', cell);

    fetch(url.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.lat && data.lon) {
                renderCellLocationResult(target, data);
                return;
            }
            target.textContent = data.error || '定位失败';
        })
        .catch(function(err) {
            target.textContent = '定位请求失败: ' + err.message;
        });
}

// ---- 数据库视图 ----
var dbDevicesCache = null;
var dbEntriesCache = {};
var dbState = { level: 'months', monthYear: null, deviceId: null };

function checkAndPlayDb(btn) {
    var mp3Url = btn.dataset.mp3;
    playAudio(btn, mp3Url);
}

// 图片加载完成后检测对应音频是否存在，显示播放按钮
document.addEventListener('load', function(e) {
    if (e.target.tagName === 'IMG' && e.target.classList.contains('config-img')) {
        var wrapper = e.target.closest('.config-img-wrapper');
        if (!wrapper) return;
        var playBtn = wrapper.querySelector('.db-play-btn');
        if (!playBtn) return;
        var mp3Url = playBtn.dataset.mp3;
        fetch(mp3Url, { method: 'HEAD' })
            .then(function(r) { if (r.ok) playBtn.style.display = ''; })
            .catch(function() {});
    }
}, true);

function switchTab(tab) {
    var dirView = document.getElementById('dir-view');
    var dbView  = document.getElementById('db-view');
    var tabDir  = document.getElementById('tab-dir');
    var tabDb   = document.getElementById('tab-db');
    if (tab === 'dir') {
        dirView.style.display = '';
        dbView.style.display  = 'none';
        tabDir.classList.add('tab-active');
        tabDb.classList.remove('tab-active');
    } else {
        dirView.style.display = 'none';
        dbView.style.display  = '';
        tabDir.classList.remove('tab-active');
        tabDb.classList.add('tab-active');
        loadDbDevices();
    }
}

function minsToTime(m) {
    m = parseInt(m);
    if (isNaN(m) || m < 0 || m > 1439) m = 0;
    var h = Math.floor(m / 60), mm = m % 60;
    return (h < 10 ? '0' : '') + h + ':' + (mm < 10 ? '0' : '') + mm;
}

function dbFormatMonthYear(my) {
    if (/^\d{4}$/.test(my)) {
        return '20' + my.substring(2, 4) + '年' + parseInt(my.substring(0, 2)) + '月';
    }
    return my;
}

function loadDbDevices() {
    if (dbDevicesCache !== null) { renderDbView(); return; }
    document.getElementById('db-devices-list').innerHTML = '<div class="db-loading">加载中…</div>';
    document.getElementById('db-breadcrumb').style.display = 'none';
    fetch('?dbapi=devices')
        .then(function(r) { return r.json(); })
        .then(function(data) { dbDevicesCache = data; renderDbView(); })
        .catch(function(e) {
            document.getElementById('db-devices-list').innerHTML =
                '<div class="empty-msg">加载失败: ' + e.message + '</div>';
        });
}

function renderDbView() {
    if (dbState.level === 'months') renderDbMonths();
    else if (dbState.level === 'devices') renderDbDevicesForMonth();
    else renderDbEntriesView();
}

function renderDbBreadcrumb(parts) {
    var bc = document.getElementById('db-breadcrumb');
    bc.style.display = '';
    var html = '<a onclick="dbNavTo(\'months\')">根目录</a>';
    parts.forEach(function(p) {
        html += '<span>/</span><a onclick="' + p.onclick + '">' + p.label + '</a>';
    });
    bc.innerHTML = html;
}

function dbNavTo(level, monthYear, deviceId) {
    dbState.level = level;
    dbState.monthYear = monthYear || null;
    dbState.deviceId = deviceId || null;
    renderDbView();
}

function renderDbMonths() {
    renderDbBreadcrumb([]);
    var list = document.getElementById('db-devices-list');
    if (!dbDevicesCache || !dbDevicesCache.length) {
        list.innerHTML = '<div class="empty-msg">数据库暂无设备记录</div>';
        return;
    }
    var months = {};
    dbDevicesCache.forEach(function(d) {
        if (!months[d.month_year]) months[d.month_year] = 0;
        months[d.month_year]++;
    });
    var keys = Object.keys(months).sort().reverse();
    var html = '<div class="container">';
    keys.forEach(function(my) {
        var count = months[my];
        html += '<div class="dir-item" onclick="dbNavTo(\'devices\',\'' + my + '\')">';
        html += '<div><span class="dir-name">' + dbFormatMonthYear(my) + '</span>';
        html += '<div class="dir-meta">' + count + ' 台设备</div></div>';
        html += '</div>';
    });
    html += '</div>';
    list.innerHTML = html;
}

function renderDbDevicesForMonth() {
    var my = dbState.monthYear;
    var monthLabel = dbFormatMonthYear(my);
    renderDbBreadcrumb([
        { label: monthLabel, onclick: 'dbNavTo(\'devices\',\'' + my + '\')' }
    ]);
    var list = document.getElementById('db-devices-list');
    var devices = (dbDevicesCache || []).filter(function(d) { return d.month_year === my; });
    if (!devices.length) {
        list.innerHTML = '<div class="empty-msg">暂无设备</div>';
        return;
    }
    var html = '<div class="container">';
    devices.forEach(function(d) {
        var regDate = d.registered_at ? new Date(d.registered_at * 1000).toLocaleDateString('zh-CN') : '';
        html += '<div class="dir-item" onclick="dbNavTo(\'entries\',\'' + my + '\',' + d.id + ')">';
        html += '<div><span class="dir-name">' + d.mac_b62 + '</span>';
        if (regDate) html += '<div class="dir-meta">注册: ' + regDate + '</div>';
        html += '<div class="dir-meta">请求次数: ' + (parseInt(d.getlist_count || 0, 10) || 0) + '</div>';
        html += '</div>';
        html += '</div>';
    });
    html += '</div>';
    list.innerHTML = html;
}

function renderDbEntriesView() {
    var deviceId = dbState.deviceId;
    var my = dbState.monthYear;
    var device = null;
    if (dbDevicesCache) {
        for (var i = 0; i < dbDevicesCache.length; i++) {
            if (dbDevicesCache[i].id == deviceId) { device = dbDevicesCache[i]; break; }
        }
    }
    var monthLabel = dbFormatMonthYear(my);
    var deviceLabel = device ? device.mac_b62 : ('设备' + deviceId);
    renderDbBreadcrumb([
        { label: monthLabel, onclick: 'dbNavTo(\'devices\',\'' + my + '\')' },
        { label: deviceLabel, onclick: 'dbNavTo(\'entries\',\'' + my + '\',' + deviceId + ')' }
    ]);
    var list = document.getElementById('db-devices-list');
    if (dbEntriesCache[deviceId]) {
        renderDbEntries(list, dbEntriesCache[deviceId], deviceId);
        return;
    }
    list.innerHTML = '<div class="db-loading">加载中…</div>';
    fetch('?dbapi=entries&device_id=' + deviceId)
        .then(function(r) { return r.json(); })
        .then(function(data) { dbEntriesCache[deviceId] = data; renderDbEntries(list, data, deviceId); })
        .catch(function(e) { list.innerHTML = '<div class="empty-msg">加载失败: ' + e.message + '</div>'; });
}

function renderDbEntries(panel, entries, deviceId) {
    if (!entries || !entries.length) {
        panel.innerHTML = '<div class="empty-msg">无条目记录</div>';
        return;
    }
    // 找到设备信息，用于拼接图片/音频路径
    var device = null;
    if (dbDevicesCache) {
        for (var i = 0; i < dbDevicesCache.length; i++) {
            if (dbDevicesCache[i].id == deviceId) { device = dbDevicesCache[i]; break; }
        }
    }
    var imgBase = device ? (device.month_year + '/' + device.mac_b62 + '/') : '';
    var pendingCellLoads = [];

    var html = '<div class="config-container" style="margin-top:12px;padding-bottom:4px">';
    entries.forEach(function(e) {
        var state = parseInt(e.state);
        var stateText, stateClass, actText, actClass, actType;
        if (state === 0) {
            stateText = '已禁用'; stateClass = 'state-disabled';
            actText = '开启';    actClass = 'btn-enable';  actType = 'enable';
        } else if (state === 1) {
            stateText = '未启用'; stateClass = 'state-inactive';
            actText = '禁用';    actClass = 'btn-disable'; actType = 'disable';
        } else {
            stateText = '上传 ' + state + ' 次'; stateClass = 'state-active';
            actText = '禁用'; actClass = 'btn-disable'; actType = 'disable';
        }
        var imgUrl = imgBase + e.key + state + '.png';
        var mp3Url = imgBase + e.key + state + '.mp3';
        html += '<div class="config-item" data-key="' + e.key + '" data-device-id="' + deviceId + '" data-state="' + state + '" data-img-base="' + imgBase + '">';
        html += '<div class="config-img-wrapper">';
        if (imgBase) {
            html += '<img class="config-img" src="' + imgUrl + '" alt="' + e.key + '" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">';
            html += '<div class="config-img-placeholder" style="display:none">无图片</div>';
            html += '<div class="play-btn db-play-btn" style="display:none" data-mp3="' + mp3Url + '" onclick="checkAndPlayDb(this)"></div>';
        } else {
            html += '<div class="config-img-placeholder">无图片</div>';
        }
        html += '</div>';
        var keyLink = device ? ('/qfp/?' + device.month_year + device.mac_b62 + e.key) : '';
        html += '<div class="config-key">' + (keyLink ? '<a href="' + keyLink + '" target="_blank">' + e.key + '</a>' : e.key) + '</div>';
        html += '<div class="config-footer">';
        html += '<div class="config-state ' + stateClass + '">' + stateText + '</div>';
        html += '<button class="action-btn ' + actClass + '" onclick="handleDbAction(this,\'' + actType + '\')">' + actText + '</button>';
        html += '</div>';
        html += '</div>';
    });
    html += '</div>';
    if (dbDevicesCache) {
        var device = null;
        for (var i = 0; i < dbDevicesCache.length; i++) {
            if (dbDevicesCache[i].id == deviceId) { device = dbDevicesCache[i]; break; }
        }
        if (device) {
            var regDate = new Date(device.registered_at * 1000).toLocaleDateString('zh-CN');
            var getlistAt = device.getlist_at ? new Date(device.getlist_at * 1000).toLocaleString('zh-CN') : '';
            var getlistCount = parseInt(device.getlist_count || 0, 10) || 0;
            var sleepNormal = (device.sleep !== null && device.sleep !== undefined) ? device.sleep : 15;
            var sleepLow = (device.sleep_low !== null && device.sleep_low !== undefined) ? device.sleep_low : sleepNormal;
            html += '<div class="setup-panel" style="margin-top:16px" data-device-id="' + deviceId + '">';
            html += '<div class="setup-row"><span class="setup-label">注册时间</span><span class="setup-value">' + regDate + '</span></div>';
            html += '<div class="setup-row"><span class="setup-label">请求次数</span><span class="setup-value">' + getlistCount + '</span></div>';
            if (getlistAt) {
                html += '<div class="setup-row"><span class="setup-label">最后请求</span><span class="setup-value">' + getlistAt + '</span></div>';
            }
            var cell = device.cell || '';
            var cellAt = device.cell_at ? new Date(device.cell_at * 1000).toLocaleString('zh-CN') : '';
            if (cell) {
                html += '<div class="setup-row"><span class="setup-label">基站信息</span><span class="setup-value">' + cell + '</span></div>';
                html += '<div class="setup-row"><span class="setup-label">上报时间</span><span class="setup-value">' + cellAt + '</span></div>';
                html += '<div class="setup-row"><span class="setup-label">位置信息</span><span class="setup-value" id="cell-location-' + deviceId + '">查询中...</span></div>';
                pendingCellLoads.push({ cell: cell, targetId: 'cell-location-' + deviceId });
            }
            html += '<div class="setup-row"><label class="setup-label" for="db-sleep-normal-' + deviceId + '">祝福切换间隔时间</label><input class="setup-input" id="db-sleep-normal-' + deviceId + '" type="number" value="' + sleepNormal + '" min="1"><span style="font-size:12px;color:#999;margin-left:4px">正常电量(秒)</span></div>';
            html += '<div class="setup-row"><span class="setup-label"></span><input class="setup-input" id="db-sleep-low-' + deviceId + '" type="number" value="' + sleepLow + '" min="1"><span style="font-size:12px;color:#999;margin-left:4px">低电量(秒)</span></div>';
            var attimeMins  = (device.attime >= 0 && device.attime <= 1439) ? device.attime : 0;
            var timeStartMins = (device.time_start != null && device.time_start >= 0) ? device.time_start : 0;
            var timeEndMins   = (device.time_end   != null && device.time_end   >= 0) ? device.time_end   : 1439;
            html += '<div class="setup-row"><label class="setup-label" for="db-attime-' + deviceId + '">更新时间</label><input class="setup-input" id="db-attime-' + deviceId + '" type="time" value="' + minsToTime(attimeMins) + '"><span style="font-size:12px;color:#999;margin-left:4px">每天更新时刻</span></div>';
            html += '<div class="setup-row"><label class="setup-label" for="db-tstart-' + deviceId + '">祝福进行时间</label><input class="setup-input" id="db-tstart-' + deviceId + '" type="time" value="' + minsToTime(timeStartMins) + '"><span style="font-size:12px;color:#999;margin-left:4px">开始</span></div>';
            html += '<div class="setup-row"><span class="setup-label"></span><input class="setup-input" id="db-tend-' + deviceId + '" type="time" value="' + minsToTime(timeEndMins) + '"><span style="font-size:12px;color:#999;margin-left:4px">结束</span></div>';
            var volumeVal = (device.volume !== null && device.volume !== undefined) ? device.volume : 5;
            html += '<div class="setup-row"><label class="setup-label" for="db-volume-' + deviceId + '">音量</label><input class="setup-input" id="db-volume-' + deviceId + '" type="number" value="' + volumeVal + '" min="0" max="10"><span style="font-size:12px;color:#999;margin-left:4px">0-10</span></div>';
            html += '<button class="setup-save-btn" onclick="saveDbSetup(this,' + deviceId + ')">保存</button>';
            html += '</div>';
        }
    }
    panel.innerHTML = html;
    pendingCellLoads.forEach(function(item) {
        loadCellLocation(item.cell, item.targetId);
    });
}

function handleDbAction(btn, action) {
    var item     = btn.closest('.config-item');
    var key      = item.dataset.key;
    var deviceId = item.dataset.deviceId;
    var fd = new FormData();
    fd.append('action', 'db_entry');
    fd.append('device_id', deviceId);
    fd.append('key', key);
    fd.append('entry_action', action);
    btn.disabled = true;
    btn.textContent = '处理中…';
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var ns = data.newState;
                item.dataset.state = ns;
                var stateEl = item.querySelector('.config-state');
                stateEl.classList.remove('state-disabled', 'state-inactive', 'state-active');
                if (ns === 0) {
                    stateEl.textContent = '已禁用'; stateEl.classList.add('state-disabled');
                    btn.textContent = '开启'; btn.className = 'action-btn btn-enable';
                    btn.onclick = function() { handleDbAction(this, 'enable'); };
                } else if (ns === 1) {
                    stateEl.textContent = '未启用'; stateEl.classList.add('state-inactive');
                    btn.textContent = '禁用'; btn.className = 'action-btn btn-disable';
                    btn.onclick = function() { handleDbAction(this, 'disable'); };
                } else {
                    stateEl.textContent = '上传 ' + ns + ' 次'; stateEl.classList.add('state-active');
                    btn.textContent = '禁用'; btn.className = 'action-btn btn-disable';
                    btn.onclick = function() { handleDbAction(this, 'disable'); };
                }
                if (dbEntriesCache[deviceId]) {
                    var arr = dbEntriesCache[deviceId];
                    for (var i = 0; i < arr.length; i++) {
                        if (arr[i].key === key) { arr[i].state = ns; break; }
                    }
                }
                // 刷新图片（state 变化后文件名随之改变）
                var imgBase = item.dataset.imgBase || '';
                if (imgBase) {
                    var imgEl   = item.querySelector('.config-img');
                    var playBtn = item.querySelector('.db-play-btn');
                    if (imgEl) {
                        imgEl.style.display = '';
                        imgEl.src = imgBase + key + ns + '.png';
                        if (playBtn) {
                            playBtn.style.display = 'none';
                            playBtn.dataset.mp3 = imgBase + key + ns + '.mp3';
                        }
                    }
                }
            } else {
                alert('操作失败: ' + (data.error || '未知错误'));
            }
            btn.disabled = false;
        })
        .catch(function(e) {
            alert('请求失败: ' + e.message);
            btn.textContent = action === 'enable' ? '开启' : '禁用';
            btn.disabled = false;
        });
}

function saveDbSetup(btn, deviceId) {
    var sleepNormalVal = document.getElementById('db-sleep-normal-' + deviceId).value;
    var sleepLowVal    = document.getElementById('db-sleep-low-'    + deviceId).value;
    var attimeStr  = document.getElementById('db-attime-'  + deviceId).value;
    var tstartStr  = document.getElementById('db-tstart-'  + deviceId).value;
    var tendStr    = document.getElementById('db-tend-'    + deviceId).value;
    var volumeVal  = document.getElementById('db-volume-'  + deviceId).value;
    function timeTomins(s) { var p = (s||'0:0').split(':'); return parseInt(p[0]||0)*60+parseInt(p[1]||0); }
    var atMins     = timeTomins(attimeStr);
    var tStartMins = timeTomins(tstartStr);
    var tEndMins   = timeTomins(tendStr);
    var fd = new FormData();
    fd.append('action', 'db_setup');
    fd.append('device_id', deviceId);
    fd.append('sleep_normal', sleepNormalVal);
    fd.append('sleep_low', sleepLowVal);
    fd.append('attime', atMins);
    fd.append('time_start', tStartMins);
    fd.append('time_end', tEndMins);
    fd.append('volume', volumeVal);
    btn.disabled = true;
    btn.textContent = '保存中…';
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (dbDevicesCache) {
                    for (var i = 0; i < dbDevicesCache.length; i++) {
                        if (dbDevicesCache[i].id == deviceId) {
                            dbDevicesCache[i].sleep = parseInt(sleepNormalVal);
                            dbDevicesCache[i].sleep_low = parseInt(sleepLowVal);
                            dbDevicesCache[i].attime = atMins;
                            dbDevicesCache[i].time_start = tStartMins;
                            dbDevicesCache[i].time_end = tEndMins;
                            dbDevicesCache[i].volume = parseInt(volumeVal);
                            break;
                        }
                    }
                }
                btn.textContent = '已保存';
                setTimeout(function() { btn.textContent = '保存'; btn.disabled = false; }, 1500);
            } else {
                alert('保存失败');
                btn.textContent = '保存';
                btn.disabled = false;
            }
        })
        .catch(function(e) {
            alert('请求失败: ' + e.message);
            btn.textContent = '保存';
            btn.disabled = false;
        });
}
</script>

</div><!-- #dir-view -->

<div id="db-view" style="display:none">
    <div class="breadcrumb" id="db-breadcrumb" style="display:none"></div>
    <div id="db-devices-list"></div>
</div>

</body>
</html>

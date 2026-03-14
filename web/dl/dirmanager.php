<?php
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
    </style>
</head>
<body>

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
            echo '<div class="config-key">' . htmlspecialchars($key) . '</div>';
            echo '<div class="config-footer">';
            echo '<div class="config-state ' . $stateClass . '">' . $stateText . '</div>';
            echo '<button class="action-btn ' . $actionClass . '" onclick="handleAction(this, \'' . $actionType . '\')">' . $actionText . '</button>';
            echo '</div>';
            echo '</div>';
        }
        
        echo '</div>';
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
</script>

</body>
</html>

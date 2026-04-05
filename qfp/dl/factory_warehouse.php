<?php
session_start();

require_once __DIR__ . '/db.php';

$pdo = getDb();

function redirectWarehouseSelf(): void {
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function currentFactoryUser(): ?array {
    if (empty($_SESSION['factory_user'])) {
        return null;
    }
    return $_SESSION['factory_user'];
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function factoryStatusLabel($status): string {
    $map = [
        0 => '新注册',
        1 => '设备测试阶段',
        2 => '合格待转库',
        3 => '转出待接收',
        4 => '返回工厂中',
        5 => '已入售前库',
        6 => '已发代理商',
        7 => '代理商已入库',
        8 => '已发经销商',
        9 => '经销商已入库',
    ];
    $status = is_numeric($status) ? intval($status) : $status;
    return $map[$status] ?? (string) $status;
}

function requireFactoryLogin(): array {
    $user = currentFactoryUser();
    if (!$user) {
        header('Location: factory_manager.php');
        exit;
    }
    return $user;
}

$user = requireFactoryLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'logout') {
        unset($_SESSION['factory_user']);
        redirectWarehouseSelf();
    }
}

// AJAX API
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxAction = $_GET['ajax'];

    if ($ajaxAction === 'load_detail') {
        $deviceId = intval($_GET['device_id'] ?? 0);
        if ($deviceId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        // load test results
        $stmt = $pdo->prepare('SELECT test_index, result, note FROM factory_test_results WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $rows = $stmt->fetchAll();
        $results = [];
        foreach ($rows as $row) {
            $results[(int) $row['test_index']] = [
                'result' => $row['result'],
                'note' => $row['note'],
            ];
        }
        // load photos
        $stmt2 = $pdo->prepare('SELECT month_year, mac_b62 FROM devices WHERE id = ? LIMIT 1');
        $stmt2->execute([$deviceId]);
        $dev = $stmt2->fetch();
        $photos = ['parts' => [], 'sim' => [], 'family' => []];
        if ($dev) {
            $macDir = __DIR__ . DIRECTORY_SEPARATOR . $dev['month_year'] . DIRECTORY_SEPARATOR . $dev['mac_b62'];
            if (is_dir($macDir)) {
                foreach (scandir($macDir) as $f) {
                    if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $f)) continue;
                    $relPath = $dev['month_year'] . '/' . $dev['mac_b62'] . '/' . $f;
                    foreach (['parts', 'sim', 'family'] as $type) {
                        if (strpos($f, $type . '_') === 0) {
                            $photos[$type][] = $relPath;
                            break;
                        }
                    }
                }
            }
        }
        echo json_encode(['ok' => true, 'results' => $results, 'photos' => $photos]);
        exit;
    }

    if ($ajaxAction === 'revert_testing') {
        $input = json_decode(file_get_contents('php://input'), true);
        $deviceId = intval($input['device_id'] ?? 0);
        if ($deviceId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE devices SET factory_status = 1 WHERE id = ? AND factory_status = 2');
        $stmt->execute([$deviceId]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => '状态更新失败']);
        }
        exit;
    }

    if ($ajaxAction === 'transfer_to_warehouse') {
        $input = json_decode(file_get_contents('php://input'), true);
        $deviceId = intval($input['device_id'] ?? 0);
        if ($deviceId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE devices SET factory_status = 3 WHERE id = ? AND factory_status = 2');
        $stmt->execute([$deviceId]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => '状态更新失败，设备可能已不在合格待转库状态']);
        }
        exit;
    }

    echo json_encode(['error' => '未知操作']);
    exit;
}

$viewTransferred = isset($_GET['view']) && $_GET['view'] === 'transferred';

if ($viewTransferred) {
    $stmt = $pdo->prepare(
        'SELECT d.id, d.month_year, d.mac_b62, d.registered_at, d.getlist_at, d.factory_status,
                n.note AS stage_note, n.updated_at AS stage_note_updated_at
         FROM devices d
         LEFT JOIN factory_stage_notes n ON n.device_id = d.id AND n.stage = d.factory_status
         WHERE d.factory_status >= 3
         ORDER BY COALESCE(d.getlist_at, 0) DESC, d.registered_at DESC, d.id DESC'
    );
    $stmt->execute();
} else {
    $stmt = $pdo->prepare(
        'SELECT d.id, d.month_year, d.mac_b62, d.registered_at, d.getlist_at, d.factory_status,
                n.note AS stage_note, n.updated_at AS stage_note_updated_at
         FROM devices d
         LEFT JOIN factory_stage_notes n ON n.device_id = d.id AND n.stage = d.factory_status
         WHERE d.factory_status = ?
         ORDER BY COALESCE(d.getlist_at, 0) DESC, d.registered_at DESC, d.id DESC'
    );
    $stmt->execute([2]);
}
$devices = $stmt->fetchAll();
$pageTitle = $viewTransferred ? '已转出设备' : '合格待转库';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($pageTitle); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "PingFang SC", sans-serif;
            background:
                radial-gradient(circle at top right, rgba(99, 102, 241, 0.12), transparent 24%),
                radial-gradient(circle at bottom left, rgba(59, 130, 246, 0.10), transparent 22%),
                linear-gradient(135deg, #eef0f7 0%, #edf3f8 52%, #f1f4f7 100%);
            color: #1d2939;
        }
        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 40px 20px 56px;
        }
        .hero {
            margin-bottom: 24px;
        }
        .hero h1 {
            margin: 0 0 10px;
            font-size: 34px;
            letter-spacing: 1px;
        }
        .hero p {
            margin: 0;
            color: #667085;
            font-size: 15px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 22px;
        }
        .topbar h2 {
            margin: 0;
            font-size: 24px;
        }
        .topbar p {
            margin: 4px 0 0;
            color: #667085;
            font-size: 14px;
        }
        .stage-switcher {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }
        .stage-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid #d0d9e5;
            background: rgba(255, 255, 255, 0.84);
            color: #344054;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }
        .stage-link.active {
            border-color: #4f46e5;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            box-shadow: 0 10px 24px rgba(79, 70, 229, 0.22);
        }
        .btn {
            border: none;
            border-radius: 14px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-secondary {
            background: #eef2f7;
            color: #344054;
        }
        .panel {
            background: rgba(243, 247, 251, 0.92);
            border: 1px solid rgba(129, 146, 173, 0.24);
            border-radius: 22px;
            box-shadow: 0 16px 42px rgba(42, 65, 108, 0.12);
            backdrop-filter: blur(10px);
        }
        .card { padding: 22px; }
        .device-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 5px;
        }
        .device-tile {
            padding: 18px;
            border: 1px solid #d7e1ec;
            border-radius: 18px;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbfd 100%);
            box-shadow: 0 8px 20px rgba(31, 41, 55, 0.06);
            cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .device-tile:hover {
            border-color: #a5b4fc;
            box-shadow: 0 12px 28px rgba(79, 70, 229, 0.10);
        }
        .device-tile-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }
        .device-title {
            margin: 0;
            font-size: 18px;
            line-height: 1.35;
            word-break: break-all;
        }
        .device-subtitle {
            margin: 6px 0 0;
            color: #667085;
            font-size: 12px;
        }
        .device-meta {
            width: 100%;
            display: grid;
            gap: 10px;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #edf1f7;
        }
        .meta-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .meta-label {
            color: #667085;
            font-size: 12px;
            flex: 0 0 84px;
        }
        .meta-value {
            text-align: right;
            font-size: 14px;
            color: #1d2939;
            word-break: break-all;
        }
        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: #ede9fe;
            color: #4f46e5;
            font-size: 12px;
            font-weight: 600;
        }
        .note-box {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #f6f9fc;
            border: 1px solid #e2e8f0;
        }
        .note-box strong {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            color: #475467;
        }
        .note-text {
            font-size: 13px;
            color: #1d2939;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .note-time {
            margin-top: 8px;
            font-size: 12px;
            color: #667085;
        }
        .empty {
            padding: 30px;
            text-align: center;
            color: #667085;
            border: 1px dashed #d0d5dd;
            border-radius: 16px;
            background: #fcfcfd;
        }
        .device-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            padding: 0 8px;
            border-radius: 999px;
            background: #4f46e5;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            margin-left: 8px;
        }
        .fullscreen-modal {
            position: fixed;
            inset: 0;
            display: none;
            background: rgba(8, 15, 28, 0.58);
            z-index: 1200;
        }
        .fullscreen-modal.active {
            display: block;
        }
        .fullscreen-sheet {
            position: absolute;
            inset: 0;
            overflow-y: auto;
            background:
                radial-gradient(circle at top right, rgba(99, 102, 241, 0.14), transparent 24%),
                linear-gradient(180deg, #eef0f7 0%, #f8fbfd 100%);
        }
        .fullscreen-inner {
            max-width: 800px;
            margin: 0 auto;
            min-height: 100%;
            padding: 20px 16px 28px;
        }
        .fullscreen-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }
        .fullscreen-title {
            margin: 0;
            font-size: 24px;
            line-height: 1.35;
            word-break: break-all;
        }
        .fullscreen-subtitle {
            margin: 6px 0 0;
            color: #667085;
            font-size: 14px;
        }
        .detail-section {
            margin-bottom: 20px;
        }
        .detail-section-title {
            font-size: 16px;
            font-weight: 700;
            color: #334155;
            margin: 0 0 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .detail-photo-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .detail-photo-grid img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #d0d9e5;
            cursor: pointer;
        }
        .detail-photo-grid img:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .detail-photo-empty {
            color: #94a3b8;
            font-size: 13px;
        }
        .test-result-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #edf1f7;
            font-size: 14px;
        }
        .test-result-item:last-child {
            border-bottom: none;
        }
        .test-result-name {
            flex: 1;
            font-weight: 600;
            color: #1d2939;
        }
        .test-result-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .test-result-badge.ok {
            background: #d1fae5;
            color: #065f46;
        }
        .test-result-badge.problem {
            background: #fee2e2;
            color: #991b1b;
        }
        .test-result-badge.none {
            background: #f1f5f9;
            color: #94a3b8;
        }
        .test-result-note {
            font-size: 12px;
            color: #dc2626;
            margin-left: 4px;
        }
        .detail-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        .btn-close-detail {
            flex: 1;
            background: #ffffff;
            color: #0f172a;
            border: 1px solid #d5dde7;
        }
        .btn-revert {
            flex: 1;
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: #fff;
        }
        .btn-revert:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-transfer {
            flex: 1;
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff;
        }
        .btn-transfer:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        @media (max-width: 800px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
            .page {
                padding-left: 14px;
                padding-right: 14px;
            }
            .card {
                padding: 16px;
            }
            .device-grid {
                grid-template-columns: 1fr;
                gap: 5px;
            }
            .device-tile {
                padding: 16px;
                border-radius: 16px;
            }
            .meta-row {
                flex-direction: column;
                gap: 4px;
            }
            .meta-label,
            .meta-value {
                flex: none;
                text-align: left;
            }
            .fullscreen-inner {
                padding: 14px 12px 20px;
            }
            .fullscreen-topbar {
                flex-direction: column;
                align-items: flex-start;
            }
            .fullscreen-title {
                font-size: 20px;
            }
            .detail-actions {
                flex-direction: column;
            }
            .detail-photo-grid img {
                width: 80px;
                height: 80px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <h1>祝福牌工厂后台</h1>
        <p><?php echo $viewTransferred ? '当前页面显示已转出的设备列表。' : '当前页面显示已通过测试、等待转库的设备列表。'; ?></p>
    </div>

    <div class="topbar">
        <div>
            <h2><?php echo h($pageTitle); ?> <span class="device-count"><?php echo count($devices); ?></span></h2>
            <p>当前登录：<?php echo h($user['display_name']); ?>（<?php echo h($user['username']); ?>）</p>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="logout">
            <button class="btn btn-secondary" type="submit">退出登录</button>
        </form>
    </div>

    <div class="stage-switcher">
        <a class="stage-link" href="factory_manager.php">设备刚注册阶段</a>
        <a class="stage-link" href="factory_testing.php">设备测试阶段</a>
        <a class="stage-link<?php echo !$viewTransferred ? ' active' : ''; ?>" href="factory_warehouse.php">合格待转库</a>
        <a class="stage-link<?php echo $viewTransferred ? ' active' : ''; ?>" href="factory_warehouse.php?view=transferred">已转出</a>
    </div>

    <div class="panel card">
        <?php if (!$devices): ?>
            <div class="empty"><?php echo $viewTransferred ? '当前没有已转出的设备。' : '当前没有处于"合格待转库"状态的设备。'; ?></div>
        <?php else: ?>
            <div class="device-grid">
                <?php foreach ($devices as $device): ?>
                    <article class="device-tile js-open-detail"
                        data-device-id="<?php echo (int) $device['id']; ?>"
                        data-device-code="<?php echo h($device['month_year'] . $device['mac_b62']); ?>"
                    >
                        <div class="device-tile-header">
                            <div>
                                <h3 class="device-title"><?php echo h($device['month_year'] . $device['mac_b62']); ?></h3>
                                <p class="device-subtitle">数据库ID：<?php echo (int) $device['id']; ?></p>
                            </div>
                            <span class="badge"><?php echo h(factoryStatusLabel($device['factory_status'])); ?></span>
                        </div>
                        <div class="device-meta">
                            <div class="meta-row">
                                <div class="meta-label">最后请求</div>
                                <div class="meta-value"><?php echo !empty($device['getlist_at']) ? h(date('Y-m-d H:i:s', (int) $device['getlist_at'])) : '-'; ?></div>
                            </div>
                            <div class="meta-row">
                                <div class="meta-label">注册时间</div>
                                <div class="meta-value"><?php echo h(date('Y-m-d H:i:s', (int) $device['registered_at'])); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($device['stage_note'])): ?>
                            <div class="note-box">
                                <strong>阶段备注</strong>
                                <div class="note-text"><?php echo nl2br(h($device['stage_note'])); ?></div>
                                <div class="note-time">更新时间：<?php echo !empty($device['stage_note_updated_at']) ? h(date('Y-m-d H:i:s', (int) $device['stage_note_updated_at'])) : '-'; ?></div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="fullscreen-modal" id="detailModal">
    <div class="fullscreen-sheet">
        <div class="fullscreen-inner">
            <div class="fullscreen-topbar">
                <div>
                    <h3 class="fullscreen-title" id="detailTitle">设备详情</h3>
                    <p class="fullscreen-subtitle" id="detailSubtitle">测试结果与照片记录</p>
                </div>
            </div>

            <div class="detail-section">
                <h4 class="detail-section-title">📷 所有配件照片</h4>
                <div class="detail-photo-grid" id="photosPartsGrid"></div>
            </div>

            <div class="detail-section">
                <h4 class="detail-section-title">🔧 测试结果</h4>
                <div id="testResultsList"></div>
            </div>

            <div class="detail-section">
                <h4 class="detail-section-title">📷 SIM卡号照片</h4>
                <div class="detail-photo-grid" id="photosSimGrid"></div>
            </div>

            <div class="detail-section">
                <h4 class="detail-section-title">📷 包装前全家福</h4>
                <div class="detail-photo-grid" id="photosFamilyGrid"></div>
            </div>

            <div class="detail-actions">
                <button class="btn btn-close-detail" type="button" id="closeDetailBtn">关闭</button>
                <button class="btn btn-revert" type="button" id="revertTestingBtn">返回测试状态</button>
                <?php if (!$viewTransferred): ?>
                <button class="btn btn-transfer" type="button" id="transferBtn">转给成品库房</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    const TEST_NAMES = {
        1: '太阳能充电',
        2: '屏幕',
        3: '网络信号',
        4: '声音',
        5: '电池电压',
        6: '装壳',
        7: '装壳后屏显声音网络',
        8: '制作二维码吊牌',
        9: '出厂包装'
    };

    const detailModal = document.getElementById('detailModal');
    const detailTitle = document.getElementById('detailTitle');
    const detailSubtitle = document.getElementById('detailSubtitle');
    const closeDetailBtn = document.getElementById('closeDetailBtn');
    const revertTestingBtn = document.getElementById('revertTestingBtn');
    const testResultsList = document.getElementById('testResultsList');
    const photosPartsGrid = document.getElementById('photosPartsGrid');
    const photosSimGrid = document.getElementById('photosSimGrid');
    const photosFamilyGrid = document.getElementById('photosFamilyGrid');
    let currentDeviceId = null;

    function openDetail(deviceId, deviceCode) {
        currentDeviceId = deviceId;
        detailTitle.textContent = '设备详情：' + deviceCode;
        detailSubtitle.textContent = '设备ID ' + deviceId;
        detailModal.classList.add('active');
        document.body.style.overflow = 'hidden';
        revertTestingBtn.disabled = false;
        revertTestingBtn.textContent = '返回测试状态';
        loadDetail(deviceId);
    }

    function closeDetail() {
        detailModal.classList.remove('active');
        document.body.style.overflow = '';
        currentDeviceId = null;
    }

    function renderPhotos(container, photos) {
        container.innerHTML = '';
        if (!photos || !photos.length) {
            container.innerHTML = '<span class="detail-photo-empty">暂无照片</span>';
            return;
        }
        photos.forEach(p => {
            const img = document.createElement('img');
            img.src = p;
            img.loading = 'lazy';
            img.addEventListener('click', () => window.open(p, '_blank'));
            container.appendChild(img);
        });
    }

    function renderTestResults(results) {
        testResultsList.innerHTML = '';
        for (let i = 1; i <= 9; i++) {
            const info = results[i] || {};
            const item = document.createElement('div');
            item.className = 'test-result-item';

            const name = document.createElement('span');
            name.className = 'test-result-name';
            name.textContent = i + '. ' + (TEST_NAMES[i] || '测试项' + i);
            item.appendChild(name);

            if (info.note) {
                const noteSpan = document.createElement('span');
                noteSpan.className = 'test-result-note';
                noteSpan.textContent = info.note;
                item.appendChild(noteSpan);
            }

            const badge = document.createElement('span');
            badge.className = 'test-result-badge';
            if (info.result === 'ok') {
                badge.classList.add('ok');
                badge.textContent = 'OK';
            } else if (info.result === 'problem') {
                badge.classList.add('problem');
                badge.textContent = '问题';
            } else {
                badge.classList.add('none');
                badge.textContent = '未测';
            }
            item.appendChild(badge);

            testResultsList.appendChild(item);
        }
    }

    async function loadDetail(deviceId) {
        testResultsList.innerHTML = '<span style="color:#94a3b8">加载中...</span>';
        photosPartsGrid.innerHTML = '';
        photosSimGrid.innerHTML = '';
        photosFamilyGrid.innerHTML = '';
        try {
            const resp = await fetch('factory_warehouse.php?ajax=load_detail&device_id=' + encodeURIComponent(deviceId));
            const data = await resp.json();
            if (data.ok) {
                renderTestResults(data.results || {});
                renderPhotos(photosPartsGrid, data.photos.parts);
                renderPhotos(photosSimGrid, data.photos.sim);
                renderPhotos(photosFamilyGrid, data.photos.family);
            }
        } catch (e) {
            testResultsList.innerHTML = '<span style="color:#dc2626">加载失败</span>';
        }
    }

    document.querySelectorAll('.js-open-detail').forEach(tile => {
        tile.addEventListener('click', () => {
            openDetail(tile.dataset.deviceId, tile.dataset.deviceCode);
        });
    });

    closeDetailBtn.addEventListener('click', closeDetail);

    detailModal.addEventListener('click', (e) => {
        if (e.target === detailModal) closeDetail();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && detailModal.classList.contains('active')) closeDetail();
    });

    const transferBtn = document.getElementById('transferBtn');
    if (transferBtn) {
        transferBtn.addEventListener('click', async () => {
            if (!currentDeviceId) return;
            if (!confirm('确认将该设备转给成品库房？')) return;
            transferBtn.disabled = true;
            transferBtn.textContent = '处理中...';
            try {
                const resp = await fetch('factory_warehouse.php?ajax=transfer_to_warehouse', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ device_id: parseInt(currentDeviceId) })
                });
                const data = await resp.json();
                if (data.ok) {
                    alert('已成功转给成品库房。');
                    closeDetail();
                    location.reload();
                } else {
                    alert('操作失败：' + (data.error || '未知错误'));
                    transferBtn.disabled = false;
                    transferBtn.textContent = '转给成品库房';
                }
            } catch (e) {
                alert('请求失败，请重试。');
                transferBtn.disabled = false;
                transferBtn.textContent = '转给成品库房';
            }
        });
    }

    revertTestingBtn.addEventListener('click', async () => {
        if (!currentDeviceId) return;
        if (!confirm('确认将该设备退回到测试阶段？')) return;
        revertTestingBtn.disabled = true;
        revertTestingBtn.textContent = '处理中...';
        try {
            const resp = await fetch('factory_warehouse.php?ajax=revert_testing', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ device_id: parseInt(currentDeviceId) })
            });
            const data = await resp.json();
            if (data.ok) {
                alert('已退回测试阶段。');
                closeDetail();
                location.reload();
            } else {
                alert('操作失败：' + (data.error || '未知错误'));
                revertTestingBtn.disabled = false;
                revertTestingBtn.textContent = '返回测试状态';
            }
        } catch (e) {
            alert('请求失败，请重试。');
            revertTestingBtn.disabled = false;
            revertTestingBtn.textContent = '返回测试状态';
        }
    });
</script>
</body>
</html>

<?php
session_start();

require_once __DIR__ . '/db.php';

$pdo = getDb();

function redirectTestingSelf(): void {
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
        redirectTestingSelf();
    }
}

// AJAX API
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxAction = $_GET['ajax'];

    if ($ajaxAction === 'load_results') {
        $deviceId = intval($_GET['device_id'] ?? 0);
        if ($deviceId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
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
        echo json_encode(['ok' => true, 'results' => $results]);
        exit;
    }

    if ($ajaxAction === 'save_result') {
        $input = json_decode(file_get_contents('php://input'), true);
        $deviceId = intval($input['device_id'] ?? 0);
        $testIndex = intval($input['test_index'] ?? 0);
        $result = trim((string) ($input['result'] ?? ''));
        if ($deviceId <= 0 || $testIndex <= 0 || !in_array($result, ['ok', 'problem', ''], true)) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $now = time();
        $stmt = $pdo->prepare(
            'INSERT INTO factory_test_results (device_id, test_index, result, updated_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(device_id, test_index) DO UPDATE SET result = excluded.result, updated_at = excluded.updated_at'
        );
        $stmt->execute([$deviceId, $testIndex, $result, $now]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($ajaxAction === 'save_note') {
        $input = json_decode(file_get_contents('php://input'), true);
        $deviceId = intval($input['device_id'] ?? 0);
        $testIndex = intval($input['test_index'] ?? 0);
        $note = trim((string) ($input['note'] ?? ''));
        if ($deviceId <= 0 || $testIndex <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $now = time();
        $stmt = $pdo->prepare(
            'INSERT INTO factory_test_results (device_id, test_index, note, updated_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(device_id, test_index) DO UPDATE SET note = excluded.note, updated_at = excluded.updated_at'
        );
        $stmt->execute([$deviceId, $testIndex, $note, $now]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($ajaxAction === 'upload_photo') {
        $deviceId = intval($_POST['device_id'] ?? 0);
        $photoType = trim((string) ($_POST['photo_type'] ?? ''));
        $allowedTypes = ['parts', 'sim', 'family'];
        if ($deviceId <= 0 || !in_array($photoType, $allowedTypes, true)) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => '未收到照片']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT month_year, mac_b62 FROM devices WHERE id = ? LIMIT 1');
        $stmt->execute([$deviceId]);
        $dev = $stmt->fetch();
        if (!$dev) {
            echo json_encode(['error' => '设备不存在']);
            exit;
        }
        $macDir = __DIR__ . DIRECTORY_SEPARATOR . $dev['month_year'] . DIRECTORY_SEPARATOR . $dev['mac_b62'];
        if (!is_dir($macDir)) {
            mkdir($macDir, 0777, true);
        }
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        if ($ext === '') $ext = 'jpg';
        $filename = $photoType . '_' . date('Ymd_His') . '.' . $ext;
        $dest = $macDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
            echo json_encode(['error' => '保存照片失败']);
            exit;
        }
        echo json_encode(['ok' => true, 'filename' => $filename]);
        exit;
    }

    if ($ajaxAction === 'list_photos') {
        $deviceId = intval($_GET['device_id'] ?? 0);
        $photoType = trim((string) ($_GET['photo_type'] ?? ''));
        if ($deviceId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT month_year, mac_b62 FROM devices WHERE id = ? LIMIT 1');
        $stmt->execute([$deviceId]);
        $dev = $stmt->fetch();
        if (!$dev) {
            echo json_encode(['error' => '设备不存在']);
            exit;
        }
        $macDir = __DIR__ . DIRECTORY_SEPARATOR . $dev['month_year'] . DIRECTORY_SEPARATOR . $dev['mac_b62'];
        $photos = [];
        if (is_dir($macDir)) {
            foreach (scandir($macDir) as $f) {
                if ($photoType && strpos($f, $photoType . '_') !== 0) continue;
                if (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $f)) {
                    $photos[] = $dev['month_year'] . '/' . $dev['mac_b62'] . '/' . $f;
                }
            }
        }
        echo json_encode(['ok' => true, 'photos' => $photos]);
        exit;
    }

    if ($ajaxAction === 'advance_pass') {
        $input = json_decode(file_get_contents('php://input'), true);
        $deviceId = intval($input['device_id'] ?? 0);
        if ($deviceId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE devices SET factory_status = 2 WHERE id = ? AND factory_status = 1');
        $stmt->execute([$deviceId]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => '状态更新失败，设备可能不在测试阶段']);
        }
        exit;
    }

    echo json_encode(['error' => '未知操作']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT d.id, d.month_year, d.mac_b62, d.registered_at, d.getlist_at, d.factory_status,
            n.note AS stage_note, n.updated_at AS stage_note_updated_at
     FROM devices d
     LEFT JOIN factory_stage_notes n ON n.device_id = d.id AND n.stage = d.factory_status
     WHERE d.factory_status = ?
     ORDER BY COALESCE(d.getlist_at, 0) DESC, d.registered_at DESC, d.id DESC'
);
$stmt->execute([1]);
$devices = $stmt->fetchAll();

$deviceLinksMap = [];
if ($devices) {
    $deviceIds = array_map(static fn($device) => (int) $device['id'], $devices);
    $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
    $entryStmt = $pdo->prepare("SELECT device_id, key FROM entries WHERE device_id IN ($placeholders) ORDER BY id ASC");
    $entryStmt->execute($deviceIds);
    foreach ($entryStmt->fetchAll() as $entry) {
        $deviceId = (int) $entry['device_id'];
        if (!isset($deviceLinksMap[$deviceId])) {
            $deviceLinksMap[$deviceId] = [];
        }
        foreach ($devices as $device) {
            if ((int) $device['id'] === $deviceId) {
                $deviceLinksMap[$deviceId][] = '/qfp/?' . $device['month_year'] . $device['mac_b62'] . $entry['key'];
                break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>设备测试阶段</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "PingFang SC", sans-serif;
            background:
                radial-gradient(circle at top right, rgba(20, 184, 166, 0.12), transparent 24%),
                radial-gradient(circle at bottom left, rgba(59, 130, 246, 0.10), transparent 22%),
                linear-gradient(135deg, #e8f1f1 0%, #edf4f6 52%, #f1f4f7 100%);
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
            border-color: #0f766e;
            background: linear-gradient(135deg, #0f766e, #14b8a6);
            color: #fff;
            box-shadow: 0 10px 24px rgba(15, 118, 110, 0.22);
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
        .card {
            padding: 22px;
        }
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
        }
        .device-tile:hover {
            border-color: #8dd3cb;
            box-shadow: 0 12px 28px rgba(15, 118, 110, 0.10);
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
            background: #e7fffb;
            color: #0f766e;
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
                radial-gradient(circle at top right, rgba(20, 184, 166, 0.14), transparent 24%),
                linear-gradient(180deg, #eef7f6 0%, #f8fbfd 100%);
        }
        .fullscreen-inner {
            max-width: 1180px;
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
        .test-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 10px;
        }
        .test-tile {
            min-height: 144px;
            padding: 18px 16px;
            border-radius: 22px;
            border: 1px solid #d6e4e2;
            background: linear-gradient(180deg, #ffffff 0%, #f7fbfb 100%);
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .test-order {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e7fffb;
            color: #0f766e;
            font-size: 14px;
            font-weight: 700;
        }
        .test-name {
            margin: 18px 0 10px;
            font-size: 22px;
            color: #0f172a;
            line-height: 1.3;
        }
        .test-desc {
            font-size: 13px;
            line-height: 1.6;
            color: #667085;
        }
        .test-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            margin-top: 16px;
        }
        .btn-copy {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: #fff;
        }
        .btn-close-fullscreen {
            background: #ffffff;
            color: #0f172a;
            border: 1px solid #d5dde7;
        }
        .copy-status {
            margin-top: 10px;
            min-height: 20px;
            color: #0f766e;
            font-size: 13px;
        }
        .test-result-btns {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }
        .test-result-btn {
            flex: 1;
            padding: 8px 0;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #f1f5f9;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .test-result-btn:hover {
            border-color: #94a3b8;
        }
        .test-result-btn.active-ok {
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff;
            border-color: #059669;
            box-shadow: 0 4px 14px rgba(5, 150, 105, 0.3);
        }
        .test-result-btn.active-problem {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: #fff;
            border-color: #dc2626;
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.3);
        }
        .test-desc-link {
            cursor: pointer;
            text-decoration: underline;
            text-decoration-style: dashed;
            text-underline-offset: 3px;
        }
        .test-desc-link:hover {
            color: #0f766e;
        }
        .note-edit-modal {
            position: fixed;
            inset: 0;
            display: none;
            background: rgba(8, 15, 28, 0.58);
            z-index: 1300;
            align-items: center;
            justify-content: center;
        }
        .note-edit-modal.active {
            display: flex;
        }
        .note-edit-box {
            width: 90%;
            max-width: 480px;
            background: #fff;
            border-radius: 20px;
            padding: 28px 24px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.18);
        }
        .note-edit-box h4 {
            margin: 0 0 14px;
            font-size: 18px;
        }
        .note-edit-box textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border: 1px solid #d0d9e5;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
        }
        .note-edit-box textarea:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15,118,110,0.12);
        }
        .note-edit-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 14px;
        }
        .btn-save-note {
            background: linear-gradient(135deg, #0f766e, #14b8a6);
            color: #fff;
        }
        .test-note-indicator {
            margin-top: 8px;
            font-size: 12px;
            color: #94a3b8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .test-note-indicator.has-note {
            color: #dc2626;
        }
        .photo-tile {
            min-height: 100px;
            padding: 18px 16px;
            border-radius: 22px;
            border: 2px dashed #94a3b8;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .photo-tile:hover {
            border-color: #0f766e;
            background: linear-gradient(180deg, #f0fdfa 0%, #e7fffb 100%);
        }
        .photo-tile-icon {
            font-size: 28px;
        }
        .photo-tile-label {
            font-size: 15px;
            font-weight: 600;
            color: #334155;
        }
        .photo-tile-count {
            font-size: 12px;
            color: #64748b;
        }
        .photo-tile input[type=file] {
            display: none;
        }
        .photo-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }
        .photo-preview img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #d0d9e5;
        }
        .btn-advance-pass {
            display: block;
            width: 100%;
            margin-top: 20px;
            padding: 16px;
            border: none;
            border-radius: 18px;
            background: linear-gradient(135deg, #0f766e, #14b8a6);
            color: #fff;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(15, 118, 110, 0.22);
            transition: all 0.15s;
        }
        .btn-advance-pass:hover {
            box-shadow: 0 14px 32px rgba(15, 118, 110, 0.32);
            transform: translateY(-1px);
        }
        .btn-advance-pass:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
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
            .fullscreen-inner {
                padding: 14px 12px 20px;
            }
            .fullscreen-topbar {
                align-items: flex-start;
                flex-direction: column;
            }
            .fullscreen-title {
                font-size: 20px;
            }
            .test-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            .test-tile {
                min-height: 118px;
                border-radius: 18px;
            }
            .test-name {
                font-size: 20px;
                margin-top: 14px;
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
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <h1>祝福牌工厂后台</h1>
        <p>当前页面显示已经进入设备测试阶段的设备，便于后续测试处理与跟踪。</p>
    </div>

    <div class="topbar">
        <div>
            <h2>设备测试阶段</h2>
            <p>当前登录：<?php echo h($user['display_name']); ?>（<?php echo h($user['username']); ?>）</p>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="logout">
            <button class="btn btn-secondary" type="submit">退出登录</button>
        </form>
    </div>

    <div class="stage-switcher">
        <a class="stage-link" href="factory_manager.php">设备刚注册阶段</a>
        <a class="stage-link active" href="factory_testing.php">设备测试阶段</a>
        <a class="stage-link" href="factory_warehouse.php">合格待转库</a>
    </div>

    <div class="panel card">
        <?php if (!$devices): ?>
            <div class="empty">当前没有处于“设备测试阶段”的设备。</div>
        <?php else: ?>
            <div class="device-grid">
                <?php foreach ($devices as $device): ?>
                    <article
                        class="device-tile js-open-test-panel"
                        data-device-code="<?php echo h($device['month_year'] . $device['mac_b62']); ?>"
                        data-device-id="<?php echo (int) $device['id']; ?>"
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
<div class="fullscreen-modal" id="testPanelModal" aria-hidden="true">
    <div class="fullscreen-sheet">
        <div class="fullscreen-inner">
            <div class="fullscreen-topbar">
                <div>
                    <h3 class="fullscreen-title" id="testPanelTitle">设备测试项</h3>
                    <p class="fullscreen-subtitle" id="testPanelSubtitle">按顺序完成每个测试瓷片。</p>
                </div>
                <button class="btn btn-close-fullscreen" type="button" id="closeTestPanelBtn">关闭</button>
            </div>
            <div class="test-grid">
                <div class="photo-tile" data-photo-type="parts" id="photoTileParts">
                    <div class="photo-tile-icon">📷</div>
                    <div class="photo-tile-label">所有配件照片</div>
                    <div class="photo-tile-count" data-photo-count="parts">点击拍照上传</div>
                    <input type="file" accept="image/*" capture="environment" data-photo-input="parts">
                    <div class="photo-preview" data-photo-preview="parts"></div>
                </div>
                <article class="test-tile" data-test-index="1">
                    <span class="test-order">1</span>
                    <div>
                        <div class="test-name">太阳能充电</div>
                        <div class="test-desc test-desc-link" data-test-index="1">检查太阳能板接入后的充电状态与指示表现。</div>
                        <div class="test-note-indicator" data-note-display="1"></div>
                    </div>
                    <div class="test-result-btns">
                        <button class="test-result-btn js-result-btn" data-test-index="1" data-result="ok" type="button">OK</button>
                        <button class="test-result-btn js-result-btn" data-test-index="1" data-result="problem" type="button">问题</button>
                    </div>
                </article>
                <article class="test-tile" data-test-index="2">
                    <span class="test-order">2</span>
                    <div>
                        <div class="test-name">屏幕</div>
                        <div class="test-desc test-desc-link" data-test-index="2">确认屏幕显示内容、刷新效果和异常闪屏情况。</div>
                        <div class="test-note-indicator" data-note-display="2"></div>
                    </div>
                    <div class="test-result-btns">
                        <button class="test-result-btn js-result-btn" data-test-index="2" data-result="ok" type="button">OK</button>
                        <button class="test-result-btn js-result-btn" data-test-index="2" data-result="problem" type="button">问题</button>
                    </div>
                </article>
                <article class="test-tile" data-test-index="3">
                    <span class="test-order">3</span>
                    <div>
                        <div class="test-name">网络信号</div>
                        <div class="test-desc test-desc-link" data-test-index="3">检查联网状态、信号强度和请求返回是否正常。</div>
                        <div class="test-note-indicator" data-note-display="3"></div>
                    </div>
                    <div class="test-result-btns">
                        <button class="test-result-btn js-result-btn" data-test-index="3" data-result="ok" type="button">OK</button>
                        <button class="test-result-btn js-result-btn" data-test-index="3" data-result="problem" type="button">问题</button>
                    </div>
                </article>
                <article class="test-tile" data-test-index="4">
                    <span class="test-order">4</span>
                    <div>
                        <div class="test-name">声音</div>
                        <div class="test-desc test-desc-link" data-test-index="4">验证喇叭播放、音量和杂音情况。</div>
                        <div class="test-note-indicator" data-note-display="4"></div>
                    </div>
                    <div class="test-result-btns">
                        <button class="test-result-btn js-result-btn" data-test-index="4" data-result="ok" type="button">OK</button>
                        <button class="test-result-btn js-result-btn" data-test-index="4" data-result="problem" type="button">问题</button>
                    </div>
                </article>
                <article class="test-tile" data-test-index="5">
                    <span class="test-order">5</span>
                    <div>
                        <div class="test-name">电池电压</div>
                        <div class="test-desc test-desc-link" data-test-index="5">记录当前供电电压，确认是否处于可交付范围。</div>
                        <div class="test-note-indicator" data-note-display="5"></div>
                    </div>
                    <div class="test-result-btns">
                        <button class="test-result-btn js-result-btn" data-test-index="5" data-result="ok" type="button">OK</button>
                        <button class="test-result-btn js-result-btn" data-test-index="5" data-result="problem" type="button">问题</button>
                    </div>
                </article>
                <article class="test-tile" data-test-index="6">
                    <span class="test-order">6</span>
                    <div>
                        <div class="test-name">装壳</div>
                        <div class="test-desc test-desc-link" data-test-index="6">完成结构装配，检查卡扣、螺丝和外观完整性。</div>
                        <div class="test-note-indicator" data-note-display="6"></div>
                    </div>
                    <div class="test-result-btns">
                        <button class="test-result-btn js-result-btn" data-test-index="6" data-result="ok" type="button">OK</button>
                        <button class="test-result-btn js-result-btn" data-test-index="6" data-result="problem" type="button">问题</button>
                    </div>
                </article>
                <article class="test-tile" data-test-index="7">
                    <span class="test-order">7</span>
                    <div>
                        <div class="test-name">装壳后屏显声音网络</div>
                        <div class="test-desc test-desc-link" data-test-index="7">装壳后复测屏幕、声音与网络，确认装配未引入异常。</div>
                        <div class="test-note-indicator" data-note-display="7"></div>
                    </div>
                    <div class="test-result-btns">
                        <button class="test-result-btn js-result-btn" data-test-index="7" data-result="ok" type="button">OK</button>
                        <button class="test-result-btn js-result-btn" data-test-index="7" data-result="problem" type="button">问题</button>
                    </div>
                </article>
                <article class="test-tile" data-test-index="8">
                    <span class="test-order">8</span>
                    <div>
                        <div class="test-name">制作二维码吊牌</div>
                        <div class="test-desc test-desc-link" data-test-index="8">生成并核对当前设备全部排位连接，用于制作二维码吊牌。</div>
                        <div class="test-note-indicator" data-note-display="8"></div>
                    </div>
                    <div class="test-actions">
                        <button class="btn btn-copy" type="button" id="copyAllLinksBtn">复制全部连接</button>
                        <div class="copy-status" id="copyStatus"></div>
                    </div>
                    <div class="test-result-btns">
                        <button class="test-result-btn js-result-btn" data-test-index="8" data-result="ok" type="button">OK</button>
                        <button class="test-result-btn js-result-btn" data-test-index="8" data-result="problem" type="button">问题</button>
                    </div>
                </article>
                <article class="test-tile" data-test-index="9">
                    <span class="test-order">9</span>
                    <div>
                        <div class="test-name">出厂包装</div>
                        <div class="test-desc test-desc-link" data-test-index="9">完成最终清洁、附件核对和出厂包装确认。</div>
                        <div class="test-note-indicator" data-note-display="9"></div>
                    </div>
                    <div class="test-result-btns">
                        <button class="test-result-btn js-result-btn" data-test-index="9" data-result="ok" type="button">OK</button>
                        <button class="test-result-btn js-result-btn" data-test-index="9" data-result="problem" type="button">问题</button>
                    </div>
                </article>
                <div class="photo-tile" data-photo-type="sim" id="photoTileSim">
                    <div class="photo-tile-icon">📷</div>
                    <div class="photo-tile-label">SIM卡号照片</div>
                    <div class="photo-tile-count" data-photo-count="sim">点击拍照上传</div>
                    <input type="file" accept="image/*" capture="environment" data-photo-input="sim">
                    <div class="photo-preview" data-photo-preview="sim"></div>
                </div>
                <div class="photo-tile" data-photo-type="family" id="photoTileFamily">
                    <div class="photo-tile-icon">📷</div>
                    <div class="photo-tile-label">包装前全家福</div>
                    <div class="photo-tile-count" data-photo-count="family">点击拍照上传</div>
                    <input type="file" accept="image/*" capture="environment" data-photo-input="family">
                    <div class="photo-preview" data-photo-preview="family"></div>
                </div>
            </div>
            <button class="btn-advance-pass" type="button" id="advancePassBtn">✅ 确认合格，转入待转库阶段</button>
        </div>
    </div>
</div>
<div class="note-edit-modal" id="noteEditModal">
    <div class="note-edit-box">
        <h4 id="noteEditTitle">编辑问题描述</h4>
        <textarea id="noteEditTextarea" placeholder="输入问题描述..."></textarea>
        <div class="note-edit-actions">
            <button class="btn btn-secondary" type="button" id="noteEditCancelBtn">取消</button>
            <button class="btn btn-save-note" type="button" id="noteEditSaveBtn">保存</button>
        </div>
    </div>
</div>
<script>
    const deviceLinksMap = <?php echo json_encode($deviceLinksMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const testPanelModal = document.getElementById('testPanelModal');
    const testPanelTitle = document.getElementById('testPanelTitle');
    const testPanelSubtitle = document.getElementById('testPanelSubtitle');
    const closeTestPanelBtn = document.getElementById('closeTestPanelBtn');
    const copyAllLinksBtn = document.getElementById('copyAllLinksBtn');
    const copyStatus = document.getElementById('copyStatus');
    let selectedLinks = [];
    let currentDeviceId = null;
    let testResultsCache = {};

    async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }

    function openTestPanel(deviceCode, deviceId) {
        currentDeviceId = deviceId;
        testPanelTitle.textContent = '设备测试项：' + deviceCode;
        testPanelSubtitle.textContent = '设备ID ' + deviceId + '，请按顺序完成下列测试瓷片。';
        selectedLinks = (deviceLinksMap[deviceId] || []).map((link) => window.location.origin + link);
        copyStatus.textContent = selectedLinks.length ? ('当前设备共 ' + selectedLinks.length + ' 个排位连接') : '当前设备没有可复制的排位连接';
        testPanelModal.classList.add('active');
        testPanelModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        loadTestResults(deviceId);
        loadAllPhotos(deviceId);
        advancePassBtn.disabled = false;
        advancePassBtn.textContent = '✅ 确认合格，转入待转库阶段';
    }

    function resetTestUI() {
        document.querySelectorAll('.js-result-btn').forEach(btn => {
            btn.classList.remove('active-ok', 'active-problem');
        });
        document.querySelectorAll('[data-note-display]').forEach(el => {
            el.textContent = '';
            el.classList.remove('has-note');
        });
    }

    async function loadTestResults(deviceId) {
        resetTestUI();
        try {
            const resp = await fetch('factory_testing.php?ajax=load_results&device_id=' + encodeURIComponent(deviceId));
            const data = await resp.json();
            if (data.ok && data.results) {
                testResultsCache = data.results;
                for (const [idx, info] of Object.entries(data.results)) {
                    if (info.result) {
                        applyResultUI(parseInt(idx), info.result);
                    }
                    if (info.note) {
                        applyNoteUI(parseInt(idx), info.note);
                    }
                }
            }
        } catch (e) {
            console.error('加载测试结果失败', e);
        }
    }

    function applyResultUI(testIndex, result) {
        document.querySelectorAll('.js-result-btn[data-test-index="' + testIndex + '"]').forEach(btn => {
            btn.classList.remove('active-ok', 'active-problem');
            if (btn.dataset.result === result) {
                btn.classList.add(result === 'ok' ? 'active-ok' : 'active-problem');
            }
        });
    }

    function applyNoteUI(testIndex, note) {
        const el = document.querySelector('[data-note-display="' + testIndex + '"]');
        if (el) {
            if (note) {
                el.textContent = '备注：' + note;
                el.classList.add('has-note');
            } else {
                el.textContent = '';
                el.classList.remove('has-note');
            }
        }
    }

    async function saveTestResult(deviceId, testIndex, result) {
        try {
            await fetch('factory_testing.php?ajax=save_result', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ device_id: parseInt(deviceId), test_index: testIndex, result: result })
            });
        } catch (e) {
            console.error('保存测试结果失败', e);
        }
    }

    async function saveTestNote(deviceId, testIndex, note) {
        try {
            await fetch('factory_testing.php?ajax=save_note', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ device_id: parseInt(deviceId), test_index: testIndex, note: note })
            });
        } catch (e) {
            console.error('保存备注失败', e);
        }
    }

    // OK / 问题 按钮点击
    document.querySelectorAll('.js-result-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (!currentDeviceId) return;
            const testIndex = parseInt(btn.dataset.testIndex);
            const result = btn.dataset.result;
            const isActive = btn.classList.contains('active-ok') || btn.classList.contains('active-problem');
            if (isActive) {
                applyResultUI(testIndex, '');
                saveTestResult(currentDeviceId, testIndex, '');
            } else {
                applyResultUI(testIndex, result);
                saveTestResult(currentDeviceId, testIndex, result);
            }
        });
    });

    // 点击描述文字弹出编辑备注
    const noteEditModal = document.getElementById('noteEditModal');
    const noteEditTitle = document.getElementById('noteEditTitle');
    const noteEditTextarea = document.getElementById('noteEditTextarea');
    const noteEditSaveBtn = document.getElementById('noteEditSaveBtn');
    const noteEditCancelBtn = document.getElementById('noteEditCancelBtn');
    let editingTestIndex = null;

    document.querySelectorAll('.test-desc-link').forEach(el => {
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            if (!currentDeviceId) return;
            const testIndex = parseInt(el.dataset.testIndex);
            editingTestIndex = testIndex;
            const existing = (testResultsCache[testIndex] && testResultsCache[testIndex].note) || '';
            noteEditTextarea.value = existing;
            const testName = el.closest('.test-tile').querySelector('.test-name').textContent;
            noteEditTitle.textContent = '编辑问题描述 — ' + testName;
            noteEditModal.classList.add('active');
        });
    });

    noteEditCancelBtn.addEventListener('click', () => {
        noteEditModal.classList.remove('active');
        editingTestIndex = null;
    });

    noteEditSaveBtn.addEventListener('click', async () => {
        if (editingTestIndex === null || !currentDeviceId) return;
        const note = noteEditTextarea.value.trim();
        await saveTestNote(currentDeviceId, editingTestIndex, note);
        if (!testResultsCache[editingTestIndex]) testResultsCache[editingTestIndex] = {};
        testResultsCache[editingTestIndex].note = note;
        applyNoteUI(editingTestIndex, note);
        noteEditModal.classList.remove('active');
        editingTestIndex = null;
    });

    noteEditModal.addEventListener('click', (e) => {
        if (e.target === noteEditModal) {
            noteEditModal.classList.remove('active');
            editingTestIndex = null;
        }
    });

    function closeTestPanel() {
        testPanelModal.classList.remove('active');
        testPanelModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        copyStatus.textContent = '';
    }

    document.querySelectorAll('.js-open-test-panel').forEach((tile) => {
        tile.addEventListener('click', () => {
            openTestPanel(tile.dataset.deviceCode || '', tile.dataset.deviceId || '');
        });
    });

    closeTestPanelBtn.addEventListener('click', closeTestPanel);
    copyAllLinksBtn.addEventListener('click', async () => {
        if (!selectedLinks.length) {
            copyStatus.textContent = '当前设备没有可复制的排位连接';
            return;
        }

        try {
            await copyText(selectedLinks.join('\n'));
            copyStatus.textContent = '已复制 ' + selectedLinks.length + ' 条连接';
        } catch (error) {
            copyStatus.textContent = '复制失败，请重试';
        }
    });
    testPanelModal.addEventListener('click', (event) => {
        if (event.target === testPanelModal) {
            closeTestPanel();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && testPanelModal.classList.contains('active')) {
            closeTestPanel();
        }
    });

    // 照片上传逻辑
    document.querySelectorAll('.photo-tile').forEach(tile => {
        tile.addEventListener('click', (e) => {
            if (e.target.tagName === 'IMG') return;
            const input = tile.querySelector('input[type=file]');
            if (input) input.click();
        });
        const input = tile.querySelector('input[type=file]');
        if (input) {
            input.addEventListener('change', async () => {
                if (!currentDeviceId || !input.files.length) return;
                const photoType = tile.dataset.photoType;
                const file = input.files[0];
                const form = new FormData();
                form.append('device_id', currentDeviceId);
                form.append('photo_type', photoType);
                form.append('photo', file);
                const countEl = tile.querySelector('[data-photo-count]');
                if (countEl) countEl.textContent = '上传中...';
                try {
                    const resp = await fetch('factory_testing.php?ajax=upload_photo', { method: 'POST', body: form });
                    const data = await resp.json();
                    if (data.ok) {
                        if (countEl) countEl.textContent = '上传成功';
                        loadPhotos(currentDeviceId, photoType);
                    } else {
                        if (countEl) countEl.textContent = '上传失败: ' + (data.error || '');
                    }
                } catch (e) {
                    if (countEl) countEl.textContent = '上传失败';
                }
                input.value = '';
            });
        }
    });

    async function loadPhotos(deviceId, photoType) {
        const previewEl = document.querySelector('[data-photo-preview="' + photoType + '"]');
        const countEl = document.querySelector('[data-photo-count="' + photoType + '"]');
        if (!previewEl) return;
        try {
            const resp = await fetch('factory_testing.php?ajax=list_photos&device_id=' + encodeURIComponent(deviceId) + '&photo_type=' + encodeURIComponent(photoType));
            const data = await resp.json();
            if (data.ok) {
                previewEl.innerHTML = '';
                data.photos.forEach(p => {
                    const img = document.createElement('img');
                    img.src = p;
                    img.loading = 'lazy';
                    previewEl.appendChild(img);
                });
                if (countEl && data.photos.length) {
                    countEl.textContent = '已上传 ' + data.photos.length + ' 张';
                } else if (countEl) {
                    countEl.textContent = '点击拍照上传';
                }
            }
        } catch (e) {}
    }

    function loadAllPhotos(deviceId) {
        ['parts', 'sim', 'family'].forEach(type => loadPhotos(deviceId, type));
    }

    // 确认合格按钮
    const advancePassBtn = document.getElementById('advancePassBtn');
    advancePassBtn.addEventListener('click', async () => {
        if (!currentDeviceId) return;
        if (!confirm('确认该设备测试合格？将转入待转库阶段。')) return;
        advancePassBtn.disabled = true;
        advancePassBtn.textContent = '处理中...';
        try {
            const resp = await fetch('factory_testing.php?ajax=advance_pass', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ device_id: parseInt(currentDeviceId) })
            });
            const data = await resp.json();
            if (data.ok) {
                alert('已确认合格，设备已转入检测阶段。');
                closeTestPanel();
                location.reload();
            } else {
                alert('操作失败：' + (data.error || '未知错误'));
                advancePassBtn.disabled = false;
                advancePassBtn.textContent = '✅ 确认合格，转入待转库阶段';
            }
        } catch (e) {
            alert('请求失败，请重试。');
            advancePassBtn.disabled = false;
            advancePassBtn.textContent = '✅ 确认合格，转入待转库阶段';
        }
    });
</script>
</body>
</html>
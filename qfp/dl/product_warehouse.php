<?php
session_start();

require_once __DIR__ . '/db.php';

$pdo = getDb();

function redirectProductSelf(): void {
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function currentAccountUser(): ?array {
    if (empty($_SESSION['account_user'])) {
        return null;
    }
    return $_SESSION['account_user'];
}

function hp(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function productStatusLabel($status): string {
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

$user = currentAccountUser();
if (!$user) {
    header('Location: account_manager.php');
    exit;
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

    if ($ajaxAction === 'return_device') {
        $input = json_decode(file_get_contents('php://input'), true);
        $deviceId = intval($input['device_id'] ?? 0);
        if ($deviceId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE devices SET factory_status = 4 WHERE id = ? AND factory_status = 3');
        $stmt->execute([$deviceId]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => '状态更新失败，设备可能已不在待接收状态']);
        }
        exit;
    }

    if ($ajaxAction === 'accept_device') {
        $input = json_decode(file_get_contents('php://input'), true);
        $deviceId = intval($input['device_id'] ?? 0);
        if ($deviceId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE devices SET factory_status = 5 WHERE id = ? AND factory_status = 3');
        $stmt->execute([$deviceId]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => '状态更新失败，设备可能已不在待接收状态']);
        }
        exit;
    }

    if ($ajaxAction === 'list_agents') {
        $stmt = $pdo->query("SELECT id, display_name, username, recipient_name, recipient_address, recipient_phone FROM accounts WHERE role = 'agent' AND status = 1 ORDER BY display_name");
        $agents = $stmt->fetchAll();
        echo json_encode(['ok' => true, 'agents' => $agents]);
        exit;
    }

    if ($ajaxAction === 'list_stocked_devices') {
        $stmt = $pdo->query(
            "SELECT d.id, d.month_year || '/' || d.mac_b62 AS device_code
             FROM devices d
             WHERE d.factory_status = 5
             ORDER BY d.registered_at DESC, d.id DESC"
        );
        $devices = $stmt->fetchAll();
        echo json_encode(['ok' => true, 'devices' => $devices]);
        exit;
    }

    if ($ajaxAction === 'ship_parcel') {
        $input = json_decode(file_get_contents('php://input'), true);
        $agentId = intval($input['agent_id'] ?? 0);
        $recipientName = trim($input['recipient_name'] ?? '');
        $recipientAddress = trim($input['recipient_address'] ?? '');
        $recipientPhone = trim($input['recipient_phone'] ?? '');
        $trackingNumber = trim($input['tracking_number'] ?? '');
        $remarkText = trim($input['remark'] ?? '');
        $deviceIds = $input['device_ids'] ?? [];

        if ($agentId <= 0) {
            echo json_encode(['error' => '请选择代理商']);
            exit;
        }
        if (!is_array($deviceIds) || count($deviceIds) === 0) {
            echo json_encode(['error' => '请至少选择一个设备']);
            exit;
        }
        $deviceIds = array_map('intval', $deviceIds);
        $deviceIds = array_filter($deviceIds, fn($id) => $id > 0);
        if (count($deviceIds) === 0) {
            echo json_encode(['error' => '设备ID无效']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $now = time();
            $stmt = $pdo->prepare(
                'INSERT INTO parcels (agent_id, recipient_name, recipient_address, recipient_phone, tracking_number, remark, status, created_at, shipped_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->execute([$agentId, $recipientName, $recipientAddress, $recipientPhone, $trackingNumber, $remarkText, $now, $now]);
            $parcelId = (int) $pdo->lastInsertId();

            $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM devices WHERE id IN ($placeholders) AND factory_status = 5");
            $checkStmt->execute($deviceIds);
            if ((int) $checkStmt->fetchColumn() !== count($deviceIds)) {
                $pdo->rollBack();
                echo json_encode(['error' => '部分设备不在已入库状态，请刷新后重试']);
                exit;
            }

            $insertPd = $pdo->prepare('INSERT INTO parcel_devices (parcel_id, device_id) VALUES (?, ?)');
            foreach ($deviceIds as $did) {
                $insertPd->execute([$parcelId, $did]);
            }

            $updateStmt = $pdo->prepare("UPDATE devices SET factory_status = 6 WHERE id IN ($placeholders) AND factory_status = 5");
            $updateStmt->execute($deviceIds);

            $pdo->commit();
            echo json_encode(['ok' => true, 'parcel_id' => $parcelId]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => '创建包裹失败：' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => '未知操作']);
    exit;
}

$tab = $_GET['tab'] ?? 'pending';
$validTabs = ['pending', 'returned', 'stocked', 'agent', 'shipped'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'pending';
}

$tabConfig = [
    'pending'  => ['label' => '待接收',     'status' => 3,    'empty' => '当前没有待接收的设备。'],
    'returned' => ['label' => '返回工厂',   'status' => 4,    'empty' => '当前没有返回工厂中的设备。'],
    'stocked'  => ['label' => '已入库',     'status' => 5,    'empty' => '当前没有已入库的设备。'],
    'agent'    => ['label' => '已发代理商', 'status' => 6,    'empty' => '暂未开放此功能。'],
    'shipped'  => ['label' => '已发出',     'status' => 7,    'empty' => '当前没有已发出的设备。'],
];

$currentTab = $tabConfig[$tab];
$pageTitle = '货品管理 - ' . $currentTab['label'];

$devices = [];
$parcels = [];
if ($tab === 'agent') {
    // Load parcels grouped with their devices
    $parcelsStmt = $pdo->query(
        'SELECT p.*, a.display_name AS agent_name
         FROM parcels p
         LEFT JOIN accounts a ON a.id = p.agent_id
         ORDER BY p.shipped_at DESC, p.created_at DESC, p.id DESC'
    );
    $parcels = $parcelsStmt->fetchAll();
    foreach ($parcels as &$parcel) {
        $pdStmt = $pdo->prepare(
            'SELECT d.id, d.month_year, d.mac_b62, d.registered_at, d.getlist_at
             FROM parcel_devices pd
             JOIN devices d ON d.id = pd.device_id
             WHERE pd.parcel_id = ?
             ORDER BY d.id'
        );
        $pdStmt->execute([(int) $parcel['id']]);
        $parcel['devices'] = $pdStmt->fetchAll();
    }
    unset($parcel);
} else {
    $stmt = $pdo->prepare(
        'SELECT d.id, d.month_year, d.mac_b62, d.registered_at, d.getlist_at, d.factory_status,
                n.note AS stage_note, n.updated_at AS stage_note_updated_at
         FROM devices d
         LEFT JOIN factory_stage_notes n ON n.device_id = d.id AND n.stage = d.factory_status
         WHERE d.factory_status = ?
         ORDER BY COALESCE(d.getlist_at, 0) DESC, d.registered_at DESC, d.id DESC'
    );
    $stmt->execute([$currentTab['status']]);
    $devices = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo hp($pageTitle); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "PingFang SC", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(63, 137, 255, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(22, 193, 139, 0.16), transparent 24%),
                linear-gradient(135deg, #eef4ff 0%, #f9fbff 45%, #f5fff8 100%);
            color: #1e2a3a;
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
            color: #5a6780;
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
            color: #627188;
            font-size: 14px;
        }
        .topbar-actions {
            display: flex;
            gap: 10px;
            align-items: center;
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
            border-color: #3068ff;
            background: linear-gradient(135deg, #3068ff, #5a8cff);
            color: #fff;
            box-shadow: 0 10px 24px rgba(48, 104, 255, 0.22);
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
            background: #eef3ff;
            color: #34578c;
        }
        .btn-ghost {
            background: rgba(255, 255, 255, 0.82);
            color: #26446f;
            border: 1px solid #d7e0ef;
            text-decoration: none;
        }
        .panel {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(115, 137, 167, 0.18);
            border-radius: 22px;
            box-shadow: 0 18px 45px rgba(51, 83, 135, 0.12);
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
            box-shadow: 0 12px 28px rgba(48, 104, 255, 0.10);
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
            color: #627188;
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
            color: #627188;
            font-size: 12px;
            flex: 0 0 84px;
        }
        .meta-value {
            text-align: right;
            font-size: 14px;
            color: #1e2a3a;
            word-break: break-all;
        }
        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-returned { background: #fee2e2; color: #991b1b; }
        .badge-stocked { background: #d1fae5; color: #065f46; }
        .badge-agent { background: #ede9fe; color: #5b21b6; }
        .badge-shipped { background: #dbeafe; color: #1e40af; }
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
            color: #1e2a3a;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .note-time {
            margin-top: 8px;
            font-size: 12px;
            color: #627188;
        }
        .empty {
            padding: 30px;
            text-align: center;
            color: #627188;
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
            background: #3068ff;
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
                radial-gradient(circle at top right, rgba(63, 137, 255, 0.14), transparent 24%),
                linear-gradient(180deg, #eef4ff 0%, #f9fbff 100%);
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
            color: #627188;
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
            color: #1e2a3a;
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
        .btn-return {
            flex: 1;
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: #fff;
        }
        .btn-return:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-accept {
            flex: 1;
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff;
        }
        .btn-accept:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-primary {
            background: linear-gradient(135deg, #3068ff, #5a8cff);
            color: #fff;
        }
        .parcel-card {
            margin-bottom: 18px;
            padding: 20px;
            border: 1px solid #d7e1ec;
            border-radius: 18px;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbfd 100%);
            box-shadow: 0 8px 20px rgba(31, 41, 55, 0.06);
        }
        .parcel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #edf1f7;
        }
        .parcel-title {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
        }
        .parcel-meta {
            font-size: 13px;
            color: #627188;
            line-height: 1.8;
        }
        .parcel-devices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 8px;
            margin-top: 10px;
        }
        .parcel-device-item {
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            font-size: 13px;
            cursor: pointer;
        }
        .parcel-device-item:hover {
            border-color: #a5b4fc;
            background: #eef4ff;
        }
        .parcel-device-code {
            font-weight: 700;
            color: #1e2a3a;
            word-break: break-all;
        }
        .parcel-device-sub {
            color: #627188;
            font-size: 12px;
            margin-top: 4px;
        }
        .pick-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
            margin-bottom: 6px;
        }
        .pick-item.selected {
            border-color: #3068ff;
            background: #eef4ff;
        }
        .pick-radio {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pick-item.selected .pick-radio {
            border-color: #3068ff;
            background: #3068ff;
        }
        .pick-item.selected .pick-radio::after {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #fff;
        }
        .pick-info {
            flex: 1;
        }
        .pick-code {
            font-weight: 700;
            font-size: 14px;
            word-break: break-all;
        }
        .pick-sub {
            font-size: 12px;
            color: #627188;
            margin-top: 2px;
        }
        .agent-select-item {
            padding: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            cursor: pointer;
            margin-bottom: 8px;
            transition: border-color 0.15s, background 0.15s;
        }
        .agent-select-item:hover,
        .agent-select-item.selected {
            border-color: #3068ff;
            background: #eef4ff;
        }
        .agent-select-name {
            font-weight: 700;
            font-size: 15px;
        }
        .agent-select-sub {
            font-size: 12px;
            color: #627188;
            margin-top: 4px;
        }
        .form-field {
            margin-bottom: 14px;
        }
        .form-field label {
            display: block;
            font-size: 13px;
            color: #56637b;
            margin-bottom: 6px;
        }
        .form-field input,
        .form-field textarea {
            width: 100%;
            border: 1px solid #d7e0ef;
            background: #fff;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            color: #243248;
            outline: none;
        }
        .form-field input:focus,
        .form-field textarea:focus {
            border-color: #5f87ff;
            box-shadow: 0 0 0 3px rgba(95, 135, 255, 0.12);
        }
        .form-field textarea {
            min-height: 70px;
            resize: vertical;
        }
        .selected-agent-info {
            padding: 14px;
            border: 1px solid #d1fae5;
            border-radius: 14px;
            background: #ecfdf5;
            margin-bottom: 14px;
        }
        .selected-agent-info .agent-name {
            font-weight: 700;
            font-size: 15px;
            color: #065f46;
            margin-bottom: 8px;
        }
        .confirm-device-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 14px;
        }
        .confirm-device-item {
            padding: 8px 12px;
            border-bottom: 1px solid #edf1f7;
            font-size: 13px;
        }
        .confirm-device-item:last-child {
            border-bottom: none;
        }
        .stocked-toolbar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 14px;
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
        <h1>货品管理</h1>
        <p>管理成品库房中的设备，接收工厂转来的合格设备。</p>
    </div>

    <div class="topbar">
        <div>
            <h2><?php echo hp($currentTab['label']); ?> <span class="device-count"><?php echo count($devices); ?></span></h2>
            <p>当前登录：<?php echo hp($user['display_name']); ?>（<?php echo hp($user['username']); ?>）</p>
        </div>
        <div class="topbar-actions">
            <a class="btn btn-ghost" href="account_manager.php">返回管理中心</a>
        </div>
    </div>

    <div class="stage-switcher">
        <?php foreach ($tabConfig as $key => $cfg): ?>
            <a class="stage-link<?php echo $tab === $key ? ' active' : ''; ?>" href="product_warehouse.php?tab=<?php echo $key; ?>"><?php echo hp($cfg['label']); ?></a>
        <?php endforeach; ?>
    </div>

    <div class="panel card">
        <?php if ($tab === 'agent'): ?>
            <?php if (!$parcels): ?>
                <div class="empty">当前没有已发代理商的包裹。</div>
            <?php else: ?>
                <?php foreach ($parcels as $parcel): ?>
                    <div class="parcel-card">
                        <div class="parcel-header">
                            <div>
                                <h3 class="parcel-title">包裹 #<?php echo (int) $parcel['id']; ?> → <?php echo hp($parcel['agent_name'] ?? '未知代理商'); ?></h3>
                                <div class="parcel-meta">
                                    收件人：<?php echo hp($parcel['recipient_name']); ?>　
                                    电话：<?php echo hp($parcel['recipient_phone']); ?><br>
                                    地址：<?php echo hp($parcel['recipient_address']); ?><br>
                                    快递单号：<?php echo hp($parcel['tracking_number'] ?: '无'); ?>　
                                    <?php if ($parcel['remark']): ?>备注：<?php echo hp($parcel['remark']); ?><br><?php endif; ?>
                                    发货时间：<?php echo $parcel['shipped_at'] ? hp(date('Y-m-d H:i:s', (int) $parcel['shipped_at'])) : '-'; ?>
                                </div>
                            </div>
                            <span class="badge badge-agent">共 <?php echo count($parcel['devices']); ?> 台</span>
                        </div>
                        <div class="parcel-devices-grid">
                            <?php foreach ($parcel['devices'] as $pd): ?>
                                <div class="parcel-device-item js-open-detail"
                                    data-device-id="<?php echo (int) $pd['id']; ?>"
                                    data-device-code="<?php echo hp($pd['month_year'] . $pd['mac_b62']); ?>"
                                >
                                    <div class="parcel-device-code"><?php echo hp($pd['month_year'] . $pd['mac_b62']); ?></div>
                                    <div class="parcel-device-sub">ID：<?php echo (int) $pd['id']; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php elseif ($tab === 'stocked'): ?>
            <div class="stocked-toolbar">
                <button class="btn btn-primary" type="button" id="createParcelBtn">📦 创建包裹</button>
            </div>
            <?php if (!$devices): ?>
                <div class="empty"><?php echo hp($currentTab['empty']); ?></div>
            <?php else: ?>
            <div class="device-grid">
                <?php
                $badgeClass = [
                    'pending' => 'badge-pending',
                    'returned' => 'badge-returned',
                    'stocked' => 'badge-stocked',
                    'agent' => 'badge-agent',
                    'shipped' => 'badge-shipped',
                ][$tab] ?? 'badge-pending';
                ?>
                <?php foreach ($devices as $device): ?>
                    <article class="device-tile js-open-detail"
                        data-device-id="<?php echo (int) $device['id']; ?>"
                        data-device-code="<?php echo hp($device['month_year'] . $device['mac_b62']); ?>"
                    >
                        <div class="device-tile-header">
                            <div>
                                <h3 class="device-title"><?php echo hp($device['month_year'] . $device['mac_b62']); ?></h3>
                                <p class="device-subtitle">数据库ID：<?php echo (int) $device['id']; ?></p>
                            </div>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo hp(productStatusLabel($device['factory_status'])); ?></span>
                        </div>
                        <div class="device-meta">
                            <div class="meta-row">
                                <div class="meta-label">最后请求</div>
                                <div class="meta-value"><?php echo !empty($device['getlist_at']) ? hp(date('Y-m-d H:i:s', (int) $device['getlist_at'])) : '-'; ?></div>
                            </div>
                            <div class="meta-row">
                                <div class="meta-label">注册时间</div>
                                <div class="meta-value"><?php echo hp(date('Y-m-d H:i:s', (int) $device['registered_at'])); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($device['stage_note'])): ?>
                            <div class="note-box">
                                <strong>阶段备注</strong>
                                <div class="note-text"><?php echo nl2br(hp($device['stage_note'])); ?></div>
                                <div class="note-time">更新时间：<?php echo !empty($device['stage_note_updated_at']) ? hp(date('Y-m-d H:i:s', (int) $device['stage_note_updated_at'])) : '-'; ?></div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if (!$devices): ?>
                <div class="empty"><?php echo hp($currentTab['empty']); ?></div>
            <?php else: ?>
            <div class="device-grid">
                <?php
                $badgeClass = [
                    'pending' => 'badge-pending',
                    'returned' => 'badge-returned',
                    'stocked' => 'badge-stocked',
                    'agent' => 'badge-agent',
                    'shipped' => 'badge-shipped',
                ][$tab] ?? 'badge-pending';
                ?>
                <?php foreach ($devices as $device): ?>
                    <article class="device-tile js-open-detail"
                        data-device-id="<?php echo (int) $device['id']; ?>"
                        data-device-code="<?php echo hp($device['month_year'] . $device['mac_b62']); ?>"
                    >
                        <div class="device-tile-header">
                            <div>
                                <h3 class="device-title"><?php echo hp($device['month_year'] . $device['mac_b62']); ?></h3>
                                <p class="device-subtitle">数据库ID：<?php echo (int) $device['id']; ?></p>
                            </div>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo hp(productStatusLabel($device['factory_status'])); ?></span>
                        </div>
                        <div class="device-meta">
                            <div class="meta-row">
                                <div class="meta-label">最后请求</div>
                                <div class="meta-value"><?php echo !empty($device['getlist_at']) ? hp(date('Y-m-d H:i:s', (int) $device['getlist_at'])) : '-'; ?></div>
                            </div>
                            <div class="meta-row">
                                <div class="meta-label">注册时间</div>
                                <div class="meta-value"><?php echo hp(date('Y-m-d H:i:s', (int) $device['registered_at'])); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($device['stage_note'])): ?>
                            <div class="note-box">
                                <strong>阶段备注</strong>
                                <div class="note-text"><?php echo nl2br(hp($device['stage_note'])); ?></div>
                                <div class="note-time">更新时间：<?php echo !empty($device['stage_note_updated_at']) ? hp(date('Y-m-d H:i:s', (int) $device['stage_note_updated_at'])) : '-'; ?></div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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
                <?php if ($tab === 'pending'): ?>
                <button class="btn btn-return" type="button" id="returnDeviceBtn">返回设备</button>
                <button class="btn btn-accept" type="button" id="acceptDeviceBtn">接收设备</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Parcel Creation Modal -->
<div class="fullscreen-modal" id="parcelModal">
    <div class="fullscreen-sheet">
        <div class="fullscreen-inner">
            <div class="fullscreen-topbar">
                <div>
                    <h3 class="fullscreen-title">📦 创建包裹</h3>
                    <p class="fullscreen-subtitle">选择代理商，填写发货信息，拣货发出</p>
                </div>
            </div>

            <div class="form-field">
                <label>选择代理商</label>
                <button class="btn btn-ghost" type="button" id="selectAgentBtn" style="width:100%;text-align:left;">点击选择代理商...</button>
                <input type="hidden" id="parcelAgentId" value="">
            </div>

            <div id="agentInfoSection" style="display:none;">
                <div class="selected-agent-info" id="agentInfoDisplay"></div>

                <div class="form-field">
                    <label for="parcelRecipientName">收件人姓名</label>
                    <input type="text" id="parcelRecipientName">
                </div>
                <div class="form-field">
                    <label for="parcelRecipientAddress">收件人地址</label>
                    <input type="text" id="parcelRecipientAddress">
                </div>
                <div class="form-field">
                    <label for="parcelRecipientPhone">收件人手机</label>
                    <input type="text" id="parcelRecipientPhone">
                </div>
                <div class="form-field">
                    <label for="parcelTracking">快递单号</label>
                    <input type="text" id="parcelTracking" placeholder="可留空，发货后填写">
                </div>
                <div class="form-field">
                    <label for="parcelRemark">备注</label>
                    <textarea id="parcelRemark" placeholder="可选"></textarea>
                </div>

                <div id="pickedDevicesSummary" style="display:none;margin-bottom:14px;">
                    <label style="display:block;font-size:13px;color:#56637b;margin-bottom:6px;">已拣货设备</label>
                    <div id="pickedDevicesList" style="padding:10px 14px;border:1px solid #d1fae5;border-radius:12px;background:#ecfdf5;font-size:13px;"></div>
                </div>

                <div class="detail-actions">
                    <button class="btn btn-close-detail" type="button" id="closeParcelBtn">取消</button>
                    <button class="btn btn-primary" type="button" id="openPickBtn">🛒 拣货</button>
                    <button class="btn btn-accept" type="button" id="shipParcelBtn" style="display:none;">📤 发货</button>
                </div>
            </div>

            <div id="noAgentActions" class="detail-actions">
                <button class="btn btn-close-detail" type="button" id="closeParcelBtn2">取消</button>
            </div>
        </div>
    </div>
</div>

<!-- Agent Selection Modal -->
<div class="fullscreen-modal" id="agentSelectModal">
    <div class="fullscreen-sheet">
        <div class="fullscreen-inner">
            <div class="fullscreen-topbar">
                <div>
                    <h3 class="fullscreen-title">选择代理商</h3>
                </div>
                <button class="btn btn-ghost" type="button" id="closeAgentSelectBtn">返回</button>
            </div>
            <div id="agentSelectList">
                <span style="color:#94a3b8">加载中...</span>
            </div>
        </div>
    </div>
</div>

<!-- Pick Devices Modal -->
<div class="fullscreen-modal" id="pickModal">
    <div class="fullscreen-sheet">
        <div class="fullscreen-inner">
            <div class="fullscreen-topbar">
                <div>
                    <h3 class="fullscreen-title">🛒 拣货 - 选择已入库设备</h3>
                    <p class="fullscreen-subtitle" id="pickCount">已选 0 台</p>
                </div>
                <button class="btn btn-primary" type="button" id="confirmPickBtn">确定选择</button>
            </div>
            <div id="pickDeviceList">
                <span style="color:#94a3b8">加载中...</span>
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
            const resp = await fetch('product_warehouse.php?ajax=load_detail&device_id=' + encodeURIComponent(deviceId));
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

    const returnDeviceBtn = document.getElementById('returnDeviceBtn');
    if (returnDeviceBtn) {
        returnDeviceBtn.addEventListener('click', async () => {
            if (!currentDeviceId) return;
            if (!confirm('确认将该设备返回工厂？')) return;
            returnDeviceBtn.disabled = true;
            returnDeviceBtn.textContent = '处理中...';
            try {
                const resp = await fetch('product_warehouse.php?ajax=return_device', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ device_id: parseInt(currentDeviceId) })
                });
                const data = await resp.json();
                if (data.ok) {
                    alert('设备已标记为返回工厂。');
                    closeDetail();
                    location.reload();
                } else {
                    alert('操作失败：' + (data.error || '未知错误'));
                    returnDeviceBtn.disabled = false;
                    returnDeviceBtn.textContent = '返回设备';
                }
            } catch (e) {
                alert('请求失败，请重试。');
                returnDeviceBtn.disabled = false;
                returnDeviceBtn.textContent = '返回设备';
            }
        });
    }

    const acceptDeviceBtn = document.getElementById('acceptDeviceBtn');
    if (acceptDeviceBtn) {
        acceptDeviceBtn.addEventListener('click', async () => {
            if (!currentDeviceId) return;
            if (!confirm('确认接收该设备入库？')) return;
            acceptDeviceBtn.disabled = true;
            acceptDeviceBtn.textContent = '处理中...';
            try {
                const resp = await fetch('product_warehouse.php?ajax=accept_device', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ device_id: parseInt(currentDeviceId) })
                });
                const data = await resp.json();
                if (data.ok) {
                    alert('设备已成功接收入库。');
                    closeDetail();
                    location.reload();
                } else {
                    alert('操作失败：' + (data.error || '未知错误'));
                    acceptDeviceBtn.disabled = false;
                    acceptDeviceBtn.textContent = '接收设备';
                }
            } catch (e) {
                alert('请求失败，请重试。');
                acceptDeviceBtn.disabled = false;
                acceptDeviceBtn.textContent = '接收设备';
            }
        });
    }

    // ===================== Parcel workflow =====================
    const parcelModal = document.getElementById('parcelModal');
    const agentSelectModal = document.getElementById('agentSelectModal');
    const pickModal = document.getElementById('pickModal');

    let selectedAgent = null;
    let pickedDevices = []; // [{id, code}]
    let agentsList = null; // cache

    function openModal(el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
    function closeModal(el) { el.classList.remove('active'); if (!document.querySelector('.fullscreen-modal.active')) document.body.style.overflow = ''; }

    function resetParcelForm() {
        selectedAgent = null;
        pickedDevices = [];
        document.getElementById('parcelAgentId').value = '';
        document.getElementById('selectAgentBtn').textContent = '点击选择代理商...';
        document.getElementById('agentInfoSection').style.display = 'none';
        document.getElementById('noAgentActions').style.display = '';
        document.getElementById('parcelRecipientName').value = '';
        document.getElementById('parcelRecipientAddress').value = '';
        document.getElementById('parcelRecipientPhone').value = '';
        document.getElementById('parcelTracking').value = '';
        document.getElementById('parcelRemark').value = '';
        document.getElementById('pickedDevicesSummary').style.display = 'none';
        document.getElementById('pickedDevicesList').innerHTML = '';
        document.getElementById('shipParcelBtn').style.display = 'none';
        document.getElementById('openPickBtn').style.display = '';
    }

    // --- Create Parcel button ---
    const createParcelBtn = document.getElementById('createParcelBtn');
    if (createParcelBtn) {
        createParcelBtn.addEventListener('click', () => {
            resetParcelForm();
            openModal(parcelModal);
        });
    }

    // --- Close parcel modal ---
    document.getElementById('closeParcelBtn')?.addEventListener('click', () => closeModal(parcelModal));
    document.getElementById('closeParcelBtn2')?.addEventListener('click', () => closeModal(parcelModal));
    parcelModal?.addEventListener('click', (e) => { if (e.target === parcelModal) closeModal(parcelModal); });

    // --- Open agent selection ---
    document.getElementById('selectAgentBtn')?.addEventListener('click', async () => {
        openModal(agentSelectModal);
        const listEl = document.getElementById('agentSelectList');
        if (!agentsList) {
            listEl.innerHTML = '<span style="color:#94a3b8">加载中...</span>';
            try {
                const resp = await fetch('product_warehouse.php?ajax=list_agents');
                const data = await resp.json();
                if (data.ok) agentsList = data.agents;
                else { listEl.innerHTML = '<span style="color:#dc2626">' + (data.error || '加载失败') + '</span>'; return; }
            } catch (e) { listEl.innerHTML = '<span style="color:#dc2626">加载失败</span>'; return; }
        }
        renderAgentList(agentsList, listEl);
    });

    function renderAgentList(agents, container) {
        container.innerHTML = '';
        if (!agents || !agents.length) {
            container.innerHTML = '<span style="color:#94a3b8">暂无代理商</span>';
            return;
        }
        agents.forEach(a => {
            const div = document.createElement('div');
            div.className = 'agent-select-item' + (selectedAgent && selectedAgent.id == a.id ? ' selected' : '');
            div.innerHTML = '<strong>' + escHtml(a.display_name) + '</strong><span style="font-size:12px;color:#64748b;">' +
                (a.recipient_name ? ' · ' + escHtml(a.recipient_name) : '') +
                (a.recipient_phone ? ' · ' + escHtml(a.recipient_phone) : '') + '</span>';
            div.addEventListener('click', () => selectAgent(a));
            container.appendChild(div);
        });
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function selectAgent(agent) {
        selectedAgent = agent;
        document.getElementById('parcelAgentId').value = agent.id;
        document.getElementById('selectAgentBtn').textContent = agent.display_name;
        document.getElementById('agentInfoDisplay').innerHTML =
            '<strong>' + escHtml(agent.display_name) + '</strong>' +
            (agent.recipient_name ? '<br>收件人：' + escHtml(agent.recipient_name) : '') +
            (agent.recipient_address ? '<br>地址：' + escHtml(agent.recipient_address) : '') +
            (agent.recipient_phone ? '<br>电话：' + escHtml(agent.recipient_phone) : '');
        document.getElementById('parcelRecipientName').value = agent.recipient_name || '';
        document.getElementById('parcelRecipientAddress').value = agent.recipient_address || '';
        document.getElementById('parcelRecipientPhone').value = agent.recipient_phone || '';
        document.getElementById('agentInfoSection').style.display = '';
        document.getElementById('noAgentActions').style.display = 'none';
        closeModal(agentSelectModal);
    }

    // --- Close agent select modal ---
    document.getElementById('closeAgentSelectBtn')?.addEventListener('click', () => closeModal(agentSelectModal));
    agentSelectModal?.addEventListener('click', (e) => { if (e.target === agentSelectModal) closeModal(agentSelectModal); });

    // --- Open pick devices ---
    document.getElementById('openPickBtn')?.addEventListener('click', async () => {
        openModal(pickModal);
        const listEl = document.getElementById('pickDeviceList');
        listEl.innerHTML = '<span style="color:#94a3b8">加载中...</span>';
        try {
            const resp = await fetch('product_warehouse.php?ajax=list_stocked_devices');
            const data = await resp.json();
            if (data.ok) {
                renderPickList(data.devices, listEl);
            } else {
                listEl.innerHTML = '<span style="color:#dc2626">' + (data.error || '加载失败') + '</span>';
            }
        } catch (e) { listEl.innerHTML = '<span style="color:#dc2626">加载失败</span>'; }
    });

    function renderPickList(devices, container) {
        container.innerHTML = '';
        if (!devices || !devices.length) {
            container.innerHTML = '<span style="color:#94a3b8">暂无已入库设备</span>';
            return;
        }
        const pickedIds = new Set(pickedDevices.map(d => d.id));
        devices.forEach(d => {
            const item = document.createElement('div');
            item.className = 'pick-item' + (pickedIds.has(d.id) ? ' picked' : '');
            item.innerHTML = '<span class="pick-check">' + (pickedIds.has(d.id) ? '☑' : '☐') + '</span>' +
                '<span class="pick-code">' + escHtml(d.device_code) + '</span>';
            item.addEventListener('click', () => {
                if (pickedIds.has(d.id)) {
                    pickedIds.delete(d.id);
                    pickedDevices = pickedDevices.filter(pd => pd.id !== d.id);
                    item.classList.remove('picked');
                    item.querySelector('.pick-check').textContent = '☐';
                } else {
                    pickedIds.add(d.id);
                    pickedDevices.push({ id: d.id, code: d.device_code });
                    item.classList.add('picked');
                    item.querySelector('.pick-check').textContent = '☑';
                }
                document.getElementById('pickCount').textContent = '已选 ' + pickedDevices.length + ' 台';
            });
            container.appendChild(item);
        });
        document.getElementById('pickCount').textContent = '已选 ' + pickedDevices.length + ' 台';
    }

    // --- Confirm pick ---
    document.getElementById('confirmPickBtn')?.addEventListener('click', () => {
        closeModal(pickModal);
        updatePickedSummary();
    });

    pickModal?.addEventListener('click', (e) => { if (e.target === pickModal) { closeModal(pickModal); updatePickedSummary(); } });

    function updatePickedSummary() {
        const summaryEl = document.getElementById('pickedDevicesSummary');
        const listEl = document.getElementById('pickedDevicesList');
        const shipBtn = document.getElementById('shipParcelBtn');
        if (pickedDevices.length > 0) {
            summaryEl.style.display = '';
            listEl.innerHTML = pickedDevices.map(d => '<span style="display:inline-block;background:#fff;border:1px solid #d1fae5;border-radius:6px;padding:2px 8px;margin:2px;">'
                + escHtml(d.code) + '</span>').join(' ');
            shipBtn.style.display = '';
        } else {
            summaryEl.style.display = 'none';
            listEl.innerHTML = '';
            shipBtn.style.display = 'none';
        }
    }

    // --- Ship parcel ---
    document.getElementById('shipParcelBtn')?.addEventListener('click', async () => {
        if (!selectedAgent) { alert('请选择代理商'); return; }
        if (!pickedDevices.length) { alert('请拣货'); return; }
        if (!confirm('确认发货 ' + pickedDevices.length + ' 台设备给 ' + selectedAgent.display_name + ' ？')) return;

        const btn = document.getElementById('shipParcelBtn');
        btn.disabled = true;
        btn.textContent = '发货中...';
        try {
            const resp = await fetch('product_warehouse.php?ajax=ship_parcel', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    agent_id: parseInt(selectedAgent.id),
                    device_ids: pickedDevices.map(d => d.id),
                    recipient_name: document.getElementById('parcelRecipientName').value.trim(),
                    recipient_address: document.getElementById('parcelRecipientAddress').value.trim(),
                    recipient_phone: document.getElementById('parcelRecipientPhone').value.trim(),
                    tracking_number: document.getElementById('parcelTracking').value.trim(),
                    remark: document.getElementById('parcelRemark').value.trim()
                })
            });
            const data = await resp.json();
            if (data.ok) {
                alert('发货成功！包裹编号 #' + data.parcel_id);
                closeModal(parcelModal);
                location.reload();
            } else {
                alert('发货失败：' + (data.error || '未知错误'));
                btn.disabled = false;
                btn.textContent = '📤 发货';
            }
        } catch (e) {
            alert('请求失败，请重试。');
            btn.disabled = false;
            btn.textContent = '📤 发货';
        }
    });

    // --- Escape closes topmost modal ---
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (pickModal?.classList.contains('active')) { closeModal(pickModal); updatePickedSummary(); }
            else if (agentSelectModal?.classList.contains('active')) closeModal(agentSelectModal);
            else if (parcelModal?.classList.contains('active')) closeModal(parcelModal);
        }
    });
</script>
</body>
</html>

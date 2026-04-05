<?php
session_start();
require_once __DIR__ . '/db.php';

$pdo = getDb();

function ha(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$user = null;
if (!empty($_SESSION['account_user']) && $_SESSION['account_user']['role'] === 'agent') {
    $user = $_SESSION['account_user'];
}
if (!$user) {
    header('Location: account_manager.php');
    exit;
}

// POST 处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'logout') {
        unset($_SESSION['account_user']);
        header('Location: account_manager.php');
        exit;
    }
}

// AJAX API
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxAction = $_GET['ajax'];
    $agentId = (int) $user['id'];

    // 加载待收货包裹 (设备 factory_status=6)
    if ($ajaxAction === 'load_pending') {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.tracking_number, p.remark, p.created_at, p.shipped_at,
                    COUNT(pd.device_id) AS device_count
             FROM parcels p
             JOIN parcel_devices pd ON pd.parcel_id = p.id
             JOIN devices d ON d.id = pd.device_id AND d.factory_status = 6
             WHERE p.agent_id = ? AND p.dealer_id = 0
             GROUP BY p.id
             ORDER BY p.shipped_at DESC, p.id DESC'
        );
        $stmt->execute([$agentId]);
        echo json_encode(['ok' => true, 'parcels' => $stmt->fetchAll()]);
        exit;
    }

    // 加载已入库设备列表 (factory_status=7)
    if ($ajaxAction === 'load_stocked') {
        $stmt = $pdo->prepare(
            'SELECT d.id, d.month_year, d.mac_b62, d.mac_hex, d.registered_at, d.factory_status
             FROM devices d
             JOIN parcel_devices pd ON pd.device_id = d.id
             JOIN parcels p ON p.id = pd.parcel_id AND p.agent_id = ?
             WHERE d.factory_status = 7
             ORDER BY d.id DESC'
        );
        $stmt->execute([$agentId]);
        echo json_encode(['ok' => true, 'devices' => $stmt->fetchAll()]);
        exit;
    }

    // 加载已发经销商的包裹
    if ($ajaxAction === 'load_shipped') {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.tracking_number, p.remark, p.created_at, p.shipped_at,
                    a.display_name AS dealer_name,
                    COUNT(pd.device_id) AS device_count
             FROM parcels p
             JOIN parcel_devices pd ON pd.parcel_id = p.id
             LEFT JOIN accounts a ON a.id = p.dealer_id
             WHERE p.agent_id = ? AND p.dealer_id > 0
             GROUP BY p.id
             ORDER BY p.shipped_at DESC, p.id DESC'
        );
        $stmt->execute([$agentId]);
        echo json_encode(['ok' => true, 'parcels' => $stmt->fetchAll()]);
        exit;
    }

    // 加载包裹内设备列表
    if ($ajaxAction === 'load_parcel_devices') {
        $parcelId = intval($_GET['parcel_id'] ?? 0);
        if ($parcelId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $checkStmt = $pdo->prepare('SELECT id FROM parcels WHERE id = ? AND agent_id = ?');
        $checkStmt->execute([$parcelId, $agentId]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['error' => '无权访问此包裹']);
            exit;
        }
        $stmt = $pdo->prepare(
            'SELECT d.id, d.month_year, d.mac_b62, d.mac_hex, d.registered_at, d.factory_status
             FROM parcel_devices pd
             JOIN devices d ON d.id = pd.device_id
             WHERE pd.parcel_id = ?
             ORDER BY d.id'
        );
        $stmt->execute([$parcelId]);
        echo json_encode(['ok' => true, 'devices' => $stmt->fetchAll()]);
        exit;
    }

    // 确认收货：将包裹内 factory_status=6 的设备改为 7
    if ($ajaxAction === 'confirm_receipt') {
        $input = json_decode(file_get_contents('php://input'), true);
        $parcelId = intval($input['parcel_id'] ?? 0);
        if ($parcelId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $checkStmt = $pdo->prepare('SELECT id FROM parcels WHERE id = ? AND agent_id = ?');
        $checkStmt->execute([$parcelId, $agentId]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['error' => '无权操作此包裹']);
            exit;
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE devices SET factory_status = 7
                 WHERE id IN (SELECT device_id FROM parcel_devices WHERE parcel_id = ?)
                   AND factory_status = 6'
            );
            $stmt->execute([$parcelId]);
            $updated = $stmt->rowCount();
            $pdo->commit();
            echo json_encode(['ok' => true, 'updated' => $updated]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => '操作失败：' . $e->getMessage()]);
        }
        exit;
    }

    // 列出代理商名下的经销商
    if ($ajaxAction === 'list_dealers') {
        $stmt = $pdo->prepare(
            "SELECT id, display_name, username, phone, address, remark,
                    recipient_name, recipient_address, recipient_phone
             FROM accounts WHERE role = 'dealer' AND parent_id = ? AND status = 1
             ORDER BY display_name"
        );
        $stmt->execute([$agentId]);
        echo json_encode(['ok' => true, 'dealers' => $stmt->fetchAll()]);
        exit;
    }

    // 获取单个经销商详情
    if ($ajaxAction === 'get_dealer') {
        $dealerId = intval($_GET['dealer_id'] ?? 0);
        if ($dealerId <= 0) { echo json_encode(['error' => '参数无效']); exit; }
        $stmt = $pdo->prepare(
            "SELECT id, display_name, username, phone, address, remark,
                    recipient_name, recipient_address, recipient_phone
             FROM accounts WHERE id = ? AND role = 'dealer' AND parent_id = ? AND status = 1"
        );
        $stmt->execute([$dealerId, $agentId]);
        $dealer = $stmt->fetch();
        if (!$dealer) { echo json_encode(['error' => '经销商不存在']); exit; }
        echo json_encode(['ok' => true, 'dealer' => $dealer]);
        exit;
    }

    // 更新经销商信息
    if ($ajaxAction === 'update_dealer') {
        $input = json_decode(file_get_contents('php://input'), true);
        $dealerId = intval($input['id'] ?? 0);
        if ($dealerId <= 0) { echo json_encode(['error' => '参数无效']); exit; }
        $check = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND role = 'dealer' AND parent_id = ? AND status = 1");
        $check->execute([$dealerId, $agentId]);
        if (!$check->fetch()) { echo json_encode(['error' => '经销商不存在或不属于您']); exit; }

        $displayName = trim($input['display_name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $address = trim($input['address'] ?? '');
        $remark = trim($input['remark'] ?? '');
        $recipientName = trim($input['recipient_name'] ?? '');
        $recipientAddress = trim($input['recipient_address'] ?? '');
        $recipientPhone = trim($input['recipient_phone'] ?? '');
        $newPassword = $input['password'] ?? '';

        if ($displayName === '') { echo json_encode(['error' => '经销商名称不能为空']); exit; }

        $sql = 'UPDATE accounts SET display_name = ?, phone = ?, address = ?, remark = ?, recipient_name = ?, recipient_address = ?, recipient_phone = ?';
        $params = [$displayName, $phone, $address, $remark, $recipientName, $recipientAddress, $recipientPhone];
        if ($newPassword !== '') {
            if (strlen($newPassword) < 6) { echo json_encode(['error' => '密码至少6位']); exit; }
            $sql .= ', password_hash = ?';
            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = ?';
        $params[] = $dealerId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['ok' => true]);
        exit;
    }

    // 列出代理商已入库的设备 (factory_status=7) 用于发货选择
    if ($ajaxAction === 'list_stocked_devices') {
        $stmt = $pdo->prepare(
            "SELECT d.id, d.month_year || '/' || d.mac_b62 AS device_code
             FROM devices d
             JOIN parcel_devices pd ON pd.device_id = d.id
             JOIN parcels p ON p.id = pd.parcel_id AND p.agent_id = ?
             WHERE d.factory_status = 7
             ORDER BY d.id DESC"
        );
        $stmt->execute([$agentId]);
        echo json_encode(['ok' => true, 'devices' => $stmt->fetchAll()]);
        exit;
    }

    // 发货给经销商
    if ($ajaxAction === 'ship_to_dealer') {
        $input = json_decode(file_get_contents('php://input'), true);
        $dealerId = intval($input['dealer_id'] ?? 0);
        $recipientName = trim($input['recipient_name'] ?? '');
        $recipientAddress = trim($input['recipient_address'] ?? '');
        $recipientPhone = trim($input['recipient_phone'] ?? '');
        $trackingNumber = trim($input['tracking_number'] ?? '');
        $remarkText = trim($input['remark'] ?? '');
        $deviceIds = $input['device_ids'] ?? [];

        if ($dealerId <= 0) {
            echo json_encode(['error' => '请选择经销商']);
            exit;
        }
        $checkDealer = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND role = 'dealer' AND parent_id = ? AND status = 1");
        $checkDealer->execute([$dealerId, $agentId]);
        if (!$checkDealer->fetch()) {
            echo json_encode(['error' => '经销商无效或不属于您']);
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
                'INSERT INTO parcels (agent_id, dealer_id, recipient_name, recipient_address, recipient_phone, tracking_number, remark, status, created_at, shipped_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->execute([$agentId, $dealerId, $recipientName, $recipientAddress, $recipientPhone, $trackingNumber, $remarkText, $now, $now]);
            $parcelId = (int) $pdo->lastInsertId();

            $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
            $checkStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM devices d
                 JOIN parcel_devices pd ON pd.device_id = d.id
                 JOIN parcels p ON p.id = pd.parcel_id AND p.agent_id = ?
                 WHERE d.id IN ($placeholders) AND d.factory_status = 7"
            );
            $checkStmt->execute(array_merge([$agentId], $deviceIds));
            if ((int) $checkStmt->fetchColumn() !== count($deviceIds)) {
                $pdo->rollBack();
                echo json_encode(['error' => '部分设备不在已入库状态，请刷新后重试']);
                exit;
            }

            $insertPd = $pdo->prepare('INSERT INTO parcel_devices (parcel_id, device_id) VALUES (?, ?)');
            foreach ($deviceIds as $did) {
                $insertPd->execute([$parcelId, $did]);
            }

            $updateStmt = $pdo->prepare("UPDATE devices SET factory_status = 8 WHERE id IN ($placeholders) AND factory_status = 7");
            $updateStmt->execute($deviceIds);

            $pdo->commit();
            echo json_encode(['ok' => true, 'parcel_id' => $parcelId]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => '创建包裹失败：' . $e->getMessage()]);
        }
        exit;
    }

    // 创建经销商
    if ($ajaxAction === 'create_dealer') {
        $input = json_decode(file_get_contents('php://input'), true);
        $displayName = trim($input['display_name'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $phone = trim($input['phone'] ?? '');
        $address = trim($input['address'] ?? '');
        $remark = trim($input['remark'] ?? '');
        $recipientName = trim($input['recipient_name'] ?? '');
        $recipientAddress = trim($input['recipient_address'] ?? '');
        $recipientPhone = trim($input['recipient_phone'] ?? '');

        if ($displayName === '') {
            echo json_encode(['error' => '经销商名称不能为空']);
            exit;
        }
        if (!preg_match('/^[A-Za-z0-9_]{4,32}$/', $username)) {
            echo json_encode(['error' => '用户名需为4-32位字母、数字或下划线']);
            exit;
        }
        if (strlen($password) < 6) {
            echo json_encode(['error' => '密码至少6位']);
            exit;
        }
        $dup = $pdo->prepare('SELECT id FROM accounts WHERE username = ? LIMIT 1');
        $dup->execute([$username]);
        if ($dup->fetch()) {
            echo json_encode(['error' => '用户名已存在']);
            exit;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO accounts (role, parent_id, display_name, username, password_hash, phone, address, remark, recipient_name, recipient_address, recipient_phone, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([
            'dealer', $agentId, $displayName, $username,
            password_hash($password, PASSWORD_DEFAULT),
            $phone, $address, $remark,
            $recipientName, $recipientAddress, $recipientPhone,
            time(),
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => '未知操作']);
    exit;
}

$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending', 'stocked', 'shipped'], true)) {
    $tab = 'pending';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>代理商仓库</title>
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
        .page { max-width: 1100px; margin: 0 auto; padding: 40px 20px 56px; }
        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            gap: 16px; margin-bottom: 22px;
        }
        .topbar h2 { margin: 0; font-size: 24px; }
        .topbar p { margin: 4px 0 0; color: #627188; font-size: 14px; }
        .menu-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn {
            border: none; border-radius: 14px; padding: 12px 18px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; text-decoration: none;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 10px 18px rgba(44, 87, 181, 0.14); }
        .btn-primary { background: linear-gradient(135deg, #3068ff, #5a8cff); color: #fff; }
        .btn-secondary { background: #eef3ff; color: #34578c; }
        .btn-ghost { background: rgba(255,255,255,0.82); color: #26446f; border: 1px solid #d7e0ef; }
        .btn-success { background: linear-gradient(135deg, #16c18b, #2ed8a3); color: #fff; }
        .panel {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(115, 137, 167, 0.18);
            border-radius: 22px;
            box-shadow: 0 18px 45px rgba(51, 83, 135, 0.12);
            backdrop-filter: blur(10px);
        }
        .tabs { display: flex; gap: 0; border-bottom: 2px solid #e3ebf7; padding: 0 22px; }
        .tab-btn {
            border: none; background: transparent; padding: 16px 28px; font-size: 15px; font-weight: 600;
            color: #73819a; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px;
            transition: color 0.2s, border-color 0.2s; position: relative;
        }
        .tab-btn:hover { color: #3068ff; }
        .tab-btn.active { color: #3068ff; border-bottom-color: #3068ff; }
        .tab-badge {
            display: inline-block; background: #ff4d6a; color: #fff; font-size: 11px; font-weight: 700;
            border-radius: 10px; padding: 1px 7px; margin-left: 6px; vertical-align: middle;
        }
        .tab-content { padding: 22px; }
        .parcels-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px;
        }
        .parcel-card {
            border: 1px solid #e3ebf7; border-radius: 16px; padding: 18px;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(246,249,255,0.92));
            cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .parcel-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(44, 87, 181, 0.12); }
        .parcel-card.active { border-color: #3068ff; box-shadow: 0 0 0 2px rgba(48, 104, 255, 0.18); }
        .parcel-title {
            font-size: 16px; font-weight: 700; margin-bottom: 8px;
            display: flex; align-items: center; gap: 8px;
        }
        .parcel-title .icon { font-size: 20px; }
        .parcel-meta { font-size: 13px; color: #58657b; line-height: 1.8; }
        .empty-state {
            padding: 40px 24px; border: 1px dashed #cad6ec; border-radius: 16px;
            color: #73819a; text-align: center; background: rgba(250, 252, 255, 0.9); font-size: 15px;
        }
        .device-panel {
            margin-top: 20px; border: 1px solid #e3ebf7; border-radius: 16px;
            background: #fff; overflow: hidden; display: none;
        }
        .device-panel.open { display: block; }
        .device-panel-head {
            padding: 16px 20px; background: linear-gradient(135deg, #f0f5ff, #f7faff);
            border-bottom: 1px solid #e3ebf7; display: flex; justify-content: space-between; align-items: center;
        }
        .device-panel-head h4 { margin: 0; font-size: 16px; }
        .device-list { padding: 16px 20px; }
        .device-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 0; border-bottom: 1px solid #f0f3f9;
        }
        .device-item:last-child { border-bottom: none; }
        .device-code { font-size: 14px; font-weight: 600; color: #243248; font-family: "Courier New", monospace; }
        .device-status { font-size: 12px; padding: 3px 10px; border-radius: 8px; font-weight: 600; }
        .status-pending { background: #fff3e0; color: #e65100; }
        .status-stocked { background: #e8f5e9; color: #2e7d32; }
        .status-shipped { background: #e3f2fd; color: #1565c0; }
        .confirm-bar { padding: 16px 20px; border-top: 1px solid #e3ebf7; background: #fafcff; text-align: center; }
        .confirm-bar .btn { min-width: 200px; padding: 14px 28px; font-size: 16px; }
        .loading { text-align: center; padding: 30px; color: #73819a; }
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(15, 24, 44, 0.42);
            display: none; align-items: center; justify-content: center;
            padding: 20px; z-index: 1000;
        }
        .modal-overlay.open { display: flex; }
        .modal-card {
            width: min(100%, 580px); max-height: calc(100vh - 40px);
            overflow: auto; padding: 24px;
        }
        .modal-head {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 18px; gap: 12px;
        }
        .modal-head h3 { margin: 0; font-size: 20px; }
        .modal-close {
            border: none; background: transparent; color: #74839b;
            font-size: 28px; line-height: 1; cursor: pointer; padding: 0 4px;
        }
        .form-grid { display: grid; gap: 14px; }
        label { display: block; font-size: 13px; color: #56637b; margin-bottom: 6px; }
        input[type="text"], input[type="password"], textarea, select {
            width: 100%; border: 1px solid #d7e0ef; background: #fff;
            border-radius: 12px; padding: 12px 14px; font-size: 14px;
            color: #243248; outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        input:focus, textarea:focus, select:focus {
            border-color: #5f87ff; box-shadow: 0 0 0 3px rgba(95, 135, 255, 0.12);
        }
        textarea { min-height: 60px; resize: vertical; }
        .device-checkbox-list {
            max-height: 260px; overflow-y: auto;
            border: 1px solid #e3ebf7; border-radius: 12px; padding: 8px;
        }
        .device-checkbox-item {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 8px; border-radius: 8px; cursor: pointer;
        }
        .device-checkbox-item:hover { background: #f0f5ff; }
        .device-checkbox-item input[type="checkbox"] { width: auto; }
        @media (max-width: 640px) {
            .topbar { flex-direction: column; align-items: flex-start; }
            .parcels-grid { grid-template-columns: 1fr; }
            .tabs { padding: 0 12px; }
            .tab-btn { padding: 14px 18px; font-size: 14px; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h2>代理商仓库</h2>
            <p>当前登录：<?php echo ha($user['display_name']); ?>（<?php echo ha($user['username']); ?>）</p>
        </div>
        <div class="menu-actions">
            <button class="btn btn-primary" onclick="openModal('create-dealer')">创建经销商</button>
            <button class="btn btn-ghost" onclick="openModal('manage-dealers')">经销商管理</button>
            <a class="btn btn-ghost" href="account_manager.php" style="text-decoration:none;">账号管理</a>
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-secondary" type="submit">退出登录</button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="tabs">
            <button class="tab-btn<?php echo $tab === 'pending' ? ' active' : ''; ?>" data-tab="pending">
                待收货<span class="tab-badge" id="badge-pending" style="display:none;">0</span>
            </button>
            <button class="tab-btn<?php echo $tab === 'stocked' ? ' active' : ''; ?>" data-tab="stocked">
                已入库<span class="tab-badge" id="badge-stocked" style="display:none;background:#16c18b;">0</span>
            </button>
            <button class="tab-btn<?php echo $tab === 'shipped' ? ' active' : ''; ?>" data-tab="shipped">
                已发货<span class="tab-badge" id="badge-shipped" style="display:none;background:#1565c0;">0</span>
            </button>
        </div>

        <div class="tab-content" id="tab-pending" style="<?php echo $tab !== 'pending' ? 'display:none;' : ''; ?>">
            <div class="loading" id="loading-pending">正在加载...</div>
            <div id="content-pending"></div>
        </div>

        <div class="tab-content" id="tab-stocked" style="<?php echo $tab !== 'stocked' ? 'display:none;' : ''; ?>">
            <div class="loading" id="loading-stocked">正在加载...</div>
            <div id="content-stocked"></div>
        </div>

        <div class="tab-content" id="tab-shipped" style="<?php echo $tab !== 'shipped' ? 'display:none;' : ''; ?>">
            <div class="loading" id="loading-shipped">正在加载...</div>
            <div id="content-shipped"></div>
        </div>
    </div>
</div>

<!-- 创建经销商弹窗 -->
<div class="modal-overlay" id="modal-create-dealer">
    <div class="panel modal-card">
        <div class="modal-head">
            <h3>创建经销商</h3>
            <button class="modal-close" onclick="closeModal('create-dealer')">&times;</button>
        </div>
        <div class="form-grid" id="dealer-form">
            <div><label>经销商名称 *</label><input type="text" id="d_display_name"></div>
            <div><label>用户名 *</label><input type="text" id="d_username"></div>
            <div><label>密码 *</label><input type="password" id="d_password"></div>
            <div><label>手机</label><input type="text" id="d_phone"></div>
            <div><label>地址</label><input type="text" id="d_address"></div>
            <div><label>备注</label><textarea id="d_remark"></textarea></div>
            <div><label>收件人姓名</label><input type="text" id="d_recipient_name"></div>
            <div><label>收件人地址</label><input type="text" id="d_recipient_address"></div>
            <div><label>收件人手机</label><input type="text" id="d_recipient_phone"></div>
            <button class="btn btn-primary" onclick="submitCreateDealer()">创建经销商</button>
            <div id="dealer-form-msg" style="font-size:13px;"></div>
        </div>
    </div>
</div>

<!-- 经销商管理弹窗 -->
<div class="modal-overlay" id="modal-manage-dealers">
    <div class="panel modal-card">
        <div class="modal-head">
            <h3>经销商管理</h3>
            <button class="modal-close" onclick="closeModal('manage-dealers')">&times;</button>
        </div>
        <div id="dealer-list-container" style="min-height:80px;">
            <div class="loading">正在加载...</div>
        </div>
    </div>
</div>

<!-- 编辑经销商弹窗 -->
<div class="modal-overlay" id="modal-edit-dealer">
    <div class="panel modal-card">
        <div class="modal-head">
            <h3>编辑经销商</h3>
            <button class="modal-close" onclick="closeModal('edit-dealer')">&times;</button>
        </div>
        <div class="form-grid" id="edit-dealer-form">
            <input type="hidden" id="ed_id">
            <div><label>经销商名称 *</label><input type="text" id="ed_display_name"></div>
            <div><label>用户名</label><input type="text" id="ed_username" disabled style="background:#f0f3f9;cursor:not-allowed;"></div>
            <div><label>新密码（留空则不修改）</label><input type="password" id="ed_password" placeholder="留空不修改"></div>
            <div><label>手机</label><input type="text" id="ed_phone"></div>
            <div><label>地址</label><input type="text" id="ed_address"></div>
            <div><label>备注</label><textarea id="ed_remark"></textarea></div>
            <div><label>收件人姓名</label><input type="text" id="ed_recipient_name"></div>
            <div><label>收件人地址</label><input type="text" id="ed_recipient_address"></div>
            <div><label>收件人手机</label><input type="text" id="ed_recipient_phone"></div>
            <button class="btn btn-primary" onclick="submitEditDealer()">保存修改</button>
            <div id="edit-dealer-msg" style="font-size:13px;"></div>
        </div>
    </div>
</div>

<!-- 发货给经销商弹窗 -->
<div class="modal-overlay" id="modal-ship-dealer">
    <div class="panel modal-card">
        <div class="modal-head">
            <h3>发货给经销商</h3>
            <button class="modal-close" onclick="closeModal('ship-dealer')">&times;</button>
        </div>
        <div class="form-grid" id="ship-form">
            <div>
                <label>选择经销商 *</label>
                <select id="s_dealer"><option value="">加载中...</option></select>
            </div>
            <div><label>收件人姓名</label><input type="text" id="s_recipient_name"></div>
            <div><label>收件人地址</label><input type="text" id="s_recipient_address"></div>
            <div><label>收件人手机</label><input type="text" id="s_recipient_phone"></div>
            <div><label>快递单号</label><input type="text" id="s_tracking"></div>
            <div><label>备注</label><textarea id="s_remark"></textarea></div>
            <div>
                <label>选择设备 * <span id="s_devices_count" style="color:#3068ff;font-weight:600;"></span></label>
                <input type="text" id="s_devices_search" placeholder="搜索设备编号或MAC..." style="margin-bottom:8px;" oninput="filterDevices()">
                <div style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
                    <button type="button" class="btn btn-ghost" style="padding:6px 14px;font-size:12px;" onclick="toggleSelectAll(true)">全选当前</button>
                    <button type="button" class="btn btn-ghost" style="padding:6px 14px;font-size:12px;" onclick="toggleSelectAll(false)">取消全选</button>
                    <span style="font-size:12px;color:#73819a;" id="s_devices_filter_info"></span>
                </div>
                <div class="device-checkbox-list" id="s_devices_list" style="max-height:320px;">加载中...</div>
            </div>
            <button class="btn btn-primary" id="btn-ship-submit" onclick="submitShipDealer()">创建包裹并发货</button>
            <div id="ship-form-msg" style="font-size:13px;"></div>
        </div>
    </div>
</div>

<script>
(function() {
    var activeParcel = null;
    var currentTab = <?php echo json_encode($tab); ?>;

    // Modal 控制
    window.openModal = function(name) {
        var m = document.getElementById('modal-' + name);
        if (m) m.classList.add('open');
        if (name === 'ship-dealer') loadShipForm();
        if (name === 'manage-dealers') loadDealerList();
    };
    window.closeModal = function(name) {
        var m = document.getElementById('modal-' + name);
        if (m) m.classList.remove('open');
    };
    document.querySelectorAll('.modal-overlay').forEach(function(m) {
        m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('open'); });
    });

    // 切换标签
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tab = btn.getAttribute('data-tab');
            currentTab = tab;
            document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(function(c) { c.style.display = 'none'; });
            document.getElementById('tab-' + tab).style.display = '';
            loadTab(tab);
            history.replaceState(null, '', '?tab=' + tab);
        });
    });

    function loadTab(tab) {
        var loadingEl = document.getElementById('loading-' + tab);
        var contentEl = document.getElementById('content-' + tab);
        loadingEl.style.display = '';
        contentEl.innerHTML = '';
        activeParcel = null;

        if (tab === 'stocked') {
            fetch('?ajax=load_stocked')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    loadingEl.style.display = 'none';
                    if (!data.ok) { contentEl.innerHTML = '<div class="empty-state">' + escapeHtml(data.error || '加载失败') + '</div>'; return; }
                    updateBadge('stocked', data.devices.length);
                    if (data.devices.length === 0) {
                        contentEl.innerHTML = '<div class="empty-state">当前没有已入库的设备。</div>';
                        return;
                    }
                    renderStockedDevices(contentEl, data.devices);
                })
                .catch(function() { loadingEl.style.display = 'none'; contentEl.innerHTML = '<div class="empty-state">网络错误，请刷新重试。</div>'; });
        } else if (tab === 'shipped') {
            fetch('?ajax=load_shipped')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    loadingEl.style.display = 'none';
                    if (!data.ok) { contentEl.innerHTML = '<div class="empty-state">' + escapeHtml(data.error || '加载失败') + '</div>'; return; }
                    updateBadge('shipped', data.parcels.length);
                    if (data.parcels.length === 0) {
                        contentEl.innerHTML = '<div class="empty-state">当前没有已发货的包裹。</div>';
                        return;
                    }
                    renderShippedParcels(contentEl, data.parcels);
                })
                .catch(function() { loadingEl.style.display = 'none'; contentEl.innerHTML = '<div class="empty-state">网络错误，请刷新重试。</div>'; });
        } else {
            fetch('?ajax=load_pending')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    loadingEl.style.display = 'none';
                    if (!data.ok) { contentEl.innerHTML = '<div class="empty-state">' + escapeHtml(data.error || '加载失败') + '</div>'; return; }
                    updateBadge('pending', data.parcels.length);
                    if (data.parcels.length === 0) {
                        contentEl.innerHTML = '<div class="empty-state">当前没有待收货的包裹。</div>';
                        return;
                    }
                    renderParcels(contentEl, data.parcels, 'pending');
                })
                .catch(function() { loadingEl.style.display = 'none'; contentEl.innerHTML = '<div class="empty-state">网络错误，请刷新重试。</div>'; });
        }
    }

    function renderStockedDevices(container, devices) {
        var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">';
        html += '<span style="font-size:15px;font-weight:600;">已入库设备（' + devices.length + ' 台）</span>';
        html += '<button class="btn btn-primary" onclick="openModal(\'ship-dealer\')">发货给经销商</button>';
        html += '</div>';
        html += '<div class="device-list" style="background:#fff;border:1px solid #e3ebf7;border-radius:16px;">';
        devices.forEach(function(d) {
            var code = d.month_year + '/' + d.mac_b62;
            html += '<div class="device-item" style="padding:12px 20px;">';
            html += '<div><div class="device-code">' + escapeHtml(code) + '</div>';
            html += '<div style="font-size:12px;color:#73819a;margin-top:2px;">MAC: ' + escapeHtml(d.mac_hex) + '</div></div>';
            html += '<span class="device-status status-stocked">已入库</span>';
            html += '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function renderShippedParcels(container, parcels) {
        var grid = document.createElement('div');
        grid.className = 'parcels-grid';
        var devicePanel = document.createElement('div');
        devicePanel.className = 'device-panel';

        parcels.forEach(function(p) {
            var card = document.createElement('div');
            card.className = 'parcel-card';
            var shippedDate = p.shipped_at ? formatTime(p.shipped_at) : '未知';
            card.innerHTML =
                '<div class="parcel-title"><span class="icon">📦</span>包裹 #' + escapeHtml(String(p.id)) + '</div>' +
                '<div class="parcel-meta">' +
                '经销商：' + escapeHtml(p.dealer_name || '未知') + '<br>' +
                '快递单号：' + escapeHtml(p.tracking_number || '无') + '<br>' +
                '设备数量：' + escapeHtml(String(p.device_count)) + ' 台<br>' +
                '发货时间：' + escapeHtml(shippedDate) +
                (p.remark ? '<br>备注：' + escapeHtml(p.remark) : '') +
                '</div>';
            card.addEventListener('click', function() {
                grid.querySelectorAll('.parcel-card').forEach(function(c) { c.classList.remove('active'); });
                card.classList.add('active');
                loadParcelDevices(devicePanel, p.id, 'shipped');
            });
            grid.appendChild(card);
        });
        container.appendChild(grid);
        container.appendChild(devicePanel);
    }

    function updateBadge(tab, count) {
        var badge = document.getElementById('badge-' + tab);
        if (badge) {
            if (count > 0) { badge.textContent = count; badge.style.display = ''; }
            else { badge.style.display = 'none'; }
        }
    }

    function renderParcels(container, parcels, tab) {
        var grid = document.createElement('div');
        grid.className = 'parcels-grid';
        var devicePanel = document.createElement('div');
        devicePanel.className = 'device-panel';

        parcels.forEach(function(p) {
            var card = document.createElement('div');
            card.className = 'parcel-card';
            card.setAttribute('data-parcel-id', p.id);
            var shippedDate = p.shipped_at ? formatTime(p.shipped_at) : '未知';
            card.innerHTML =
                '<div class="parcel-title"><span class="icon">📦</span>包裹 #' + escapeHtml(String(p.id)) + '</div>' +
                '<div class="parcel-meta">' +
                '快递单号：' + escapeHtml(p.tracking_number || '无') + '<br>' +
                '设备数量：' + escapeHtml(String(p.device_count)) + ' 台<br>' +
                '发货时间：' + escapeHtml(shippedDate) +
                (p.remark ? '<br>备注：' + escapeHtml(p.remark) : '') +
                '</div>';
            card.addEventListener('click', function() {
                grid.querySelectorAll('.parcel-card').forEach(function(c) { c.classList.remove('active'); });
                card.classList.add('active');
                activeParcel = p.id;
                loadParcelDevices(devicePanel, p.id, tab);
            });
            grid.appendChild(card);
        });
        container.appendChild(grid);
        container.appendChild(devicePanel);
    }

    function loadParcelDevices(panel, parcelId, tab) {
        panel.className = 'device-panel open';
        panel.innerHTML = '<div class="loading">正在加载设备列表...</div>';
        fetch('?ajax=load_parcel_devices&parcel_id=' + parcelId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) { panel.innerHTML = '<div style="padding:20px;color:#af1f2d;">' + escapeHtml(data.error || '加载失败') + '</div>'; return; }
                var devices = data.devices;
                var html = '';
                html += '<div class="device-panel-head"><h4>包裹 #' + escapeHtml(String(parcelId)) + ' — 设备列表（' + devices.length + ' 台）</h4>';
                html += '<button class="btn btn-ghost" style="padding:6px 14px;font-size:13px;" onclick="this.closest(\'.device-panel\').className=\'device-panel\';">收起</button>';
                html += '</div>';
                html += '<div class="device-list">';
                if (devices.length === 0) {
                    html += '<div style="padding:16px;text-align:center;color:#73819a;">此包裹中没有设备。</div>';
                } else {
                    devices.forEach(function(d) {
                        var code = d.month_year + '/' + d.mac_b62;
                        var statusClass = d.factory_status == 6 ? 'status-pending' : (d.factory_status == 8 ? 'status-shipped' : 'status-stocked');
                        var statusText = d.factory_status == 6 ? '待收货' : (d.factory_status == 8 ? '已发经销商' : '已入库');
                        html += '<div class="device-item">';
                        html += '<div><div class="device-code">' + escapeHtml(code) + '</div>';
                        html += '<div style="font-size:12px;color:#73819a;margin-top:2px;">MAC: ' + escapeHtml(d.mac_hex) + '</div></div>';
                        html += '<span class="device-status ' + statusClass + '">' + statusText + '</span>';
                        html += '</div>';
                    });
                }
                html += '</div>';
                if (tab === 'pending') {
                    var hasPending = devices.some(function(d) { return d.factory_status == 6; });
                    if (hasPending) {
                        html += '<div class="confirm-bar">';
                        html += '<button class="btn btn-success" id="btn-confirm-' + parcelId + '" onclick="confirmReceipt(' + parcelId + ')">确认收货</button>';
                        html += '</div>';
                    }
                }
                panel.innerHTML = html;
            })
            .catch(function() { panel.innerHTML = '<div style="padding:20px;color:#af1f2d;">网络错误，请重试。</div>'; });
    }

    window.confirmReceipt = function(parcelId) {
        var btn = document.getElementById('btn-confirm-' + parcelId);
        if (!btn) return;
        if (!confirm('确认已收到此包裹中的所有设备？确认后设备将转入"已入库"状态。')) return;
        btn.disabled = true;
        btn.textContent = '处理中...';
        fetch('?ajax=confirm_receipt', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ parcel_id: parcelId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                btn.textContent = '已确认收货 ✓';
                btn.style.background = '#aaa';
                setTimeout(function() { loadTab('pending'); refreshBadges(); }, 600);
            } else {
                alert(data.error || '操作失败');
                btn.disabled = false;
                btn.textContent = '确认收货';
            }
        })
        .catch(function() { alert('网络错误，请重试'); btn.disabled = false; btn.textContent = '确认收货'; });
    };

    // 创建经销商
    window.submitCreateDealer = function() {
        var msg = document.getElementById('dealer-form-msg');
        msg.style.color = '';
        msg.textContent = '提交中...';
        var payload = {
            display_name: document.getElementById('d_display_name').value,
            username: document.getElementById('d_username').value,
            password: document.getElementById('d_password').value,
            phone: document.getElementById('d_phone').value,
            address: document.getElementById('d_address').value,
            remark: document.getElementById('d_remark').value,
            recipient_name: document.getElementById('d_recipient_name').value,
            recipient_address: document.getElementById('d_recipient_address').value,
            recipient_phone: document.getElementById('d_recipient_phone').value,
        };
        fetch('?ajax=create_dealer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                msg.style.color = '#11663f';
                msg.textContent = '经销商创建成功！';
                ['d_display_name','d_username','d_password','d_phone','d_address','d_remark','d_recipient_name','d_recipient_address','d_recipient_phone'].forEach(function(id) {
                    document.getElementById(id).value = '';
                });
            } else {
                msg.style.color = '#af1f2d';
                msg.textContent = data.error || '创建失败';
            }
        })
        .catch(function() { msg.style.color = '#af1f2d'; msg.textContent = '网络错误'; });
    };

    // 经销商管理
    function loadDealerList() {
        var container = document.getElementById('dealer-list-container');
        container.innerHTML = '<div class="loading">正在加载...</div>';
        fetch('?ajax=list_dealers').then(function(r) { return r.json(); }).then(function(data) {
            if (!data.ok) { container.innerHTML = '<div class="empty-state">' + escapeHtml(data.error || '加载失败') + '</div>'; return; }
            if (data.dealers.length === 0) { container.innerHTML = '<div class="empty-state">暂无经销商，请先创建。</div>'; return; }
            var html = '<div style="display:grid;gap:10px;">';
            data.dealers.forEach(function(d) {
                html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border:1px solid #e3ebf7;border-radius:14px;background:#fff;">';
                html += '<div>';
                html += '<div style="font-weight:600;font-size:15px;">' + escapeHtml(d.display_name) + ' <span style="color:#73819a;font-weight:400;font-size:13px;">(' + escapeHtml(d.username) + ')</span></div>';
                var info = [];
                if (d.phone) info.push('电话: ' + escapeHtml(d.phone));
                if (d.address) info.push('地址: ' + escapeHtml(d.address));
                if (d.recipient_name) info.push('收件人: ' + escapeHtml(d.recipient_name));
                if (info.length > 0) html += '<div style="font-size:12px;color:#73819a;margin-top:4px;">' + info.join(' | ') + '</div>';
                html += '</div>';
                html += '<button class="btn btn-ghost" style="padding:8px 16px;font-size:13px;" onclick="openEditDealer(' + d.id + ')">编辑</button>';
                html += '</div>';
            });
            html += '</div>';
            container.innerHTML = html;
        }).catch(function() { container.innerHTML = '<div class="empty-state">网络错误</div>'; });
    }

    window.openEditDealer = function(dealerId) {
        var msg = document.getElementById('edit-dealer-msg');
        msg.textContent = '加载中...';
        msg.style.color = '';
        document.getElementById('ed_password').value = '';
        openModal('edit-dealer');
        fetch('?ajax=get_dealer&dealer_id=' + dealerId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                msg.textContent = '';
                if (!data.ok) { msg.style.color = '#af1f2d'; msg.textContent = data.error || '加载失败'; return; }
                var d = data.dealer;
                document.getElementById('ed_id').value = d.id;
                document.getElementById('ed_display_name').value = d.display_name || '';
                document.getElementById('ed_username').value = d.username || '';
                document.getElementById('ed_phone').value = d.phone || '';
                document.getElementById('ed_address').value = d.address || '';
                document.getElementById('ed_remark').value = d.remark || '';
                document.getElementById('ed_recipient_name').value = d.recipient_name || '';
                document.getElementById('ed_recipient_address').value = d.recipient_address || '';
                document.getElementById('ed_recipient_phone').value = d.recipient_phone || '';
            })
            .catch(function() { msg.style.color = '#af1f2d'; msg.textContent = '网络错误'; });
    };

    window.submitEditDealer = function() {
        var msg = document.getElementById('edit-dealer-msg');
        msg.style.color = '';
        msg.textContent = '保存中...';
        var payload = {
            id: parseInt(document.getElementById('ed_id').value),
            display_name: document.getElementById('ed_display_name').value,
            password: document.getElementById('ed_password').value,
            phone: document.getElementById('ed_phone').value,
            address: document.getElementById('ed_address').value,
            remark: document.getElementById('ed_remark').value,
            recipient_name: document.getElementById('ed_recipient_name').value,
            recipient_address: document.getElementById('ed_recipient_address').value,
            recipient_phone: document.getElementById('ed_recipient_phone').value,
        };
        fetch('?ajax=update_dealer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                msg.style.color = '#11663f';
                msg.textContent = '保存成功！';
                setTimeout(function() { closeModal('edit-dealer'); loadDealerList(); }, 500);
            } else {
                msg.style.color = '#af1f2d';
                msg.textContent = data.error || '保存失败';
            }
        })
        .catch(function() { msg.style.color = '#af1f2d'; msg.textContent = '网络错误'; });
    };

    // 加载发货表单数据
    function loadShipForm() {
        var sel = document.getElementById('s_dealer');
        var devList = document.getElementById('s_devices_list');
        sel.innerHTML = '<option value="">加载中...</option>';
        devList.innerHTML = '加载中...';

        fetch('?ajax=list_dealers').then(function(r) { return r.json(); }).then(function(data) {
            if (!data.ok || data.dealers.length === 0) {
                sel.innerHTML = '<option value="">暂无经销商</option>';
                return;
            }
            sel.innerHTML = '<option value="">-- 请选择经销商 --</option>';
            data.dealers.forEach(function(d) {
                var opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.display_name + ' (' + d.username + ')';
                opt.setAttribute('data-rname', d.recipient_name || '');
                opt.setAttribute('data-raddr', d.recipient_address || '');
                opt.setAttribute('data-rphone', d.recipient_phone || '');
                sel.appendChild(opt);
            });
        });
        sel.onchange = function() {
            var opt = sel.options[sel.selectedIndex];
            if (opt && opt.value) {
                document.getElementById('s_recipient_name').value = opt.getAttribute('data-rname') || '';
                document.getElementById('s_recipient_address').value = opt.getAttribute('data-raddr') || '';
                document.getElementById('s_recipient_phone').value = opt.getAttribute('data-rphone') || '';
            }
        };

        document.getElementById('s_devices_search').value = '';
        document.getElementById('s_devices_count').textContent = '';
        document.getElementById('s_devices_filter_info').textContent = '';
        allStockedDevices = [];
        fetch('?ajax=list_stocked_devices').then(function(r) { return r.json(); }).then(function(data) {
            if (!data.ok || data.devices.length === 0) {
                devList.innerHTML = '<div style="padding:12px;color:#73819a;text-align:center;">暂无可发货的设备</div>';
                return;
            }
            allStockedDevices = data.devices;
            renderDeviceList(allStockedDevices);
            document.getElementById('s_devices_filter_info').textContent = '共 ' + data.devices.length + ' 台';
        });
    }

    var allStockedDevices = [];
    var checkedDeviceIds = {};

    function renderDeviceList(devices) {
        var devList = document.getElementById('s_devices_list');
        devList.innerHTML = '';
        if (devices.length === 0) {
            devList.innerHTML = '<div style="padding:12px;color:#73819a;text-align:center;">无匹配设备</div>';
            return;
        }
        devices.forEach(function(d) {
            var label = document.createElement('label');
            label.className = 'device-checkbox-item';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = d.id;
            cb.style.width = 'auto';
            if (checkedDeviceIds[d.id]) cb.checked = true;
            cb.addEventListener('change', function() {
                if (cb.checked) checkedDeviceIds[d.id] = true;
                else delete checkedDeviceIds[d.id];
                updateDeviceCount();
            });
            var span = document.createElement('span');
            span.style.fontFamily = 'monospace';
            span.style.fontSize = '13px';
            span.textContent = d.device_code;
            label.appendChild(cb);
            label.appendChild(span);
            devList.appendChild(label);
        });
    }

    function updateDeviceCount() {
        var count = Object.keys(checkedDeviceIds).length;
        var el = document.getElementById('s_devices_count');
        el.textContent = count > 0 ? '（已选 ' + count + ' 台）' : '';
    }

    window.filterDevices = function() {
        var keyword = document.getElementById('s_devices_search').value.trim().toLowerCase();
        // 保存当前可见的勾选状态
        document.querySelectorAll('#s_devices_list input[type=checkbox]').forEach(function(cb) {
            if (cb.checked) checkedDeviceIds[parseInt(cb.value)] = true;
            else delete checkedDeviceIds[parseInt(cb.value)];
        });
        var filtered = allStockedDevices;
        if (keyword) {
            filtered = allStockedDevices.filter(function(d) {
                return d.device_code.toLowerCase().indexOf(keyword) !== -1;
            });
        }
        renderDeviceList(filtered);
        var info = document.getElementById('s_devices_filter_info');
        if (keyword) {
            info.textContent = '匹配 ' + filtered.length + ' / 共 ' + allStockedDevices.length + ' 台';
        } else {
            info.textContent = '共 ' + allStockedDevices.length + ' 台';
        }
    };

    window.toggleSelectAll = function(selectAll) {
        var checkboxes = document.querySelectorAll('#s_devices_list input[type=checkbox]');
        checkboxes.forEach(function(cb) {
            cb.checked = selectAll;
            if (selectAll) checkedDeviceIds[parseInt(cb.value)] = true;
            else delete checkedDeviceIds[parseInt(cb.value)];
        });
        updateDeviceCount();
    }

    // 发货给经销商
    window.submitShipDealer = function() {
        var msg = document.getElementById('ship-form-msg');
        var btn = document.getElementById('btn-ship-submit');
        msg.style.color = '';
        msg.textContent = '';
        var dealerId = document.getElementById('s_dealer').value;
        if (!dealerId) { msg.style.color = '#af1f2d'; msg.textContent = '请选择经销商'; return; }
        // 同步当前可见的勾选状态
        document.querySelectorAll('#s_devices_list input[type=checkbox]').forEach(function(cb) {
            if (cb.checked) checkedDeviceIds[parseInt(cb.value)] = true;
            else delete checkedDeviceIds[parseInt(cb.value)];
        });
        var deviceIds = Object.keys(checkedDeviceIds).map(Number);
        if (deviceIds.length === 0) { msg.style.color = '#af1f2d'; msg.textContent = '请至少选择一个设备'; return; }

        btn.disabled = true;
        msg.textContent = '提交中...';
        fetch('?ajax=ship_to_dealer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                dealer_id: parseInt(dealerId),
                recipient_name: document.getElementById('s_recipient_name').value,
                recipient_address: document.getElementById('s_recipient_address').value,
                recipient_phone: document.getElementById('s_recipient_phone').value,
                tracking_number: document.getElementById('s_tracking').value,
                remark: document.getElementById('s_remark').value,
                device_ids: deviceIds
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.ok) {
                msg.style.color = '#11663f';
                msg.textContent = '发货成功！包裹 #' + data.parcel_id;
                setTimeout(function() {
                    closeModal('ship-dealer');
                    loadTab(currentTab);
                    refreshBadges();
                }, 800);
            } else {
                msg.style.color = '#af1f2d';
                msg.textContent = data.error || '发货失败';
            }
        })
        .catch(function() { btn.disabled = false; msg.style.color = '#af1f2d'; msg.textContent = '网络错误'; });
    };

    function refreshBadges() {
        fetch('?ajax=load_pending').then(function(r) { return r.json(); }).then(function(d) { if (d.ok) updateBadge('pending', d.parcels.length); });
        fetch('?ajax=load_stocked').then(function(r) { return r.json(); }).then(function(d) { if (d.ok) updateBadge('stocked', d.devices.length); });
        fetch('?ajax=load_shipped').then(function(r) { return r.json(); }).then(function(d) { if (d.ok) updateBadge('shipped', d.parcels.length); });
    }

    function formatTime(ts) {
        var d = new Date(ts * 1000);
        var pad = function(n) { return n < 10 ? '0' + n : n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
               ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // 初始加载
    loadTab(currentTab);
    refreshBadges();
})();
</script>
</body>
</html>

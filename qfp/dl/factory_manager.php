<?php
session_start();

require_once __DIR__ . '/db.php';

$pdo = getDb();
$errors = [];

function redirectFactorySelf(): void {
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function stagePageUrl(int $status): string {
    if ($status === 1) {
        return 'factory_testing.php';
    }
    if ($status === 2) {
        return 'factory_warehouse.php';
    }
    return 'factory_manager.php';
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

function currentStageNote(PDO $pdo, int $deviceId, int $stage): ?array {
    $stmt = $pdo->prepare('SELECT note, updated_at FROM factory_stage_notes WHERE device_id = ? AND stage = ? LIMIT 1');
    $stmt->execute([$deviceId, $stage]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE username = ? AND role = ? AND status = 1 LIMIT 1');
        $stmt->execute([$username, 'factory']);
        $account = $stmt->fetch();

        if (!$account || !password_verify($password, $account['password_hash'])) {
            $errors[] = '用户名或密码错误';
        } else {
            session_regenerate_id(true);
            $_SESSION['factory_user'] = [
                'id' => (int) $account['id'],
                'role' => $account['role'],
                'display_name' => $account['display_name'],
                'username' => $account['username'],
            ];
            redirectFactorySelf();
        }
    }

    if ($action === 'logout') {
        unset($_SESSION['factory_user']);
        redirectFactorySelf();
    }

    $user = currentFactoryUser();
    if ($user && $action === 'advance_to_testing') {
        $deviceId = intval($_POST['device_id'] ?? 0);
        if ($deviceId <= 0) {
            $errors[] = '设备参数无效';
        } else {
            $stmt = $pdo->prepare('UPDATE devices SET factory_status = 1 WHERE id = ? AND factory_status = 0');
            $stmt->execute([$deviceId]);
            redirectFactorySelf();
        }
    }

    if ($user && $action === 'save_stage_note') {
        $deviceId = intval($_POST['device_id'] ?? 0);
        $stage = intval($_POST['stage'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($deviceId <= 0) {
            $errors[] = '设备参数无效';
        } else {
            if ($note === '') {
                $stmt = $pdo->prepare('DELETE FROM factory_stage_notes WHERE device_id = ? AND stage = ?');
                $stmt->execute([$deviceId, $stage]);
            } else {
                $now = time();
                $stmt = $pdo->prepare(
                    'INSERT INTO factory_stage_notes (device_id, stage, note, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?)
                     ON CONFLICT(device_id, stage) DO UPDATE SET note = excluded.note, updated_at = excluded.updated_at'
                );
                $stmt->execute([$deviceId, $stage, $note, $now, $now]);
            }
            redirectFactorySelf();
        }
    }
}

$user = currentFactoryUser();
$devices = [];
if ($user) {
    $stmt = $pdo->prepare(
        'SELECT d.id, d.month_year, d.mac_b62, d.registered_at, d.getlist_at, d.factory_status,
                n.note AS stage_note, n.updated_at AS stage_note_updated_at
         FROM devices d
         LEFT JOIN factory_stage_notes n ON n.device_id = d.id AND n.stage = d.factory_status
         WHERE d.factory_status = ?
         ORDER BY COALESCE(d.getlist_at, 0) DESC, d.registered_at DESC, d.id DESC'
    );
    $stmt->execute([0]);
    $devices = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>工厂登录</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "PingFang SC", sans-serif;
            background:
                radial-gradient(circle at top right, rgba(255, 148, 71, 0.12), transparent 24%),
                radial-gradient(circle at bottom left, rgba(0, 159, 196, 0.1), transparent 22%),
                linear-gradient(135deg, #e7eef5 0%, #edf3f8 52%, #f4efe6 100%);
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
        .panel {
            background: rgba(243, 247, 251, 0.92);
            border: 1px solid rgba(129, 146, 173, 0.24);
            border-radius: 22px;
            box-shadow: 0 16px 42px rgba(42, 65, 108, 0.12);
            backdrop-filter: blur(10px);
        }
        .login-wrap {
            max-width: 460px;
            margin: 60px auto 0;
            padding: 28px;
        }
        .tip, .errors {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 14px;
        }
        .tip {
            background: #eef6ff;
            color: #31598c;
        }
        .errors {
            background: #fff1f1;
            color: #b42318;
        }
        .form-grid {
            display: grid;
            gap: 14px;
        }
        label {
            display: block;
            font-size: 13px;
            color: #5a667c;
            margin-bottom: 6px;
        }
        input {
            width: 100%;
            border: 1px solid #d7dfed;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            outline: none;
        }
        input:focus {
            border-color: #5f87ff;
            box-shadow: 0 0 0 3px rgba(95,135,255,0.12);
        }
        .btn {
            border: none;
            border-radius: 14px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, #f97316, #fb923c);
            color: #fff;
        }
        .btn-secondary {
            background: #eef2f7;
            color: #344054;
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
            border-color: #f97316;
            background: linear-gradient(135deg, #f97316, #fb923c);
            color: #fff;
            box-shadow: 0 10px 24px rgba(249, 115, 22, 0.22);
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
            background:
                linear-gradient(180deg, #ffffff 0%, #f9fbfd 100%);
            box-shadow: 0 8px 20px rgba(31, 41, 55, 0.06);
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
        .device-id-button {
            padding: 0;
            border: none;
            background: transparent;
            color: #155eef;
            font: inherit;
            font-weight: 700;
            text-align: left;
            cursor: pointer;
        }
        .device-id-button:hover {
            color: #0040c9;
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
            background: #fff3e8;
            color: #b54708;
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
        .modal-mask {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(15, 23, 42, 0.42);
            z-index: 1000;
        }
        .modal-mask.active {
            display: flex;
        }
        .modal-card {
            width: min(100%, 420px);
            background: #fff;
            border-radius: 22px;
            border: 1px solid #d8e1ec;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
            padding: 20px;
        }
        .modal-card h3 {
            margin: 0 0 8px;
            font-size: 20px;
        }
        .modal-card p {
            margin: 0;
            color: #667085;
            font-size: 14px;
            line-height: 1.6;
        }
        .modal-actions {
            display: grid;
            gap: 10px;
            margin-top: 18px;
        }
        .btn-warning {
            background: linear-gradient(135deg, #0f766e, #14b8a6);
            color: #fff;
        }
        textarea {
            width: 100%;
            min-height: 140px;
            border: 1px solid #d7dfed;
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 14px;
            resize: vertical;
            outline: none;
            font-family: inherit;
        }
        textarea:focus {
            border-color: #5f87ff;
            box-shadow: 0 0 0 3px rgba(95,135,255,0.12);
        }
        .modal-toolbar {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }
        .modal-toolbar .btn {
            flex: 1;
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
            .modal-card {
                width: 100%;
                border-radius: 18px;
                padding: 18px;
            }
            .modal-toolbar {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <h1>祝福牌工厂后台</h1>
        <p>当前阶段只显示新注册设备列表，用于追踪主板上电注册后的首个生产阶段。</p>
    </div>

    <?php if (!$user): ?>
        <div class="panel login-wrap">
            <h2>工厂登录</h2>
            <div class="tip">默认工厂管理员账号：gcadmin　默认密码：123456</div>
            <?php if ($errors): ?>
                <div class="errors"><?php echo h(implode('；', $errors)); ?></div>
            <?php endif; ?>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="login">
                <div>
                    <label for="username">用户名</label>
                    <input id="username" name="username" type="text" value="gcadmin" autocomplete="username">
                </div>
                <div>
                    <label for="password">密码</label>
                    <input id="password" name="password" type="password" autocomplete="current-password">
                </div>
                <button class="btn btn-primary" type="submit">登录</button>
            </form>
        </div>
    <?php else: ?>
        <div class="topbar">
            <div>
                <h2>新注册设备列表</h2>
                <p>当前登录：<?php echo h($user['display_name']); ?>（<?php echo h($user['username']); ?>）</p>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-secondary" type="submit">退出登录</button>
            </form>
        </div>

        <div class="stage-switcher">
            <a class="stage-link active" href="<?php echo h(stagePageUrl(0)); ?>">设备刚注册阶段</a>
            <a class="stage-link" href="<?php echo h(stagePageUrl(1)); ?>">设备测试阶段</a>
            <a class="stage-link" href="<?php echo h(stagePageUrl(2)); ?>">合格待转库</a>
        </div>

        <div class="panel card">
            <?php if (!$devices): ?>
                <div class="empty">当前没有处于“新注册”状态的设备。</div>
            <?php else: ?>
                <div class="device-grid">
                    <?php foreach ($devices as $device): ?>
                        <article class="device-tile">
                            <div class="device-tile-header">
                                <div>
                                    <button
                                        class="device-id-button device-title js-open-device-actions"
                                        type="button"
                                        data-device-id="<?php echo (int) $device['id']; ?>"
                                        data-device-code="<?php echo h($device['month_year'] . $device['mac_b62']); ?>"
                                        data-device-stage="<?php echo (int) $device['factory_status']; ?>"
                                        data-device-note="<?php echo h((string) ($device['stage_note'] ?? '')); ?>"
                                    ><?php echo h($device['month_year'] . $device['mac_b62']); ?></button>
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
    <?php endif; ?>
</div>
<?php if ($user): ?>
    <div class="modal-mask" id="deviceActionModal" aria-hidden="true">
        <div class="modal-card">
            <h3 id="deviceActionTitle">设备操作</h3>
            <p id="deviceActionDesc">请选择当前设备在本阶段要执行的动作。</p>
            <div class="modal-actions">
                <form method="post">
                    <input type="hidden" name="action" value="advance_to_testing">
                    <input type="hidden" name="device_id" id="advanceDeviceId" value="0">
                    <button class="btn btn-warning" type="submit">进入测试阶段</button>
                </form>
                <button class="btn btn-primary" type="button" id="openNoteModalBtn">添加阶段备注</button>
                <button class="btn btn-secondary js-close-modal" type="button">取消</button>
            </div>
        </div>
    </div>

    <div class="modal-mask" id="stageNoteModal" aria-hidden="true">
        <div class="modal-card">
            <h3 id="stageNoteTitle">编辑阶段备注</h3>
            <p>备注会独立保存到设备当前阶段，后续进入其他阶段后不会覆盖本阶段内容。</p>
            <form method="post">
                <input type="hidden" name="action" value="save_stage_note">
                <input type="hidden" name="device_id" id="noteDeviceId" value="0">
                <input type="hidden" name="stage" id="noteStage" value="0">
                <div style="margin-top: 16px;">
                    <label for="stageNoteInput">阶段备注</label>
                    <textarea id="stageNoteInput" name="note" placeholder="输入该设备在当前阶段的说明、异常、测试准备情况等"></textarea>
                </div>
                <div class="modal-toolbar">
                    <button class="btn btn-primary" type="submit">保存备注</button>
                    <button class="btn btn-secondary js-close-note-modal" type="button">取消</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const deviceActionModal = document.getElementById('deviceActionModal');
        const stageNoteModal = document.getElementById('stageNoteModal');
        const advanceDeviceIdInput = document.getElementById('advanceDeviceId');
        const noteDeviceIdInput = document.getElementById('noteDeviceId');
        const noteStageInput = document.getElementById('noteStage');
        const noteInput = document.getElementById('stageNoteInput');
        const deviceActionTitle = document.getElementById('deviceActionTitle');
        const stageNoteTitle = document.getElementById('stageNoteTitle');
        const openNoteModalBtn = document.getElementById('openNoteModalBtn');

        const selectedDevice = {
            id: 0,
            code: '',
            stage: 0,
            note: ''
        };

        function showModal(modal) {
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
        }

        function hideModal(modal) {
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('.js-open-device-actions').forEach((button) => {
            button.addEventListener('click', () => {
                selectedDevice.id = Number(button.dataset.deviceId || '0');
                selectedDevice.code = button.dataset.deviceCode || '';
                selectedDevice.stage = Number(button.dataset.deviceStage || '0');
                selectedDevice.note = button.dataset.deviceNote || '';

                advanceDeviceIdInput.value = String(selectedDevice.id);
                deviceActionTitle.textContent = '设备操作：' + selectedDevice.code;
                stageNoteTitle.textContent = '编辑阶段备注：' + selectedDevice.code;
                showModal(deviceActionModal);
            });
        });

        openNoteModalBtn.addEventListener('click', () => {
            noteDeviceIdInput.value = String(selectedDevice.id);
            noteStageInput.value = String(selectedDevice.stage);
            noteInput.value = selectedDevice.note;
            hideModal(deviceActionModal);
            showModal(stageNoteModal);
            noteInput.focus();
        });

        document.querySelectorAll('.js-close-modal').forEach((button) => {
            button.addEventListener('click', () => hideModal(deviceActionModal));
        });

        document.querySelectorAll('.js-close-note-modal').forEach((button) => {
            button.addEventListener('click', () => hideModal(stageNoteModal));
        });

        [deviceActionModal, stageNoteModal].forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    hideModal(modal);
                }
            });
        });
    </script>
<?php endif; ?>
</body>
</html>
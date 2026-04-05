<?php
session_start();

require_once __DIR__ . '/db.php';

$pdo = getDb();
$messages = [];
$errors = [];
$activeModal = '';

function redirectSelf(): void {
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function currentUser(): ?array {
    if (empty($_SESSION['account_user'])) {
        return null;
    }
    return $_SESSION['account_user'];
}

function requireAdmin(): array {
    $user = currentUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        redirectSelf();
    }
    return $user;
}

function requireManager(): array {
    $user = currentUser();
    if (!$user || !in_array(($user['role'] ?? ''), ['admin', 'agent'], true)) {
        redirectSelf();
    }
    return $user;
}

function roleLabel(string $role): string {
    if ($role === 'admin') return '管理员';
    if ($role === 'agent') return '代理商';
    if ($role === 'dealer') return '经销商';
    return $role;
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE username = ? AND status = 1 LIMIT 1');
        $stmt->execute([$username]);
        $account = $stmt->fetch();

        if (!$account || !in_array($account['role'], ['admin', 'agent', 'dealer'], true) || !password_verify($password, $account['password_hash'])) {
            $errors[] = '用户名或密码错误';
        } else {
            session_regenerate_id(true);
            $_SESSION['account_user'] = [
                'id' => (int) $account['id'],
                'role' => $account['role'],
                'display_name' => $account['display_name'],
                'username' => $account['username'],
            ];
            if ($account['role'] === 'agent') {
                header('Location: agent_warehouse.php');
                exit;
            }
            if ($account['role'] === 'dealer') {
                header('Location: dealer_warehouse.php');
                exit;
            }
            redirectSelf();
        }
    }

    if ($action === 'logout') {
        unset($_SESSION['account_user']);
        redirectSelf();
    }

    if ($action === 'create_agent') {
        $admin = requireAdmin();
        $activeModal = 'create-agent';
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $remark = trim((string) ($_POST['remark'] ?? ''));
        $recipientName = trim((string) ($_POST['recipient_name'] ?? ''));
        $recipientAddress = trim((string) ($_POST['recipient_address'] ?? ''));
        $recipientPhone = trim((string) ($_POST['recipient_phone'] ?? ''));

        if ($displayName === '') {
            $errors[] = '代理商名称不能为空';
        }
        if (!preg_match('/^[A-Za-z0-9_]{4,32}$/', $username)) {
            $errors[] = '用户名需为 4-32 位字母、数字或下划线';
        }
        if (strlen($password) < 6) {
            $errors[] = '密码至少 6 位';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('SELECT id FROM accounts WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $errors[] = '用户名已存在';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO accounts (role, parent_id, display_name, username, password_hash, phone, address, remark, recipient_name, recipient_address, recipient_phone, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            );
            $stmt->execute([
                'agent',
                (int) $admin['id'],
                $displayName,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $phone,
                $address,
                $remark,
                $recipientName,
                $recipientAddress,
                $recipientPhone,
                time(),
            ]);
            $messages[] = '代理商已创建';
            $activeModal = '';
        }
    }

    if ($action === 'create_dealer') {
        $manager = requireManager();
        $activeModal = 'create-dealer';
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $remark = trim((string) ($_POST['remark'] ?? ''));
        $recipientName = trim((string) ($_POST['recipient_name'] ?? ''));
        $recipientAddress = trim((string) ($_POST['recipient_address'] ?? ''));
        $recipientPhone = trim((string) ($_POST['recipient_phone'] ?? ''));

        if ($displayName === '') {
            $errors[] = '经销商名称不能为空';
        }
        if (!preg_match('/^[A-Za-z0-9_]{4,32}$/', $username)) {
            $errors[] = '用户名需为 4-32 位字母、数字或下划线';
        }
        if (strlen($password) < 6) {
            $errors[] = '密码至少 6 位';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('SELECT id FROM accounts WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $errors[] = '用户名已存在';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO accounts (role, parent_id, display_name, username, password_hash, phone, address, remark, recipient_name, recipient_address, recipient_phone, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            );
            $stmt->execute([
                'dealer',
                (int) $manager['id'],
                $displayName,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $phone,
                $address,
                $remark,
                $recipientName,
                $recipientAddress,
                $recipientPhone,
                time(),
            ]);
            $messages[] = '经销商已创建';
            $activeModal = '';
        }
    }

    if ($action === 'change_password') {
        $manager = requireManager();
        $activeModal = 'change-password';
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $manager['id']]);
        $account = $stmt->fetch();

        if (!$account || !password_verify($currentPassword, $account['password_hash'])) {
            $errors[] = '当前密码不正确';
        }
        if (strlen($newPassword) < 6) {
            $errors[] = '新密码至少 6 位';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = '两次输入的新密码不一致';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE accounts SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $manager['id']]);
            $messages[] = roleLabel($manager['role']) . '密码已更新';
            $activeModal = '';
        }
    }
}

$user = currentUser();
$agents = [];
$dealers = [];
if ($user && ($user['role'] ?? '') === 'admin') {
    $stmt = $pdo->query("SELECT * FROM accounts WHERE role = 'agent' ORDER BY created_at DESC, id DESC");
    $agents = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT d.*, p.display_name AS parent_name FROM accounts d LEFT JOIN accounts p ON p.id = d.parent_id WHERE d.role = 'dealer' ORDER BY d.created_at DESC, d.id DESC");
    $dealers = $stmt->fetchAll();
} elseif ($user && ($user['role'] ?? '') === 'agent') {
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE role = 'dealer' AND parent_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->execute([(int) $user['id']]);
    $dealers = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>祝福牌管理登录</title>
    <style>
        * {
            box-sizing: border-box;
        }
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
            max-width: 1180px;
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
        .panel {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(115, 137, 167, 0.18);
            border-radius: 22px;
            box-shadow: 0 18px 45px rgba(51, 83, 135, 0.12);
            backdrop-filter: blur(10px);
        }
        .login-wrap {
            max-width: 460px;
            margin: 60px auto 0;
            padding: 28px;
        }
        .login-tip {
            margin: 10px 0 22px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #eef5ff;
            color: #31598c;
            font-size: 13px;
        }
        .messages, .errors {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 14px;
        }
        .messages {
            background: #ecfbf3;
            color: #11663f;
        }
        .errors {
            background: #fff1f1;
            color: #af1f2d;
        }
        .form-grid {
            display: grid;
            gap: 14px;
        }
        label {
            display: block;
            font-size: 13px;
            color: #56637b;
            margin-bottom: 6px;
        }
        input, textarea {
            width: 100%;
            border: 1px solid #d7e0ef;
            background: #fff;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            color: #243248;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        textarea {
            min-height: 88px;
            resize: vertical;
        }
        input:focus, textarea:focus {
            border-color: #5f87ff;
            box-shadow: 0 0 0 3px rgba(95, 135, 255, 0.12);
        }
        .btn {
            border: none;
            border-radius: 14px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px rgba(44, 87, 181, 0.14);
        }
        .btn-primary {
            background: linear-gradient(135deg, #3068ff, #5a8cff);
            color: #fff;
        }
        .btn-secondary {
            background: #eef3ff;
            color: #34578c;
        }
        .btn-ghost {
            background: rgba(255, 255, 255, 0.82);
            color: #26446f;
            border: 1px solid #d7e0ef;
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
        .card {
            padding: 22px;
        }
        .card h3 {
            margin: 0 0 18px;
            font-size: 18px;
        }
        .list {
            display: grid;
            gap: 14px;
        }
        .dealer-item {
            padding: 16px;
            border: 1px solid #e3ebf7;
            border-radius: 16px;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(246,249,255,0.92));
        }
        .dealer-title {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 8px;
        }
        .dealer-name {
            font-size: 16px;
            font-weight: 700;
        }
        .dealer-username {
            color: #678;
            font-size: 13px;
        }
        .dealer-meta {
            font-size: 13px;
            color: #58657b;
            line-height: 1.7;
            white-space: pre-wrap;
        }
        .empty {
            padding: 24px;
            border: 1px dashed #cad6ec;
            border-radius: 16px;
            color: #73819a;
            text-align: center;
            background: rgba(250, 252, 255, 0.9);
        }
        .inline-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .menu-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 24, 44, 0.42);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 1000;
        }
        .modal.open {
            display: flex;
        }
        .modal-card {
            width: min(100%, 520px);
            max-height: calc(100vh - 40px);
            overflow: auto;
            padding: 24px;
        }
        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            gap: 12px;
        }
        .modal-head h3 {
            margin: 0;
            font-size: 20px;
        }
        .modal-close {
            border: none;
            background: transparent;
            color: #74839b;
            font-size: 28px;
            line-height: 1;
            cursor: pointer;
            padding: 0 4px;
        }
        @media (max-width: 920px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <h1>祝福牌管理中心</h1>
        <p>当前阶段支持管理员和代理商登录。管理员可创建代理商，代理商登录后可创建并管理自己名下的经销商。</p>
    </div>

    <?php if (!$user): ?>
        <div class="panel login-wrap">
            <h2>账号登录</h2>
            <div class="login-tip">默认管理员账号：admin　默认密码：123456。代理商和经销商账号创建后即可登录。</div>

            <?php if ($messages): ?>
                <div class="messages"><?php echo h(implode('；', $messages)); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="errors"><?php echo h(implode('；', $errors)); ?></div>
            <?php endif; ?>

            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="login">
                <div>
                    <label for="username">用户名</label>
                    <input id="username" name="username" type="text" value="admin" autocomplete="username">
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
                <h2><?php echo h(roleLabel($user['role'])); ?>控制台</h2>
                <p>当前登录：<?php echo h($user['display_name']); ?>（<?php echo h($user['username']); ?>）</p>
            </div>
            <div class="menu-actions">
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                    <button class="btn btn-primary" type="button" data-open-modal="create-agent">创建代理商</button>
                <?php endif; ?>
                <button class="btn btn-primary" type="button" data-open-modal="create-dealer">创建经销商</button>
                <a class="btn btn-primary" href="product_warehouse.php" style="text-decoration:none;">货品管理</a>
                <button class="btn btn-ghost" type="button" data-open-modal="change-password">修改密码</button>
                <form method="post">
                    <input type="hidden" name="action" value="logout">
                    <button class="btn btn-secondary" type="submit">退出登录</button>
                </form>
            </div>
        </div>

        <?php if ($messages): ?>
            <div class="messages"><?php echo h(implode('；', $messages)); ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="errors"><?php echo h(implode('；', $errors)); ?></div>
        <?php endif; ?>

        <div class="panel card">
            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <div class="inline-actions" style="justify-content:space-between; margin-bottom:18px;">
                    <h3 style="margin:0;">代理商列表</h3>
                    <span style="font-size:13px;color:#627188;">共 <?php echo count($agents); ?> 个代理商</span>
                </div>

                <?php if (!$agents): ?>
                    <div class="empty" style="margin-bottom:20px;">当前还没有代理商，点击右上角“创建代理商”开始添加。</div>
                <?php else: ?>
                    <div class="list" style="margin-bottom:22px;">
                        <?php foreach ($agents as $agent): ?>
                            <div class="dealer-item">
                                <div class="dealer-title">
                                    <div class="dealer-name"><?php echo h($agent['display_name']); ?></div>
                                    <div class="dealer-username">账号：<?php echo h($agent['username']); ?></div>
                                </div>
                                <div class="dealer-meta">
手机：<?php echo h($agent['phone'] ?: '未填写'); ?>
地址：<?php echo h($agent['address'] ?: '未填写'); ?>
备注：<?php echo h($agent['remark'] ?: '无'); ?>
创建时间：<?php echo $agent['created_at'] ? h(date('Y-m-d H:i:s', (int) $agent['created_at'])) : '未知'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="inline-actions" style="justify-content:space-between; margin-bottom:18px;">
                <h3 style="margin:0;">经销商列表</h3>
                <span style="font-size:13px;color:#627188;">共 <?php echo count($dealers); ?> 个经销商</span>
            </div>

            <?php if (!$dealers): ?>
                <div class="empty">当前还没有经销商，点击右上角“创建经销商”开始添加。</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($dealers as $dealer): ?>
                        <div class="dealer-item">
                            <div class="dealer-title">
                                <div class="dealer-name"><?php echo h($dealer['display_name']); ?></div>
                                <div class="dealer-username">账号：<?php echo h($dealer['username']); ?></div>
                            </div>
                            <div class="dealer-meta">
上级：<?php echo h($dealer['parent_name'] ?? $user['display_name']); ?>
手机：<?php echo h($dealer['phone'] ?: '未填写'); ?>
地址：<?php echo h($dealer['address'] ?: '未填写'); ?>
备注：<?php echo h($dealer['remark'] ?: '无'); ?>
创建时间：<?php echo $dealer['created_at'] ? h(date('Y-m-d H:i:s', (int) $dealer['created_at'])) : '未知'; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (($user['role'] ?? '') === 'admin'): ?>
        <div class="modal<?php echo $activeModal === 'create-agent' ? ' open' : ''; ?>" id="modal-create-agent">
            <div class="panel modal-card">
                <div class="modal-head">
                    <h3>创建代理商</h3>
                    <button class="modal-close" type="button" data-close-modal>&times;</button>
                </div>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="create_agent">
                    <div>
                        <label for="agent_display_name">代理商名称</label>
                        <input id="agent_display_name" name="display_name" type="text" required>
                    </div>
                    <div>
                        <label for="agent_username">用户名</label>
                        <input id="agent_username" name="username" type="text" required>
                    </div>
                    <div>
                        <label for="agent_password">密码</label>
                        <input id="agent_password" name="password" type="password" required>
                    </div>
                    <div>
                        <label for="agent_phone">手机</label>
                        <input id="agent_phone" name="phone" type="text">
                    </div>
                    <div>
                        <label for="agent_address">地址</label>
                        <input id="agent_address" name="address" type="text">
                    </div>
                    <div>
                        <label for="agent_remark">备注</label>
                        <textarea id="agent_remark" name="remark"></textarea>
                    </div>
                    <div>
                        <label for="agent_recipient_name">收件人姓名</label>
                        <input id="agent_recipient_name" name="recipient_name" type="text">
                    </div>
                    <div>
                        <label for="agent_recipient_address">收件人地址</label>
                        <input id="agent_recipient_address" name="recipient_address" type="text">
                    </div>
                    <div>
                        <label for="agent_recipient_phone">收件人手机</label>
                        <input id="agent_recipient_phone" name="recipient_phone" type="text">
                    </div>
                    <button class="btn btn-primary" type="submit">创建代理商</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="modal<?php echo $activeModal === 'create-dealer' ? ' open' : ''; ?>" id="modal-create-dealer">
            <div class="panel modal-card">
                <div class="modal-head">
                    <h3>创建经销商</h3>
                    <button class="modal-close" type="button" data-close-modal>&times;</button>
                </div>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="create_dealer">
                    <div>
                        <label for="display_name">经销商名称</label>
                        <input id="display_name" name="display_name" type="text" required>
                    </div>
                    <div>
                        <label for="dealer_username">用户名</label>
                        <input id="dealer_username" name="username" type="text" required>
                    </div>
                    <div>
                        <label for="dealer_password">密码</label>
                        <input id="dealer_password" name="password" type="password" required>
                    </div>
                    <div>
                        <label for="phone">手机</label>
                        <input id="phone" name="phone" type="text">
                    </div>
                    <div>
                        <label for="address">地址</label>
                        <input id="address" name="address" type="text">
                    </div>
                    <div>
                        <label for="remark">备注</label>
                        <textarea id="remark" name="remark"></textarea>
                    </div>
                    <div>
                        <label for="dealer_recipient_name">收件人姓名</label>
                        <input id="dealer_recipient_name" name="recipient_name" type="text">
                    </div>
                    <div>
                        <label for="dealer_recipient_address">收件人地址</label>
                        <input id="dealer_recipient_address" name="recipient_address" type="text">
                    </div>
                    <div>
                        <label for="dealer_recipient_phone">收件人手机</label>
                        <input id="dealer_recipient_phone" name="recipient_phone" type="text">
                    </div>
                    <button class="btn btn-primary" type="submit">创建经销商</button>
                </form>
            </div>
        </div>

        <div class="modal<?php echo $activeModal === 'change-password' ? ' open' : ''; ?>" id="modal-change-password">
            <div class="panel modal-card">
                <div class="modal-head">
                    <h3>修改<?php echo h(roleLabel($user['role'])); ?>密码</h3>
                    <button class="modal-close" type="button" data-close-modal>&times;</button>
                </div>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="change_password">
                    <div>
                        <label for="current_password">当前密码</label>
                        <input id="current_password" name="current_password" type="password" required>
                    </div>
                    <div>
                        <label for="new_password">新密码</label>
                        <input id="new_password" name="new_password" type="password" required>
                    </div>
                    <div>
                        <label for="confirm_password">确认新密码</label>
                        <input id="confirm_password" name="confirm_password" type="password" required>
                    </div>
                    <button class="btn btn-secondary" type="submit">更新密码</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
document.querySelectorAll('[data-open-modal]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var name = btn.getAttribute('data-open-modal');
        var modal = document.getElementById('modal-' + name);
        if (modal) modal.classList.add('open');
    });
});

document.querySelectorAll('[data-close-modal]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var modal = btn.closest('.modal');
        if (modal) modal.classList.remove('open');
    });
});

document.querySelectorAll('.modal').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.classList.remove('open');
        }
    });
});
</script>
</body>
</html>
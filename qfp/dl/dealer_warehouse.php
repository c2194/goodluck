<?php
session_start();
require_once __DIR__ . '/db.php';

$pdo = getDb();

function hd(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$user = null;
if (!empty($_SESSION['account_user']) && $_SESSION['account_user']['role'] === 'dealer') {
    $user = $_SESSION['account_user'];
}
if (!$user) {
    header('Location: account_manager.php');
    exit;
}

// POST
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
    $dealerId = (int) $user['id'];

    // 待收货包裹 (设备 factory_status=8，即已发经销商)
    if ($ajaxAction === 'load_pending') {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.tracking_number, p.remark, p.created_at, p.shipped_at,
                    p.recipient_name, p.recipient_address, p.recipient_phone,
                    COUNT(pd.device_id) AS device_count
             FROM parcels p
             JOIN parcel_devices pd ON pd.parcel_id = p.id
             JOIN devices d ON d.id = pd.device_id AND d.factory_status = 8
             WHERE p.dealer_id = ?
             GROUP BY p.id
             ORDER BY p.shipped_at DESC, p.id DESC'
        );
        $stmt->execute([$dealerId]);
        echo json_encode(['ok' => true, 'parcels' => $stmt->fetchAll()]);
        exit;
    }

    // 已入库设备 (factory_status=9)
    if ($ajaxAction === 'load_stocked') {
        $stmt = $pdo->prepare(
            'SELECT d.id, d.month_year, d.mac_b62, d.mac_hex, d.registered_at
             FROM devices d
             JOIN parcel_devices pd ON pd.device_id = d.id
             JOIN parcels p ON p.id = pd.parcel_id AND p.dealer_id = ?
             WHERE d.factory_status = 9
             ORDER BY d.id DESC'
        );
        $stmt->execute([$dealerId]);
        echo json_encode(['ok' => true, 'devices' => $stmt->fetchAll()]);
        exit;
    }

    // 包裹内设备
    if ($ajaxAction === 'load_parcel_devices') {
        $parcelId = intval($_GET['parcel_id'] ?? 0);
        if ($parcelId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $check = $pdo->prepare('SELECT id FROM parcels WHERE id = ? AND dealer_id = ?');
        $check->execute([$parcelId, $dealerId]);
        if (!$check->fetch()) {
            echo json_encode(['error' => '无权访问此包裹']);
            exit;
        }
        $stmt = $pdo->prepare(
            'SELECT d.id, d.month_year, d.mac_b62, d.mac_hex, d.factory_status
             FROM parcel_devices pd
             JOIN devices d ON d.id = pd.device_id
             WHERE pd.parcel_id = ?
             ORDER BY d.id'
        );
        $stmt->execute([$parcelId]);
        echo json_encode(['ok' => true, 'devices' => $stmt->fetchAll()]);
        exit;
    }

    // 确认收货：factory_status 8 → 9
    if ($ajaxAction === 'confirm_receipt') {
        $input = json_decode(file_get_contents('php://input'), true);
        $parcelId = intval($input['parcel_id'] ?? 0);
        if ($parcelId <= 0) {
            echo json_encode(['error' => '参数无效']);
            exit;
        }
        $check = $pdo->prepare('SELECT id FROM parcels WHERE id = ? AND dealer_id = ?');
        $check->execute([$parcelId, $dealerId]);
        if (!$check->fetch()) {
            echo json_encode(['error' => '无权操作此包裹']);
            exit;
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE devices SET factory_status = 9
                 WHERE id IN (SELECT device_id FROM parcel_devices WHERE parcel_id = ?)
                   AND factory_status = 8'
            );
            $stmt->execute([$parcelId]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => '操作失败：' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => '未知操作']);
    exit;
}

$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending', 'stocked'], true)) {
    $tab = 'pending';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>经销商仓库</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
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
            transition: color 0.2s, border-color 0.2s;
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
        .confirm-bar { padding: 16px 20px; border-top: 1px solid #e3ebf7; background: #fafcff; text-align: center; }
        .confirm-bar .btn { min-width: 200px; padding: 14px 28px; font-size: 16px; }
        .loading { text-align: center; padding: 30px; color: #73819a; }
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
            <h2>经销商仓库</h2>
            <p>当前登录：<?php echo hd($user['display_name']); ?>（<?php echo hd($user['username']); ?>）</p>
        </div>
        <div class="menu-actions">
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
        </div>

        <div class="tab-content" id="tab-pending" style="<?php echo $tab !== 'pending' ? 'display:none;' : ''; ?>">
            <div class="loading" id="loading-pending">正在加载...</div>
            <div id="content-pending"></div>
        </div>

        <div class="tab-content" id="tab-stocked" style="<?php echo $tab !== 'stocked' ? 'display:none;' : ''; ?>">
            <div class="loading" id="loading-stocked">正在加载...</div>
            <div id="content-stocked"></div>
        </div>
    </div>
</div>

<script>
(function() {
    var currentTab = <?php echo json_encode($tab); ?>;

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

        if (tab === 'stocked') {
            fetch('?ajax=load_stocked')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    loadingEl.style.display = 'none';
                    if (!data.ok) { contentEl.innerHTML = '<div class="empty-state">' + esc(data.error || '加载失败') + '</div>'; return; }
                    updateBadge('stocked', data.devices.length);
                    if (data.devices.length === 0) {
                        contentEl.innerHTML = '<div class="empty-state">当前没有已入库的设备。</div>';
                        return;
                    }
                    var html = '<div class="device-list" style="background:#fff;border:1px solid #e3ebf7;border-radius:16px;">';
                    html += '<div class="device-panel-head"><h4>已入库设备（' + data.devices.length + ' 台）</h4></div>';
                    data.devices.forEach(function(d) {
                        var code = d.month_year + '/' + d.mac_b62;
                        html += '<div class="device-item" style="padding:12px 20px;">';
                        html += '<div><div class="device-code">' + esc(code) + '</div>';
                        html += '<div style="font-size:12px;color:#73819a;margin-top:2px;">MAC: ' + esc(d.mac_hex) + '</div></div>';
                        html += '<span class="device-status status-stocked">已入库</span>';
                        html += '</div>';
                    });
                    html += '</div>';
                    contentEl.innerHTML = html;
                })
                .catch(function() { loadingEl.style.display = 'none'; contentEl.innerHTML = '<div class="empty-state">网络错误</div>'; });
        } else {
            fetch('?ajax=load_pending')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    loadingEl.style.display = 'none';
                    if (!data.ok) { contentEl.innerHTML = '<div class="empty-state">' + esc(data.error || '加载失败') + '</div>'; return; }
                    updateBadge('pending', data.parcels.length);
                    if (data.parcels.length === 0) {
                        contentEl.innerHTML = '<div class="empty-state">当前没有待收货的包裹。</div>';
                        return;
                    }
                    renderParcels(contentEl, data.parcels);
                })
                .catch(function() { loadingEl.style.display = 'none'; contentEl.innerHTML = '<div class="empty-state">网络错误</div>'; });
        }
    }

    function updateBadge(tab, count) {
        var badge = document.getElementById('badge-' + tab);
        if (badge) {
            if (count > 0) { badge.textContent = count; badge.style.display = ''; }
            else { badge.style.display = 'none'; }
        }
    }

    function renderParcels(container, parcels) {
        var grid = document.createElement('div');
        grid.className = 'parcels-grid';
        var panel = document.createElement('div');
        panel.className = 'device-panel';

        parcels.forEach(function(p) {
            var card = document.createElement('div');
            card.className = 'parcel-card';
            var sd = p.shipped_at ? fmtTime(p.shipped_at) : '未知';
            card.innerHTML =
                '<div class="parcel-title"><span class="icon">📦</span>包裹 #' + esc(String(p.id)) + '</div>' +
                '<div class="parcel-meta">' +
                '快递单号：' + esc(p.tracking_number || '无') + '<br>' +
                '设备数量：' + esc(String(p.device_count)) + ' 台<br>' +
                '发货时间：' + esc(sd) +
                (p.recipient_name ? '<br>收件人：' + esc(p.recipient_name) : '') +
                (p.recipient_phone ? '<br>收件电话：' + esc(p.recipient_phone) : '') +
                (p.recipient_address ? '<br>收件地址：' + esc(p.recipient_address) : '') +
                (p.remark ? '<br>备注：' + esc(p.remark) : '') + '</div>';
            card.addEventListener('click', function() {
                grid.querySelectorAll('.parcel-card').forEach(function(c) { c.classList.remove('active'); });
                card.classList.add('active');
                loadDevices(panel, p.id);
            });
            grid.appendChild(card);
        });
        container.appendChild(grid);
        container.appendChild(panel);
    }

    function loadDevices(panel, parcelId) {
        panel.className = 'device-panel open';
        panel.innerHTML = '<div class="loading">正在加载设备列表...</div>';
        fetch('?ajax=load_parcel_devices&parcel_id=' + parcelId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) { panel.innerHTML = '<div style="padding:20px;color:#af1f2d;">' + esc(data.error || '加载失败') + '</div>'; return; }
                var devices = data.devices;
                var html = '<div class="device-panel-head"><h4>包裹 #' + esc(String(parcelId)) + ' — 设备列表（' + devices.length + ' 台）</h4>';
                html += '<button class="btn btn-ghost" style="padding:6px 14px;font-size:13px;" onclick="this.closest(\'.device-panel\').className=\'device-panel\';">收起</button></div>';
                html += '<div class="device-list">';
                devices.forEach(function(d) {
                    var code = d.month_year + '/' + d.mac_b62;
                    var sc = d.factory_status == 8 ? 'status-pending' : 'status-stocked';
                    var st = d.factory_status == 8 ? '待收货' : '已入库';
                    html += '<div class="device-item"><div><div class="device-code">' + esc(code) + '</div>';
                    html += '<div style="font-size:12px;color:#73819a;margin-top:2px;">MAC: ' + esc(d.mac_hex) + '</div></div>';
                    html += '<span class="device-status ' + sc + '">' + st + '</span></div>';
                });
                html += '</div>';
                var hasPending = devices.some(function(d) { return d.factory_status == 8; });
                if (hasPending) {
                    html += '<div class="confirm-bar"><button class="btn btn-success" id="btn-confirm-' + parcelId + '" onclick="doConfirm(' + parcelId + ')">确认收货</button></div>';
                }
                panel.innerHTML = html;
            })
            .catch(function() { panel.innerHTML = '<div style="padding:20px;color:#af1f2d;">网络错误</div>'; });
    }

    window.doConfirm = function(parcelId) {
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
        .catch(function() { alert('网络错误'); btn.disabled = false; btn.textContent = '确认收货'; });
    };

    function refreshBadges() {
        fetch('?ajax=load_pending').then(function(r) { return r.json(); }).then(function(d) { if (d.ok) updateBadge('pending', d.parcels.length); });
        fetch('?ajax=load_stocked').then(function(r) { return r.json(); }).then(function(d) { if (d.ok) updateBadge('stocked', d.devices.length); });
    }

    function fmtTime(ts) {
        var d = new Date(ts * 1000);
        var pad = function(n) { return n < 10 ? '0' + n : n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    loadTab(currentTab);
    refreshBadges();
})();
</script>
</body>
</html>

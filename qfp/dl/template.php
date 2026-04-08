<?php
session_start();

require_once __DIR__ . '/db.php';

$pdo = getDb();

$pdo->exec('
    CREATE TABLE IF NOT EXISTS template_types (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL UNIQUE,
        created_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS templates (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        type_id    INTEGER NOT NULL,
        name       TEXT    NOT NULL,
        bg_img     TEXT    NOT NULL DEFAULT \'\',
        top_img    TEXT    NOT NULL DEFAULT \'\',
        created_at INTEGER NOT NULL
    );
');
// 迁移：补充 config 列（旧表可能没有）
try { $pdo->exec("ALTER TABLE templates ADD COLUMN config TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $_) {}
// 迁移：补充 tpl_name 列（模板编辑器里设置的展示名）
try { $pdo->exec("ALTER TABLE templates ADD COLUMN tpl_name TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $_) {}
// 迁移：补充 thumb_img 列（发布时合成的缩略图）
try { $pdo->exec("ALTER TABLE templates ADD COLUMN thumb_img TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $_) {}

$messages  = [];
$errors    = [];
$activeModal = '';

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// ── AJAX GET: 列出已发布模板 ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $rows = $pdo->query("SELECT id, name, tpl_name, bg_img, top_img, thumb_img FROM templates WHERE config != '' ORDER BY created_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $r) {
        $label = trim((string)($r['tpl_name'] ?? '')) ?: trim((string)($r['name'] ?? ''));
        $thumb = trim((string)($r['thumb_img'] ?? ''));
        if ($thumb === '') $thumb = trim((string)($r['bg_img'] ?? ''));
        $result[] = [
            'id'    => (int)$r['id'],
            'name'  => $label,
            'thumb' => $thumb,
            'bg'    => (string)($r['bg_img'] ?? ''),
            'top'   => (string)($r['top_img'] ?? ''),
        ];
    }
    echo json_encode($result);
    exit;
}

// ── AJAX GET: 读取模板配置 ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_config') {
    header('Content-Type: application/json; charset=utf-8');
    $templateId = (int) ($_GET['template_id'] ?? 0);
    if ($templateId <= 0) {
        echo json_encode(['ok' => false, 'error' => '参数错误']); exit;
    }
    $stmt = $pdo->prepare('SELECT config, tpl_name FROM templates WHERE id = ?');
    $stmt->execute([$templateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => '模板不存在']); exit;
    }
    echo json_encode(['ok' => true, 'config' => $row['config'], 'tpl_name' => $row['tpl_name'] ?? '']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── AJAX: 创建模板记录 ──────────────────────────────────────
    if ($action === 'create_template') {
        header('Content-Type: application/json; charset=utf-8');
        $typeId = (int) ($_POST['type_id'] ?? 0);
        $name   = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || $typeId <= 0) {
            echo json_encode(['ok' => false, 'error' => '参数错误']); exit;
        }
        $chk = $pdo->prepare('SELECT id FROM template_types WHERE id = ?');
        $chk->execute([$typeId]);
        if (!$chk->fetch()) {
            echo json_encode(['ok' => false, 'error' => '类型不存在']); exit;
        }
        $ins = $pdo->prepare('INSERT INTO templates (type_id, name, created_at) VALUES (?, ?, ?)');
        $ins->execute([$typeId, $name, time()]);
        echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        exit;
    }

    // ── AJAX: 上传图片 ─────────────────────────────────────────
    if ($action === 'upload_img') {
        ob_start();
        header('Content-Type: application/json; charset=utf-8');
        try {
            $templateId = (int) ($_POST['template_id'] ?? 0);
            $imgType    = (string) ($_POST['img_type'] ?? '');
            if ($templateId <= 0 || !in_array($imgType, ['bg', 'top'], true)) {
                ob_end_clean(); echo json_encode(['ok' => false, 'error' => '参数错误']); exit;
            }
            $chk = $pdo->prepare('SELECT id FROM templates WHERE id = ?');
            $chk->execute([$templateId]);
            if (!$chk->fetch()) {
                ob_end_clean(); echo json_encode(['ok' => false, 'error' => '模板不存在']); exit;
            }
            $file = $_FILES['file'] ?? null;
            if (!$file || (int) $file['error'] !== UPLOAD_ERR_OK) {
                ob_end_clean(); echo json_encode(['ok' => false, 'error' => '上传失败，错误码：' . ($file['error'] ?? -1)]); exit;
            }
            // MIME 检测：优先 finfo，回退扩展名
            $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $mime = '';
            if (class_exists('finfo')) {
                $fi   = new \finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $fi->file($file['tmp_name']);
            } elseif (function_exists('mime_content_type')) {
                $mime = (string) mime_content_type($file['tmp_name']);
            }
            if (!isset($mimeMap[$mime])) {
                // 扩展名兜底
                $extRaw = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
                $extMap = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
                if (!isset($extMap[$extRaw])) {
                    ob_end_clean(); echo json_encode(['ok' => false, 'error' => '只支持 jpg/png/gif/webp（检测类型：' . $mime . '）']); exit;
                }
                $mime = array_search($extMap[$extRaw], $mimeMap) ?: 'image/jpeg';
                $ext  = $extMap[$extRaw];
            } else {
                $ext = $mimeMap[$mime];
            }
            $dir = __DIR__ . '/template/' . $templateId . '/';
            if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
                ob_end_clean(); echo json_encode(['ok' => false, 'error' => '无法创建目录，请检查服务器权限']); exit;
            }
            $filename = $imgType . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                ob_end_clean(); echo json_encode(['ok' => false, 'error' => '保存失败，请检查目录权限']); exit;
            }
            $col = ($imgType === 'bg') ? 'bg_img' : 'top_img';
            $rel = 'template/' . $templateId . '/' . $filename;
            $upd = $pdo->prepare("UPDATE templates SET {$col} = ? WHERE id = ?");
            $upd->execute([$rel, $templateId]);
            ob_end_clean();
            echo json_encode(['ok' => true, 'path' => $rel . '?t=' . time()]);
            exit;
        } catch (\Throwable $ex) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => '服务器错误：' . $ex->getMessage()]);
            exit;
        }
    }

    // ── AJAX: 删除模板 ──────────────────────────────────────────
    if ($action === 'delete_template') {
        header('Content-Type: application/json; charset=utf-8');
        $templateId = (int) ($_POST['template_id'] ?? 0);
        if ($templateId <= 0) {
            echo json_encode(['ok' => false, 'error' => '参数错误']); exit;
        }
        $chk = $pdo->prepare('SELECT id FROM templates WHERE id = ?');
        $chk->execute([$templateId]);
        if (!$chk->fetch()) {
            echo json_encode(['ok' => false, 'error' => '模板不存在']); exit;
        }
        // 删除模板文件目录
        $dir = __DIR__ . '/template/' . $templateId;
        if (is_dir($dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
        }
        // 删除数据库记录
        $del = $pdo->prepare('DELETE FROM templates WHERE id = ?');
        $del->execute([$templateId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: 保存模板配置 ────────────────────────────────────────
    if ($action === 'save_config') {
        header('Content-Type: application/json; charset=utf-8');
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $tplName    = trim((string) ($_POST['tpl_name'] ?? ''));
        $config     = (string) ($_POST['config'] ?? '');
        if ($templateId <= 0) {
            echo json_encode(['ok' => false, 'error' => '参数错误']); exit;
        }
        // 可选：保存新背景图
        $bgRel  = '';
        $bgFile = $_FILES['bg'] ?? null;
        if ($bgFile && (int) $bgFile['error'] === UPLOAD_ERR_OK) {
            $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $mime = '';
            if (class_exists('finfo')) {
                $fi   = new \finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $fi->file($bgFile['tmp_name']);
            } elseif (function_exists('mime_content_type')) {
                $mime = (string) mime_content_type($bgFile['tmp_name']);
            }
            if (!isset($mimeMap[$mime])) {
                $extRaw = strtolower(pathinfo((string)($bgFile['name'] ?? ''), PATHINFO_EXTENSION));
                $extMap = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
                $ext = $extMap[$extRaw] ?? null;
            } else {
                $ext = $mimeMap[$mime];
            }
            if ($ext) {
                $dir = __DIR__ . '/template/' . $templateId . '/';
                if (!is_dir($dir)) @mkdir($dir, 0750, true);
                $bgFilename = 'bg.' . $ext;
                if (move_uploaded_file($bgFile['tmp_name'], $dir . $bgFilename)) {
                    $bgRel = 'template/' . $templateId . '/' . $bgFilename;
                    $updBg = $pdo->prepare('UPDATE templates SET bg_img = ? WHERE id = ?');
                    $updBg->execute([$bgRel, $templateId]);
                }
            }
        }
        // 可选：保存合成缩略图
        $thumbRel  = '';
        $thumbFile = $_FILES['thumb'] ?? null;
        if ($thumbFile && (int) $thumbFile['error'] === UPLOAD_ERR_OK) {
            $dir = __DIR__ . '/template/' . $templateId . '/';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            if (move_uploaded_file($thumbFile['tmp_name'], $dir . 'thumb.png')) {
                $thumbRel = 'template/' . $templateId . '/thumb.png';
            }
        }
        if ($thumbRel !== '') {
            $upd = $pdo->prepare('UPDATE templates SET config = ?, tpl_name = ?, thumb_img = ? WHERE id = ?');
            $upd->execute([$config, $tplName, $thumbRel, $templateId]);
        } else {
            $upd = $pdo->prepare('UPDATE templates SET config = ?, tpl_name = ? WHERE id = ?');
            $upd->execute([$config, $tplName, $templateId]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'create_type') {
        $activeModal = 'create-type';
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $errors[] = '类型名称不能为空';
        }
        if (!$errors) {
            try {
                $stmt = $pdo->prepare('INSERT INTO template_types (name, created_at) VALUES (?, ?)');
                $stmt->execute([$name, time()]);
                $messages[]  = '模板类型「' . $name . '」已创建';
                $activeModal = '';
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    $errors[] = '该类型名称已存在';
                } else {
                    throw $e;
                }
            }
        }
    }

    if ($action === 'rename_type') {
        $id   = (int) ($_POST['type_id'] ?? 0);
        $activeModal = 'rename-type-' . $id;
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $errors[] = '类型名称不能为空';
        }
        if ($id <= 0) {
            $errors[] = '无效的类型 ID';
        }
        if (!$errors) {
            try {
                $stmt = $pdo->prepare('UPDATE template_types SET name = ? WHERE id = ?');
                $stmt->execute([$name, $id]);
                $messages[]  = '类型名称已更新为「' . $name . '」';
                $activeModal = '';
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    $errors[] = '该类型名称已存在';
                } else {
                    throw $e;
                }
            }
        }
    }
}

$types = $pdo->query('SELECT * FROM template_types ORDER BY created_at DESC, id DESC')->fetchAll();

$allTemplates = $pdo->query('SELECT * FROM templates ORDER BY created_at ASC, id ASC')->fetchAll();
$tplByType = [];
foreach ($allTemplates as $tpl) {
    $tplByType[(int) $tpl['type_id']][] = $tpl;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>模块控制 · 模板类型</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "PingFang SC", sans-serif;
            background:
                radial-gradient(circle at top left,  rgba(63, 137, 255, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(22, 193, 139, 0.16), transparent 24%),
                linear-gradient(135deg, #eef4ff 0%, #f9fbff 45%, #f5fff8 100%);
            color: #1e2a3a;
        }
        .page {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px 60px;
        }
        .hero { margin-bottom: 28px; }
        .hero h1 { margin: 0 0 8px; font-size: 32px; letter-spacing: 1px; }
        .hero p  { margin: 0; color: #5a6780; font-size: 15px; }

        .panel {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(115, 137, 167, 0.18);
            border-radius: 22px;
            box-shadow: 0 18px 45px rgba(51, 83, 135, 0.12);
            backdrop-filter: blur(10px);
        }

        .messages, .errors {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 14px;
        }
        .messages { background: #ecfbf3; color: #11663f; }
        .errors   { background: #fff1f1; color: #af1f2d; }

        .form-grid { display: grid; gap: 14px; }
        label {
            display: block;
            font-size: 13px;
            color: #56637b;
            margin-bottom: 6px;
        }
        input {
            width: 100%;
            border: 1px solid #d7e0ef;
            background: #fff;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            color: #243248;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input:focus {
            border-color: #5f87ff;
            box-shadow: 0 0 0 3px rgba(95, 135, 255, 0.12);
        }

        .btn {
            border: none;
            border-radius: 14px;
            padding: 11px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(44, 87, 181, 0.14); }
        .btn-primary   { background: linear-gradient(135deg, #3068ff, #5a8cff); color: #fff; }
        .btn-ghost     { background: rgba(255,255,255,0.82); color: #26446f; border: 1px solid #d7e0ef; }
        .btn-sm        { padding: 7px 14px; font-size: 13px; border-radius: 10px; }

        .card { padding: 26px; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 22px;
        }
        .topbar h2 { margin: 0; font-size: 22px; }

        .list { display: grid; gap: 12px; }
        .type-item {
            display: flex;
            flex-direction: column;
            gap: 0;
            padding: 16px 20px;
            border: 1px solid #e3ebf7;
            border-radius: 16px;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(246,249,255,0.92));
        }
        .type-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
        }
        .type-name { font-size: 16px; font-weight: 700; }
        .type-meta { font-size: 12px; color: #8898b2; margin-top: 4px; }
        .type-actions { display: flex; gap: 8px; flex-shrink: 0; }

        /* 模板缩略图网格 */
        .tpl-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #e8eef8;
        }
        .tpl-card {
            width: 72px;
            text-decoration: none;
            color: inherit;
            border: 1px solid #e3ebf7;
            border-radius: 8px;
            overflow: hidden;
            background: #f9fbff;
            transition: box-shadow .15s, transform .15s;
            position: relative;
        }
        .tpl-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(44,87,181,.14); border-color: #a5c0f3; }
        .tpl-card:hover .tpl-del { opacity: 1; }
        .tpl-del {
            position: absolute; top: 2px; left: 2px;
            width: 18px; height: 18px; border-radius: 50%;
            background: rgba(220,38,38,.82); color: #fff;
            font-size: 12px; line-height: 18px; text-align: center;
            cursor: pointer; border: none; padding: 0;
            opacity: 0; transition: opacity .15s;
            z-index: 2;
        }
        .tpl-del:hover { background: #dc2626; }
        .tpl-card.published { border-color: #bcd0f7; }
        .tpl-thumb { width: 100%; aspect-ratio: 270/400; object-fit: cover; display: block; }
        .tpl-thumb-ph { width: 100%; aspect-ratio: 270/400; background: #e8eef8; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #8898b2; }
        .tpl-name { font-size: 11px; color: #26446f; padding: 4px 5px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tpl-badge { position: absolute; top: 3px; right: 3px; background: #3068ff; color: #fff; font-size: 9px; padding: 1px 4px; border-radius: 4px; line-height: 1.6; }

        .empty {
            padding: 30px;
            border: 1px dashed #cad6ec;
            border-radius: 16px;
            color: #73819a;
            text-align: center;
            background: rgba(250, 252, 255, 0.9);
        }

        /* modal */
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
        .modal.open { display: flex; }
        .modal-card {
            width: min(100%, 440px);
            max-height: calc(100vh - 40px);
            overflow: auto;
            padding: 26px;
        }
        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 12px;
        }
        .modal-head h3 { margin: 0; font-size: 19px; }
        .modal-close {
            border: none;
            background: transparent;
            color: #74839b;
            font-size: 28px;
            line-height: 1;
            cursor: pointer;
            padding: 0 4px;
        }

        /* upload area */
        .upload-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-top: 4px;
        }
        .upload-area {
            display: block;
            border: 2px dashed #c8d8f0;
            border-radius: 14px;
            padding: 22px 12px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            position: relative;
            overflow: hidden;
        }
        .upload-area:hover { border-color: #5f87ff; background: rgba(95,135,255,.04); }
        .upload-area.uploading { border-color: #f59e0b; background: rgba(245,158,11,.04); }
        .upload-area input[type=file] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .up-icon  { font-size: 26px; margin-bottom: 4px; pointer-events: none; }
        .up-label { font-size: 14px; color: #26446f; font-weight: 600; pointer-events: none; }
        .up-hint  { font-size: 12px; color: #8898b2; margin-top: 5px; pointer-events: none; }
        .upload-thumb {
            width: 100%;
            max-height: 160px;
            object-fit: contain;
            border-radius: 10px;
            margin-top: 10px;
            border: 1px solid #e3ebf7;
            display: none;
            background: #f5f8ff;
        }
        .upload-ok  { color: #16a34a; font-size: 13px; margin-top: 6px; display: none; }
        .upload-err { color: #dc2626; font-size: 13px; margin-top: 6px; min-height: 18px; }
        .upload-section-title { font-size: 13px; color: #56637b; font-weight: 600; margin-bottom: 8px; }
        @media (max-width: 440px) { .upload-grid { grid-template-columns: 1fr; } }

        @media (max-width: 540px) {
            .type-item { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <h1>模块控制</h1>
        <p>管理祝福牌模板类型，为每种类型创建对应的祝福牌模板。</p>
    </div>

    <?php if ($messages): ?>
        <div class="messages"><?= h(implode('；', $messages)); ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="errors"><?= h(implode('；', $errors)); ?></div>
    <?php endif; ?>

    <div class="panel card">
        <div class="topbar">
            <h2>模板类型</h2>
            <button class="btn btn-primary" type="button" data-open-modal="create-type">+ 创建模板类型</button>
        </div>

        <?php if (!$types): ?>
            <div class="empty">还没有模板类型，点击右上角「创建模板类型」开始添加。</div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($types as $type): ?>
                    <?php $typeTpls = $tplByType[(int) $type['id']] ?? []; ?>
                    <div class="type-item">
                        <div class="type-item-header">
                            <div>
                                <div class="type-name"><?= h($type['name']); ?></div>
                                <div class="type-meta">创建时间：<?= h(date('Y-m-d H:i', (int) $type['created_at'])); ?>　共 <?= count($typeTpls); ?> 个模板</div>
                            </div>
                            <div class="type-actions">
                                <button class="btn btn-ghost btn-sm"
                                        type="button"
                                        data-open-modal="rename-type-<?= (int) $type['id']; ?>">修改</button>
                                <button class="btn btn-primary btn-sm" type="button"
                                        onclick="openNewTemplate(<?= (int) $type['id']; ?>, '<?= h($type['name']); ?>')">创建</button>
                            </div>
                        </div>
                        <?php if ($typeTpls): ?>
                        <div class="tpl-grid">
                            <?php foreach ($typeTpls as $tpl): ?>
                                <?php
                                    $tplLink = 'template/create.html?template_id=' . (int)$tpl['id']
                                        . '&bg='  . urlencode((string)($tpl['bg_img']  ?? ''))
                                        . '&top=' . urlencode((string)($tpl['top_img'] ?? ''));
                                    $tplLabel = trim((string)($tpl['tpl_name'] ?? '')) ?: trim((string)($tpl['name'] ?? ''));
                                    $isPublished = trim((string)($tpl['config'] ?? '')) !== '';
                                ?>
                                <a class="tpl-card<?= $isPublished ? ' published' : ''; ?>" href="<?= h($tplLink); ?>" title="<?= h($tplLabel); ?>">
                                    <button class="tpl-del" type="button" onclick="deleteTemplate(event, <?= (int)$tpl['id']; ?>, '<?= h(addslashes($tplLabel)); ?>')" title="删除">&times;</button>
                                    <?php
                                        $thumbSrc = trim((string)($tpl['thumb_img'] ?? ''));
                                        if ($thumbSrc === '') $thumbSrc = trim((string)($tpl['bg_img'] ?? ''));
                                    ?>
                                    <?php if ($thumbSrc !== ''): ?>
                                        <img class="tpl-thumb" src="<?= h($thumbSrc); ?>?t=<?= (int)$tpl['created_at']; ?>" alt="">
                                    <?php else: ?>
                                        <div class="tpl-thumb-ph">无图</div>
                                    <?php endif; ?>
                                    <div class="tpl-name"><?= h($tplLabel); ?></div>
                                    <?php if ($isPublished): ?><span class="tpl-badge">已发布</span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 创建类型 modal -->
<div class="modal<?= $activeModal === 'create-type' ? ' open' : ''; ?>" id="modal-create-type">
    <div class="panel modal-card">
        <div class="modal-head">
            <h3>创建模板类型</h3>
            <button class="modal-close" type="button" data-close-modal>&times;</button>
        </div>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_type">
            <div>
                <label for="create_name">类型名称</label>
                <input id="create_name" name="name" type="text"
                       placeholder="例如：生日祝福、新年贺卡……" required autofocus>
            </div>
            <button class="btn btn-primary" type="submit">确定</button>
        </form>
    </div>
</div>

<!-- 修改类型 modals（每个类型一个） -->
<?php foreach ($types as $type): ?>
<div class="modal<?= $activeModal === 'rename-type-' . (int) $type['id'] ? ' open' : ''; ?>"
     id="modal-rename-type-<?= (int) $type['id']; ?>">
    <div class="panel modal-card">
        <div class="modal-head">
            <h3>修改类型名称</h3>
            <button class="modal-close" type="button" data-close-modal>&times;</button>
        </div>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="rename_type">
            <input type="hidden" name="type_id" value="<?= (int) $type['id']; ?>">
            <div>
                <label for="rename_name_<?= (int) $type['id']; ?>">新名称</label>
                <input id="rename_name_<?= (int) $type['id']; ?>"
                       name="name" type="text"
                       value="<?= h($type['name']); ?>" required>
            </div>
            <button class="btn btn-primary" type="submit">确定修改</button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- ══ 新建模板 Step1：输入名称 ══ -->
<div class="modal" id="modal-new-template">
    <div class="panel modal-card">
        <div class="modal-head">
            <div>
                <h3>新建模板</h3>
                <p id="ntp-type-label" style="margin:4px 0 0;font-size:13px;color:#627188;"></p>
            </div>
            <button class="modal-close" type="button" data-close-modal>&times;</button>
        </div>
        <form id="form-new-template" class="form-grid">
            <div>
                <label for="ntp-name">模板名称</label>
                <input id="ntp-name" type="text" placeholder="请输入模板名称" required autocomplete="off">
            </div>
            <div id="ntp-err" style="color:#dc2626;font-size:13px;min-height:18px;"></div>
            <button class="btn btn-primary" type="submit" id="ntp-submit">确定</button>
        </form>
    </div>
</div>

<!-- ══ 新建模板 Step2：上传图片 ══ -->
<div class="modal" id="modal-upload-imgs">
    <div class="panel modal-card" style="width:min(100%,560px);">
        <div class="modal-head">
            <div>
                <h3>上传图片</h3>
                <p id="up-tpl-name" style="margin:4px 0 0;font-size:13px;color:#627188;"></p>
            </div>
            <button class="modal-close" type="button" data-close-modal>&times;</button>
        </div>

        <div class="upload-grid">
            <!-- 底图 -->
            <div>
                <div class="upload-section-title">底图 <span style="color:#dc2626;font-size:12px;">*必填</span></div>
                <label class="upload-area" id="up-area-bg">
                    <input type="file" id="up-file-bg" accept="image/*">
                    <div class="up-icon">🖼</div>
                    <div class="up-label">上传底图</div>
                    <div class="up-hint">jpg / png / webp</div>
                </label>
                <img id="up-thumb-bg" class="upload-thumb" alt="底图预览">
                <div id="up-ok-bg"  class="upload-ok">✓ 上传成功</div>
                <div id="up-err-bg" class="upload-err"></div>
            </div>
            <!-- 顶图 -->
            <div>
                <div class="upload-section-title">顶图 <span style="color:#8898b2;font-size:12px;">可选</span></div>
                <label class="upload-area" id="up-area-top">
                    <input type="file" id="up-file-top" accept="image/*">
                    <div class="up-icon">🖼</div>
                    <div class="up-label">上传顶图</div>
                    <div class="up-hint">jpg / png / webp</div>
                </label>
                <img id="up-thumb-top" class="upload-thumb" alt="顶图预览">
                <div id="up-ok-top"  class="upload-ok">✓ 上传成功</div>
                <div id="up-err-top" class="upload-err"></div>
            </div>
        </div>

        <div style="margin-top:22px;text-align:right;">
            <button id="btn-finish" class="btn btn-primary" type="button" style="display:none;">创建</button>
        </div>
    </div>
</div>

<script>
// ── 公共 modal 开关 ──────────────────────────────────────────────
document.addEventListener('click', function (e) {
    const opener = e.target.closest('[data-open-modal]');
    if (opener) {
        const id = opener.dataset.openModal;
        document.getElementById('modal-' + id)?.classList.add('open');
        const input = document.getElementById('modal-' + id)?.querySelector('input[type=text]');
        if (input) setTimeout(() => input.focus(), 60);
        return;
    }
    if (e.target.closest('[data-close-modal]')) {
        e.target.closest('.modal')?.classList.remove('open');
        return;
    }
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('open');
    }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open'));
    }
});

// ── 新建模板流程 ─────────────────────────────────────────────────
let _newTplTypeId = 0;
let _newTplId     = 0;
let _uploadedSet  = new Set(); // 'bg' | 'top'
let _uploadedPaths = {};        // { bg: '...', top: '...' }

function openNewTemplate(typeId, typeName) {
    _newTplTypeId = typeId;
    document.getElementById('ntp-type-label').textContent = '类型：' + typeName;
    document.getElementById('ntp-name').value = '';
    document.getElementById('ntp-err').textContent = '';
    document.getElementById('ntp-submit').disabled = false;
    document.getElementById('modal-new-template').classList.add('open');
    setTimeout(() => document.getElementById('ntp-name').focus(), 60);
}

document.getElementById('form-new-template').addEventListener('submit', async function (e) {
    e.preventDefault();
    const name  = document.getElementById('ntp-name').value.trim();
    const errEl = document.getElementById('ntp-err');
    if (!name) { errEl.textContent = '请输入模板名称'; return; }
    const btn = document.getElementById('ntp-submit');
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('action', 'create_template');
        fd.append('type_id', _newTplTypeId);
        fd.append('name', name);
        const res  = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { errEl.textContent = data.error || '创建失败'; return; }
        _newTplId    = data.id;
        _uploadedSet = new Set();
        _uploadedPaths = {};
        // 重置上传区
        ['bg', 'top'].forEach(t => {
            document.getElementById('up-thumb-' + t).style.display = 'none';
            document.getElementById('up-thumb-' + t).src = '';
            document.getElementById('up-ok-' + t).style.display = 'none';
            document.getElementById('up-err-' + t).textContent = '';
            document.getElementById('up-file-' + t).value = '';
            document.getElementById('up-area-' + t).classList.remove('uploading');
        });
        document.getElementById('btn-finish').style.display = 'none';
        document.getElementById('up-tpl-name').textContent = name;
        document.getElementById('modal-new-template').classList.remove('open');
        document.getElementById('modal-upload-imgs').classList.add('open');
    } catch {
        errEl.textContent = '网络错误，请重试';
    } finally {
        btn.disabled = false;
    }
});

async function doUpload(imgType) {
    const fileInput = document.getElementById('up-file-' + imgType);
    const file      = fileInput.files[0];
    if (!file) return;
    const area  = document.getElementById('up-area-' + imgType);
    const okEl  = document.getElementById('up-ok-'   + imgType);
    const errEl = document.getElementById('up-err-'  + imgType);
    const thumb = document.getElementById('up-thumb-' + imgType);
    // 本地预览（即时反馈）
    thumb.src = URL.createObjectURL(file);
    thumb.style.display = 'block';
    okEl.style.display  = 'none';
    errEl.textContent   = '';
    area.classList.add('uploading');
    const fd = new FormData();
    fd.append('action', 'upload_img');
    fd.append('template_id', _newTplId);
    fd.append('img_type', imgType);
    fd.append('file', file);
    try {
        const res = await fetch(location.pathname, { method: 'POST', body: fd });
        let data;
        const text = await res.text();
        try { data = JSON.parse(text); }
        catch { throw new Error('服务器返回非 JSON：' + text.slice(0, 200)); }
        area.classList.remove('uploading');
        if (!data.ok) {
            thumb.style.display = 'none';
            errEl.textContent   = data.error || '上传失败';
            return;
        }
        // 用服务器路径替换 blob URL
        thumb.src = data.path;
        okEl.style.display = 'block';
        _uploadedSet.add(imgType);
        _uploadedPaths[imgType] = data.path.split('?')[0]; // 去掉 ?t= 时间戳
        // 底图上传成功后才显示创建按钮
        if (_uploadedSet.has('bg')) {
            const finBtn = document.getElementById('btn-finish');
            finBtn.style.display = 'inline-block';
            finBtn.onclick = () => {
                const params = new URLSearchParams({
                    template_id: _newTplId,
                    bg: _uploadedPaths['bg'] || '',
                });
                if (_uploadedPaths['top']) params.set('top', _uploadedPaths['top']);
                location.href = 'template/create.html?' + params.toString();
            };
        }
    } catch (err) {
        area.classList.remove('uploading');
        thumb.style.display = 'none';
        errEl.textContent = err.message || '上传失败，请重试';
    }
}

['bg', 'top'].forEach(t => {
    document.getElementById('up-file-' + t).addEventListener('change', () => doUpload(t));
});

// ── 删除模板 ─────────────────────────────────────────────────────
async function deleteTemplate(e, templateId, name) {
    e.preventDefault();
    e.stopPropagation();
    if (!confirm('确定删除模板「' + name + '」？此操作不可撤销。')) return;
    const fd = new FormData();
    fd.append('action', 'delete_template');
    fd.append('template_id', templateId);
    try {
        const res  = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { alert(data.error || '删除失败'); return; }
        location.reload();
    } catch {
        alert('网络错误，请重试');
    }
}
</script>
</body>
</html>

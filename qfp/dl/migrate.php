<?php
/**
 * JSON → SQLite 一次性迁移脚本
 * 遍历 dl/{MMYY}/{mac_b62}/config.json，将所有数据导入 SQLite。
 *
 * 使用方式：
 *   CLI : php migrate.php
 *   浏览器: http://yourhost/dl/migrate.php
 *           （迁移完成后建议删除或加访问限制）
 */

require_once __DIR__ . '/db.php';

// CLI 下输出纯文本，浏览器下输出 HTML
$isCli = (PHP_SAPI === 'cli');

function out(string $line, string $type = 'info'): void {
    global $isCli;
    if ($isCli) {
        echo $line . PHP_EOL;
    } else {
        $color = match($type) {
            'ok'    => '#3fb950',
            'skip'  => '#9aa4b2',
            'error' => '#f85149',
            default => '#e6edf3',
        };
        echo '<div style="color:' . $color . ';font-family:monospace;font-size:13px">'
            . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>';
        ob_flush(); flush();
    }
}

// Base62 反转：base62 字符串 → 十进制整数
function fromBase62(string $str): int {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $map = array_flip(str_split($alphabet));
    $num = 0;
    foreach (str_split($str) as $ch) {
        $num = $num * 62 + ($map[$ch] ?? 0);
    }
    return $num;
}

// Base62(9位) → 12位大写十六进制 MAC
function b62ToHex(string $b62): string {
    return strtoupper(str_pad(dechex(fromBase62($b62)), 12, '0', STR_PAD_LEFT));
}

if (!$isCli) {
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<title>JSON → SQLite 迁移</title>'
       . '<style>body{background:#0b0f14;color:#e6edf3;padding:20px}</style></head><body>';
}

out('=== JSON → SQLite 迁移开始 ===');

$pdo      = getDb();
$baseDir  = __DIR__;
$total    = 0;
$imported = 0;
$skipped  = 0;
$errors   = 0;

// 遍历第一层：{MMYY}
foreach (glob($baseDir . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR) as $monthPath) {
    $monthYear = basename($monthPath);

    // 遍历第二层：{mac_b62}
    foreach (glob($monthPath . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $macPath) {
        $mac_b62    = basename($macPath);
        $configFile = $macPath . DIRECTORY_SEPARATOR . 'config.json';
        $total++;

        if (!is_file($configFile)) {
            out("  [SKIP] {$monthYear}/{$mac_b62} — 无 config.json", 'skip');
            $skipped++;
            continue;
        }

        $raw = file_get_contents($configFile);
        $config = json_decode($raw, true);
        if (!is_array($config)) {
            out("  [ERR]  {$monthYear}/{$mac_b62} — JSON 解析失败", 'error');
            $errors++;
            continue;
        }

        // 提取 SETUP 节（不作为条目写入）
        $setup = isset($config['SETUP']) && is_array($config['SETUP']) ? $config['SETUP'] : [];
        $sleep  = isset($setup['sleep'])  ? intval($setup['sleep'])  : 15;
        $attime = isset($setup['attime']) ? intval($setup['attime']) : 3000;
        $registeredAt = isset($setup['systime']) ? intval($setup['systime']) : filemtime($configFile);

        // 还原 MAC 十六进制（用于 mac_hex 字段）
        $mac_hex = b62ToHex($mac_b62);

        try {
            $pdo->beginTransaction();

            // 插入设备（已存在则跳过）
            $stmt = $pdo->prepare('
                INSERT OR IGNORE INTO devices (month_year, mac_b62, mac_hex, registered_at, sleep, attime)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$monthYear, $mac_b62, $mac_hex, $registeredAt, $sleep, $attime]);

            // 查出 device_id（不管是刚插入还是已存在）
            $stmt = $pdo->prepare('SELECT id FROM devices WHERE month_year = ? AND mac_b62 = ?');
            $stmt->execute([$monthYear, $mac_b62]);
            $deviceId = (int)$stmt->fetchColumn();

            // 插入条目（跳过 SETUP 键）
            $stmtEntry = $pdo->prepare('
                INSERT OR IGNORE INTO entries (device_id, key, pw, state)
                VALUES (?, ?, ?, ?)
            ');
            $entryCount = 0;
            foreach ($config as $key => $val) {
                if ($key === 'SETUP') continue;
                if (!is_array($val)) continue;
                $pw    = isset($val['pw'])    ? strval($val['pw'])    : '';
                $state = isset($val['state']) ? intval($val['state']) : 1;
                $stmtEntry->execute([$deviceId, $key, $pw, $state]);
                $entryCount++;
            }

            $pdo->commit();
            out("  [OK]   {$monthYear}/{$mac_b62} (hex:{$mac_hex}) — {$entryCount} 条条目", 'ok');
            $imported++;

        } catch (Throwable $e) {
            $pdo->rollBack();
            out("  [ERR]  {$monthYear}/{$mac_b62} — " . $e->getMessage(), 'error');
            $errors++;
        }
    }
}

out('');
out("=== 迁移完成 === 共 {$total} 个目录，导入 {$imported}，跳过 {$skipped}，失败 {$errors}");

if (!$isCli) {
    echo '</body></html>';
}

<?php
/**
 * 打开（并按需初始化）SQLite 数据库，返回 PDO 实例。
 * 在 cmdir.php / getlist.php 顶部 require 即可使用。
 */
function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbPath = __DIR__ . DIRECTORY_SEPARATOR . 'data.db';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // WAL 模式：允许读写并发，适合多请求并发场景
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS devices (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            month_year    TEXT    NOT NULL,
            mac_b62       TEXT    NOT NULL,
            mac_hex       TEXT    NOT NULL,
            registered_at INTEGER NOT NULL,
            sleep         INTEGER NOT NULL DEFAULT 15,
            sleep_low     INTEGER NOT NULL DEFAULT 30,
            attime        INTEGER NOT NULL DEFAULT 0,
            time_start    INTEGER NOT NULL DEFAULT 0,
            time_end      INTEGER NOT NULL DEFAULT 1439,
            UNIQUE (month_year, mac_b62)
        );

        CREATE TABLE IF NOT EXISTS entries (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
            key       TEXT    NOT NULL,
            pw        TEXT    NOT NULL DEFAULT \'\',
            state     INTEGER NOT NULL DEFAULT 1,
            UNIQUE (device_id, key)
        );

        CREATE INDEX IF NOT EXISTS idx_entries_device ON entries(device_id);
    ');

    // 兼容旧库：自动补充新增字段
    $columns = $pdo->query('PRAGMA table_info(devices)')->fetchAll();
    $colNames = array_column($columns, 'name');
    if (!in_array('sleep_low',  $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN sleep_low  INTEGER NOT NULL DEFAULT 30');
    if (!in_array('time_start', $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN time_start INTEGER NOT NULL DEFAULT 0');
    if (!in_array('time_end',   $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN time_end   INTEGER NOT NULL DEFAULT 1439');

    return $pdo;
}

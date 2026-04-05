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
            factory_status INTEGER NOT NULL DEFAULT 0,
            getlist_count INTEGER NOT NULL DEFAULT 0,
            getlist_at    INTEGER NOT NULL DEFAULT 0,
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

        CREATE TABLE IF NOT EXISTS accounts (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            role          TEXT    NOT NULL,
            parent_id     INTEGER REFERENCES accounts(id) ON DELETE SET NULL,
            display_name  TEXT    NOT NULL,
            username      TEXT    NOT NULL UNIQUE,
            password_hash TEXT    NOT NULL,
            phone         TEXT    NOT NULL DEFAULT \'\',
            address       TEXT    NOT NULL DEFAULT \'\',
            remark        TEXT    NOT NULL DEFAULT \'\',
            status        INTEGER NOT NULL DEFAULT 1,
            created_at    INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS factory_stage_notes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id  INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
            stage      INTEGER NOT NULL,
            note       TEXT    NOT NULL DEFAULT \'\',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            UNIQUE (device_id, stage)
        );

        CREATE TABLE IF NOT EXISTS factory_test_results (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id   INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
            test_index  INTEGER NOT NULL,
            result      TEXT    NOT NULL DEFAULT \'\',
            note        TEXT    NOT NULL DEFAULT \'\',
            updated_at  INTEGER NOT NULL DEFAULT 0,
            UNIQUE (device_id, test_index)
        );

        CREATE INDEX IF NOT EXISTS idx_entries_device ON entries(device_id);
        CREATE INDEX IF NOT EXISTS idx_accounts_role ON accounts(role);
        CREATE INDEX IF NOT EXISTS idx_accounts_parent ON accounts(parent_id);
        CREATE INDEX IF NOT EXISTS idx_factory_stage_notes_device_stage ON factory_stage_notes(device_id, stage);
        CREATE INDEX IF NOT EXISTS idx_factory_test_results_device ON factory_test_results(device_id);

        CREATE TABLE IF NOT EXISTS parcels (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            agent_id          INTEGER NOT NULL REFERENCES accounts(id),
            recipient_name    TEXT    NOT NULL DEFAULT \'\',
            recipient_address TEXT    NOT NULL DEFAULT \'\',
            recipient_phone   TEXT    NOT NULL DEFAULT \'\',
            tracking_number   TEXT    NOT NULL DEFAULT \'\',
            remark            TEXT    NOT NULL DEFAULT \'\',
            status            INTEGER NOT NULL DEFAULT 0,
            created_at        INTEGER NOT NULL,
            shipped_at        INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS parcel_devices (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            parcel_id INTEGER NOT NULL REFERENCES parcels(id) ON DELETE CASCADE,
            device_id INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
            UNIQUE (parcel_id, device_id)
        );

        CREATE INDEX IF NOT EXISTS idx_parcels_agent ON parcels(agent_id);
        CREATE INDEX IF NOT EXISTS idx_parcel_devices_parcel ON parcel_devices(parcel_id);
        CREATE INDEX IF NOT EXISTS idx_parcel_devices_device ON parcel_devices(device_id);
    ');

    // 兼容旧库：自动补充新增字段
    $columns = $pdo->query('PRAGMA table_info(devices)')->fetchAll();
    $colNames = array_column($columns, 'name');
    if (!in_array('factory_status',$colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN factory_status INTEGER NOT NULL DEFAULT 0');
    if (!in_array('getlist_count',$colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN getlist_count INTEGER NOT NULL DEFAULT 0');
    if (!in_array('getlist_at',   $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN getlist_at    INTEGER NOT NULL DEFAULT 0');
    if (!in_array('sleep_low',  $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN sleep_low  INTEGER NOT NULL DEFAULT 30');
    if (!in_array('time_start', $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN time_start INTEGER NOT NULL DEFAULT 0');
    if (!in_array('time_end',   $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN time_end   INTEGER NOT NULL DEFAULT 1439');
    if (!in_array('volume',     $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN volume     INTEGER NOT NULL DEFAULT 5');
    if (!in_array('cell',       $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN cell       TEXT    NOT NULL DEFAULT \'\'');
    if (!in_array('cell_at',    $colNames)) $pdo->exec('ALTER TABLE devices ADD COLUMN cell_at    INTEGER NOT NULL DEFAULT 0');

    // 兼容旧状态值：将文本状态迁移为整数编号
    $pdo->exec("UPDATE devices SET factory_status = CASE factory_status
        WHEN 'new_registered' THEN 0
        WHEN 'power_on' THEN 1
        WHEN 'inspection' THEN 2
        WHEN 'qr_created' THEN 3
        WHEN 'packaged' THEN 4
        WHEN 'delivered_admin' THEN 5
        ELSE factory_status
    END");

    $accountColumns = $pdo->query('PRAGMA table_info(accounts)')->fetchAll();
    $accountColNames = array_column($accountColumns, 'name');
    if (!in_array('status',     $accountColNames)) $pdo->exec('ALTER TABLE accounts ADD COLUMN status     INTEGER NOT NULL DEFAULT 1');
    if (!in_array('created_at', $accountColNames)) $pdo->exec('ALTER TABLE accounts ADD COLUMN created_at INTEGER NOT NULL DEFAULT 0');
    if (!in_array('recipient_name',    $accountColNames)) $pdo->exec("ALTER TABLE accounts ADD COLUMN recipient_name    TEXT NOT NULL DEFAULT ''");
    if (!in_array('recipient_address', $accountColNames)) $pdo->exec("ALTER TABLE accounts ADD COLUMN recipient_address TEXT NOT NULL DEFAULT ''");
    if (!in_array('recipient_phone',   $accountColNames)) $pdo->exec("ALTER TABLE accounts ADD COLUMN recipient_phone   TEXT NOT NULL DEFAULT ''");

    // parcels 表兼容：增加 dealer_id 字段（代理商发给经销商的包裹）
    $parcelColumns = $pdo->query('PRAGMA table_info(parcels)')->fetchAll();
    $parcelColNames = array_column($parcelColumns, 'name');
    if (!in_array('dealer_id', $parcelColNames)) $pdo->exec('ALTER TABLE parcels ADD COLUMN dealer_id INTEGER NOT NULL DEFAULT 0');

    $adminStmt = $pdo->prepare('SELECT id FROM accounts WHERE username = ? LIMIT 1');
    $adminStmt->execute(['admin']);
    if (!$adminStmt->fetch()) {
        $insertAdmin = $pdo->prepare(
            'INSERT INTO accounts (role, parent_id, display_name, username, password_hash, phone, address, remark, status, created_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $insertAdmin->execute([
            'admin',
            '系统管理员',
            'admin',
            password_hash('123456', PASSWORD_DEFAULT),
            '',
            '',
            '默认管理员账号',
            time(),
        ]);
    }

    $factoryStmt = $pdo->prepare('SELECT id FROM accounts WHERE username = ? LIMIT 1');
    $factoryStmt->execute(['gcadmin']);
    if (!$factoryStmt->fetch()) {
        $insertFactory = $pdo->prepare(
            'INSERT INTO accounts (role, parent_id, display_name, username, password_hash, phone, address, remark, status, created_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $insertFactory->execute([
            'factory',
            '工厂管理员',
            'gcadmin',
            password_hash('123456', PASSWORD_DEFAULT),
            '',
            '',
            '默认工厂管理员账号',
            time(),
        ]);
    }

    return $pdo;
}

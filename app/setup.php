<?php
/**
 * setup.php — run this ONCE to create the local SQLite database with all
 * the Kurash tables and one sample tournament to preview.
 *
 * How to run it: open Command Prompt in this folder and type:
 *     C:\php\php.exe setup.php
 *
 * Safe to run again later — it won't duplicate tables or the sample data.
 */

require_once __DIR__ . '/db-config.php';

$dbFile = kurash_db_path();
$pdo = kurash_pdo();

$statements = [
    "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password_hash TEXT,
        full_name TEXT,
        role TEXT DEFAULT 'admin'
    )",
    "CREATE TABLE IF NOT EXISTS champions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT
    )",
    "CREATE TABLE IF NOT EXISTS championsubs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        champion_id INTEGER,
        subtitle TEXT,
        sandaweights TEXT,
        sandaweights_text TEXT,
        corashweights TEXT,
        corashweights_text TEXT
    )",
    "CREATE TABLE IF NOT EXISTS championregisterathletes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ika_id TEXT UNIQUE,
        register_id INTEGER,
        board_id INTEGER,
        champion_id INTEGER,
        championsub_id INTEGER,
        agecategory_text TEXT,
        user_id INTEGER,
        nationalcode TEXT,
        fullname TEXT,
        gender TEXT,
        noc_code TEXT,
        noc_name TEXT,
        flag_url TEXT,
        photo_url TEXT,
        position_title TEXT,
        accreditation_areas TEXT,
        qrcode_value TEXT,
        wushutype TEXT,
        idcard_imageurl TEXT,
        contract_id TEXT,
        lastclub TEXT,
        isnationplayer INTEGER,
        sandaweight INTEGER,
        sandaweight_text TEXT,
        talouforms TEXT,
        talouforms_text TEXT,
        corashweight INTEGER,
        corashweight_text TEXT,
        sandaweight_net TEXT,
        corashweight_net TEXT,
        weighin_value REAL,
        weighin_status TEXT DEFAULT 'pending',
        weighin_datetime TEXT,
        sanda_lotterynumber INTEGER,
        talou_lotterynumber INTEGER,
        corash_lotterynumber INTEGER,
        insurance TEXT
    )",
    "CREATE TABLE IF NOT EXISTS championregisters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        board_id INTEGER,
        board_text TEXT,
        champion_id INTEGER,
        champion_text TEXT,
        registerdate TEXT,
        registertime TEXT,
        registerstatus TEXT,
        sanda_gold INTEGER, sanda_silver INTEGER, sanda_bronze INTEGER,
        talou_gold INTEGER, talou_silver INTEGER, talou_bronze INTEGER,
        total_gold INTEGER, total_silver INTEGER, total_bronze INTEGER,
        corash_gold INTEGER, corash_silver INTEGER, corash_bronze INTEGER
    )",
    "CREATE TABLE IF NOT EXISTS championplaytablekurash (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        playcode INTEGER,
        champion_id INTEGER,
        championsub_id INTEGER,
        corashweight INTEGER,
        corashweight_text TEXT,
        roundnumber INTEGER,
        playnumber INTEGER,
        court_id INTEGER,
        court_status TEXT DEFAULT 'unscheduled',
        scoreboard_synced_at TEXT,
        pre_playnumber_a INTEGER, pre_playnumber_b INTEGER,
        registerid_a INTEGER, registerid_b INTEGER,
        athleteid_a INTEGER, athleteid_b INTEGER,
        userid_a INTEGER, userid_b INTEGER,
        fullname_a TEXT, fullname_b TEXT,
        lotterynumber_a INTEGER, lotterynumber_b INTEGER,
        boardid_a INTEGER, boardid_b INTEGER,
        score_a REAL, score_b REAL,
        wintype TEXT,
        winner_athleteid INTEGER, winner_userid INTEGER, winner_boardid INTEGER,
        winner_lotterynumber INTEGER, winner_fullname TEXT, winner_registerid INTEGER
    )",
    "CREATE TABLE IF NOT EXISTS kurashcourts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        champion_id INTEGER,
        court_number INTEGER,
        court_name TEXT,
        scoreboard_api_base_url TEXT,
        scoreboard_api_key TEXT,
        is_active INTEGER DEFAULT 1
    )",
];

// Indexes on the columns every page filters by. Without these each screen is
// a full table scan; they also shorten the write lock, which matters once
// several mats are posting results at once.
$statements[] = "CREATE INDEX IF NOT EXISTS idx_athletes_scope
                 ON championregisterathletes (champion_id, championsub_id, corashweight)";
$statements[] = "CREATE INDEX IF NOT EXISTS idx_athletes_ika
                 ON championregisterathletes (ika_id)";
$statements[] = "CREATE INDEX IF NOT EXISTS idx_play_scope
                 ON championplaytablekurash (champion_id, championsub_id, corashweight, roundnumber)";
$statements[] = "CREATE UNIQUE INDEX IF NOT EXISTS idx_play_playcode
                 ON championplaytablekurash (playcode)";
$statements[] = "CREATE INDEX IF NOT EXISTS idx_play_prev
                 ON championplaytablekurash (pre_playnumber_a, pre_playnumber_b)";

foreach ($statements as $sql) {
    $pdo->exec($sql);
}

// Seed one sample tournament, only if it doesn't already exist.
$exists = $pdo->query("SELECT COUNT(*) FROM champions WHERE id = 1")->fetchColumn();
if (!$exists) {
    $pdo->exec("INSERT INTO champions (id, title) VALUES (1, 'Preview Kurash Championship 2026')");
    $pdo->exec("INSERT INTO championsubs (id, champion_id, subtitle, corashweights, corashweights_text)
                VALUES (1, 1, 'Men Senior', '1/2/3/4', '-66/-73/-81/-90')");
}

// Seed one administrator, only if no users exist yet. The password is random
// and printed exactly once — there is no shipped default to forget about, and
// nothing to leak if this file is ever readable on a live server.
$userExists = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if (!$userExists) {
    $password = bin2hex(random_bytes(9)); // 18 hex characters
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, 'admin')");
    $stmt->execute(['admin', password_hash($password, PASSWORD_DEFAULT), 'Administrator']);

    echo "\n";
    echo "  Administrator account created.\n";
    echo "  username: admin\n";
    echo "  password: {$password}\n";
    echo "  Write this down now — it is not stored anywhere else and will not be shown again.\n";
    echo "\n";
}

echo "Setup complete. Database created at: {$dbFile}\n";
echo "Sample tournament ready: champion_id=1, championsub_id=1\n";

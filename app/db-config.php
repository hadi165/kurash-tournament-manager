<?php
/**
 * db-config.php — single source of truth for where the database lives.
 *
 * The database file MUST sit outside the web root. On DirectAdmin (and most
 * shared hosts) this app/ folder becomes public_html, so a database kept
 * alongside these scripts is downloadable by anyone who guesses the filename.
 *
 * Production: set the KURASH_DB environment variable to an absolute path
 * outside the document root, e.g. /home/youruser/kurash-data/kurash.db
 * Local: defaults to ../data/kurash.db, one level above app/.
 */

function kurash_db_path(): string
{
    $fromEnv = getenv('KURASH_DB');
    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }

    return dirname(__DIR__) . '/data/kurash.db';
}

/**
 * Open a connection with the pragmas this application actually needs.
 *
 * WAL lets readers continue while a mat posts a result, instead of blocking
 * on the database-wide write lock; busy_timeout makes a concurrent writer wait
 * five seconds rather than failing instantly with SQLITE_BUSY.
 */
function kurash_pdo(): PDO
{
    $path = kurash_db_path();
    $dir = dirname($path);

    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create database directory: {$dir}");
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

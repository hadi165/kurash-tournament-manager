<?php
/**
 * Database connection using SQLite (a single file, no server process,
 * no installation needed beyond the PHP interpreter itself).
 *
 * The database lives OUTSIDE the web root — see db-config.php.
 */
require_once __DIR__ . '/../db-config.php';

try {
    $pdocon = kurash_pdo();
} catch (Throwable $e) {
    error_log('Kurash DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed. Check the server error log, and make sure setup.php has been run.');
}

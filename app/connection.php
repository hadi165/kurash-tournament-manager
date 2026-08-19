<?php
/**
 * Database connection using SQLite (used by the API/webhook files).
 * The path and pragmas live in db-config.php so there is one place to change.
 */
require_once __DIR__ . '/db-config.php';

try {
    $pdo = kurash_pdo();
} catch (Throwable $e) {
    error_log('Kurash DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed. Check the server error log.');
}

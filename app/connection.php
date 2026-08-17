<?php
/**
 * Database connection using SQLite (used by the API/webhook files).
 */
$dbFile = __DIR__ . '/kurash.db';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()) . '. Did you run setup.php first?');
}

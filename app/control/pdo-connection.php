<?php
/**
 * Database connection using SQLite (a single file, no server process,
 * no installation needed beyond the PHP interpreter itself).
 */
$dbFile = __DIR__ . '/../kurash.db';

try {
    $pdocon = new PDO('sqlite:' . $dbFile);
    $pdocon->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdocon->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()) . '. Did you run setup.php first?');
}

<?php
/**
 * file-download-kurash.php
 * The three output folders (weigh-in-list-confirmed, primary-draw-result,
 * related-to-fight-order) live OUTSIDE app/ on purpose — but that means
 * PHP's built-in dev server (`php -S`, rooted at app/) can't serve them
 * directly, which is what caused the ERR_FILE_NOT_FOUND you saw. This
 * endpoint lives INSIDE app/ and streams those files through instead.
 */
session_start();
require_once './validate-online.php';

$allowedFolders = [
    'confirmed' => __DIR__ . '/../weigh-in-list-confirmed',
    'primary' => __DIR__ . '/../primary-draw-result',
    'fightorder' => __DIR__ . '/../related-to-fight-order',
];

$folderKey = $_GET['folder'] ?? '';
$fileName = $_GET['file'] ?? '';

if (!isset($allowedFolders[$folderKey])) {
    http_response_code(400);
    die('Unknown folder.');
}

// Prevent path traversal — only allow a bare filename, no slashes or "..".
$fileName = basename($fileName);
$fullPath = realpath($allowedFolders[$folderKey] . '/' . $fileName);
$folderReal = realpath($allowedFolders[$folderKey]);

if (!$fullPath || !$folderReal || strpos($fullPath, $folderReal) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    die('File not found: ' . htmlspecialchars($fileName) . ' in ' . htmlspecialchars($folderKey) . '. Make sure you generated/saved it first.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mime = $ext === 'csv' ? 'text/csv' : ($ext === 'html' ? 'text/html' : 'application/octet-stream');

header('Content-Type: ' . $mime . '; charset=utf-8');
if (isset($_GET['view']) && $ext === 'html') {
    // open inline (e.g. so it can be Ctrl+P'd to PDF) instead of downloading
} else {
    header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
}
readfile($fullPath);

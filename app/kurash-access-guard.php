<?php
/**
 * kurash-access-guard.php
 *
 * Include this at the very top of every Kurash admin page (after session_start()
 * and after your existing validate-online.php login check), to restrict access
 * to admin / executive-supervisor roles only.
 *
 * ASSUMPTION: your existing login system stores the logged-in user's role in
 * $_SESSION['user_role'] (or similar) after validate-online.php runs. Adjust
 * the session key below to match whatever your current login system actually
 * sets — I don't have that file, so this is a placeholder that needs a
 * one-line fix on your side.
 */

$allowedRoles = ['admin', 'supervisor']; // adjust role names to match your system

$currentRole = $_SESSION['user_role'] ?? null;

if (!$currentRole || !in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Access denied. This section is restricted to admin/supervisor accounts.'
    ]);
    exit;
}

<?php
/**
 * boot.php — the first thing every page must require.
 *
 * Replaces the bare session_start() that used to sit at the top of each file.
 * Centralising it means cookie flags, the idle timeout and CSRF are set once
 * rather than twenty-six times, and cannot be forgotten on a new page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                        // JavaScript cannot read it
        'secure'   => !empty($_SERVER['HTTPS']),   // HTTPS-only once TLS is on
        'samesite' => 'Lax',                       // not sent on cross-site POSTs
    ]);
    session_start();
}

require_once __DIR__ . '/csrf.php';

/**
 * Idle timeout — 30 minutes.
 * A tablet left unlocked on the mat should not stay signed in as an
 * administrator for the rest of the day. Only applies to authenticated
 * sessions, so the login page itself can never redirect-loop.
 */
const KURASH_IDLE_TIMEOUT = 1800;

if (!empty($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > KURASH_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?expired=1');
        exit;
    }
    $_SESSION['last_seen'] = time();
}

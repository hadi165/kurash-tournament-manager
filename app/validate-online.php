<?php
/**
 * Real login check — replaces the earlier "auto-login" stand-in used
 * during initial local previewing. Every protected page includes this
 * after session_start(); if there's no valid session, it bounces to login.php.
 */
if (empty($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

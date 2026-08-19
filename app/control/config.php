<?php
/**
 * Shared runtime configuration.
 *
 * Error output is driven by the environment, not hard-coded: on a live server
 * a stack trace in the browser hands out file paths and SQL to anyone who can
 * trigger an exception. Set KURASH_DEBUG=1 locally to see errors on screen;
 * everywhere else they go to the server error log only.
 */

$kurashDebug = getenv('KURASH_DEBUG') === '1';

error_reporting(E_ALL);
ini_set('display_errors', $kurashDebug ? '1' : '0');
ini_set('display_startup_errors', $kurashDebug ? '1' : '0');
ini_set('log_errors', '1');

date_default_timezone_set(getenv('KURASH_TZ') ?: 'UTC');

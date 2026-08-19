<?php
require_once __DIR__ . '/boot.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;

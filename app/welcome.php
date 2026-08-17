<?php
session_start();
require_once './validate-online.php';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #0d1b3d; margin: 0; height: 100vh; display: flex; align-items: center; justify-content: center; }
        a.welcome-text { color: #fff; text-decoration: none; text-align: center; font-size: 28px; line-height: 1.5; }
        a.welcome-text strong { display: block; font-size: 34px; margin-top: 6px; }
        a.welcome-text:hover { opacity: .85; }
    </style>
</head>
<body>
    <a class="welcome-text" href="main-dashboard.php">
        Welcome<br>
        to the<br>
        <strong>International KURASH Association</strong>
    </a>
</body>
</html>

<?php
/**
 * Item 6 — Welcome screen shown right after "Start".
 * SIMPLIFIED / INCOMPLETE: the spec asks for competition logos, date/time,
 * and location, but `champions` currently only stores a title. Showing
 * what we have (title + today's date) until those fields are defined.
 * TODO: add champions.logo_url, champions.event_date, champions.location
 * once confirmed, then fill in the blanks below.
 */
session_start();
require_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './control/class.php';
require_once './validate-online.php';

$dbcon = new DB();
$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);
$championInfo = $dbcon->getTableById('champions', $champion_id);
$championSubInfo = $dbcon->getTableById('championsubs', $championsub_id);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: linear-gradient(135deg,#0d1b3d,#1565c0); margin:0; height:100vh; display:flex; align-items:center; justify-content:center; color:#fff; }
        .box { text-align:center; max-width:600px; padding:40px; }
        h1 { font-size:32px; margin-bottom:10px; }
        .sub { font-size:16px; opacity:.85; margin-bottom:30px; }
        .placeholder { border:1px dashed rgba(255,255,255,.4); border-radius:8px; padding:16px; font-size:12px; opacity:.7; margin-bottom:30px; }
        .btn { background:#fff; color:#0d1b3d; border:none; padding:14px 40px; border-radius:6px; font-size:16px; cursor:pointer; text-decoration:none; display:inline-block; font-weight:bold; }
    </style>
</head>
<body>
    <div class="box">
        <h1><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></h1>
        <div class="sub"><?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?> — <?php echo date('l, F j, Y'); ?></div>
        <div class="placeholder">Logos, exact date/time, and location will appear here once those fields are added to Championship Management.</div>
        <a class="btn" href="wizard-athletes-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">Next &rarr;</a>
    </div>
</body>
</html>

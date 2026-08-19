<?php
require_once __DIR__ . '/boot.php';
require_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './control/class.php';
require_once './validate-online.php';
require_once './medal-helpers.php';

$dbcon = new DB();
$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);
$championInfo = $dbcon->getTableById('champions', $champion_id);
$formTitle = 'Medallists by Event';

$events = computeMedalEvents($pdocon, $champion_id);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Result - Medallists by Event</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
        .medal-gold { color: #b8860b; font-weight: bold; }
        .medal-silver { color: #757575; font-weight: bold; }
        .medal-bronze { color: #a05a2c; font-weight: bold; }
        .btn { background: #fff; color: #1565c0; border: 1px solid #1565c0; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; margin-bottom: 10px; }
        @media print { .btn { display: none; } }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
<?php require './page-header-kurash.php'; ?>
    <button class="btn" onclick="window.print()">Export PDF</button>
    <table>
        <thead>
            <tr><th>Event</th><th>Medal</th><th>Name</th><th>NOC</th></tr>
        </thead>
        <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td rowspan="<?php echo 2 + count($ev['bronze']); ?>"><?php echo htmlspecialchars($ev['weight_text']); ?></td>
                    <td class="medal-gold">GOLD</td>
                    <td><?php echo htmlspecialchars($ev['gold']['fullname'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($ev['gold']['noc_code'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td class="medal-silver">SILVER</td>
                    <td><?php echo htmlspecialchars($ev['silver']['fullname'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($ev['silver']['noc_code'] ?? ''); ?></td>
                </tr>
                <?php foreach ($ev['bronze'] as $b): ?>
                <tr>
                    <td class="medal-bronze">BRONZE</td>
                    <td><?php echo htmlspecialchars($b['fullname'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($b['noc_code'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (empty($events)): ?>
            <tr><td colspan="4" style="text-align:center; color:#888;">No finals decided yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

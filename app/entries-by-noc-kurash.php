<?php
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

$stmt = $pdocon->prepare("
    SELECT noc_code,
           SUM(CASE WHEN gender = 'F' THEN 1 ELSE 0 END) as girls,
           SUM(CASE WHEN gender = 'M' THEN 1 ELSE 0 END) as boys,
           COUNT(*) as total
    FROM championregisterathletes
    WHERE champion_id = ? AND championsub_id = ?
    GROUP BY noc_code
    ORDER BY noc_code ASC
");
$stmt->execute([$champion_id, $championsub_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalGirls = array_sum(array_column($rows, 'girls'));
$totalBoys = array_sum(array_column($rows, 'boys'));
$totalAll = array_sum(array_column($rows, 'total'));
$formTitle = "Number of Entries by NOC";
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entries by NOC - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .back-link { display: inline-block; margin-bottom: 16px; color: #1565c0; text-decoration: none; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: center; font-size: 14px; }
        th { background: #f8f9fa; }
        td:first-child, th:first-child { text-align: left; }
        .toolbar { margin-bottom: 10px; }
        .btn { background: #1565c0; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; }
        @media print { .toolbar, .back-link { display: none; } }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
<?php require './page-header-kurash.php'; ?>
    <a class="back-link" href="main-dashboard.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">&larr; Back to Dashboard</a>
    <h2>Number of Entries by NOC</h2>
    <p><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?> — <?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?></p>

    <div class="toolbar">
        <button class="btn" onclick="window.print()">Export PDF</button>
    </div>

    <table>
        <thead>
            <tr>
                <th>NOC</th>
                <th>Girls Entries</th>
                <th>Boys Entries</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['noc_code']); ?></td>
                <td><?php echo (int)$r['girls']; ?></td>
                <td><?php echo (int)$r['boys']; ?></td>
                <td><strong><?php echo (int)$r['total']; ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="4" style="color:#888;">No entries yet.</td></tr>
            <?php endif; ?>
            <tr>
                <td><strong>Total</strong></td>
                <td><strong><?php echo $totalGirls; ?></strong></td>
                <td><strong><?php echo $totalBoys; ?></strong></td>
                <td><strong><?php echo $totalAll; ?></strong></td>
            </tr>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

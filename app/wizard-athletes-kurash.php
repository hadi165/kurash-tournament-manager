<?php
/**
 * Item 7 — initial athlete list, shown after Welcome/Next.
 * Deliberately does not display draw numbers (even though they may
 * already be assigned in the database) — this view represents the
 * "before the draw" roster.
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

$stmt = $pdocon->prepare("
    SELECT * FROM championregisterathletes
    WHERE champion_id = ? AND championsub_id = ?
    ORDER BY corashweight ASC, fullname ASC
");
$stmt->execute([$champion_id, $championsub_id]);
$athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Athletes - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
        .btn { background: #1565c0; color: #fff; border: none; padding: 12px 30px; border-radius: 4px; cursor: pointer; font-size: 15px; text-decoration:none; display:inline-block; }
        .btn-bar { text-align: center; margin-top: 24px; }
    </style>
</head>
<body>
<div class="container">
    <h2><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></h2>
    <p style="color:#888;"><?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?> — Athlete Roster</p>

    <table>
        <thead>
            <tr><th>Name</th><th>NOC</th><th>Gender</th><th>Weight Category</th></tr>
        </thead>
        <tbody>
            <?php foreach ($athletes as $a): ?>
            <tr>
                <td><?php echo htmlspecialchars($a['fullname']); ?></td>
                <td><?php echo htmlspecialchars($a['noc_code']); ?></td>
                <td><?php echo $a['gender'] === 'M' ? 'Male' : ($a['gender'] === 'F' ? 'Female' : ''); ?></td>
                <td><?php echo htmlspecialchars($a['corashweight_text']); ?> kg</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="btn-bar">
        <a class="btn" href="draw-select-weight-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">Draw</a>
    </div>
</div>
</body>
</html>

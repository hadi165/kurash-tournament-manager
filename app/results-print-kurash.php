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
$weight = filter_input(INPUT_GET, 'weight', FILTER_VALIDATE_INT);
$autoprint = isset($_GET['autoprint']);
$forceDownload = isset($_GET['download']);
if ($forceDownload) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="result.html"');
}

$championInfo = $dbcon->getTableById('champions', $champion_id);
$championSubInfo = $dbcon->getTableById('championsubs', $championsub_id);

$stmt = $pdocon->prepare("
    SELECT * FROM championregisterathletes
    WHERE champion_id = ? AND championsub_id = ? AND corashweight = ? AND corash_lotterynumber IS NOT NULL
    ORDER BY corash_lotterynumber ASC
");
$stmt->execute([$champion_id, $championsub_id, $weight]);
$athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$weightLabel = $athletes[0]['corashweight_text'] ?? (string)$weight;

// Final-round winner, if the bracket has been played to completion
$stmt = $pdocon->prepare("SELECT MAX(roundnumber) FROM championplaytablekurash WHERE champion_id=? AND championsub_id=? AND corashweight=?");
$stmt->execute([$champion_id, $championsub_id, $weight]);
$maxRound = $stmt->fetchColumn();
$winnerName = null;
if ($maxRound) {
    $stmt = $pdocon->prepare("SELECT winner_fullname FROM championplaytablekurash WHERE champion_id=? AND championsub_id=? AND corashweight=? AND roundnumber=? LIMIT 1");
    $stmt->execute([$champion_id, $championsub_id, $weight, $maxRound]);
    $winnerName = $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <title>Result - <?php echo htmlspecialchars($weightLabel); ?>kg</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; padding: 30px; }
        h2 { margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
        .winner-box { background: #e8f5e9; padding: 10px 16px; border-radius: 6px; margin-top: 16px; font-weight: bold; }
    </style>
</head>
<body>
    <h2><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></h2>
    <p><?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?> — <?php echo htmlspecialchars($weightLabel); ?> kg</p>

    <?php if ($winnerName): ?>
        <div class="winner-box">Winner: <?php echo htmlspecialchars($winnerName); ?></div>
    <?php endif; ?>

    <table>
        <thead><tr><th>Draw No.</th><th>Name</th><th>NOC</th></tr></thead>
        <tbody>
            <?php foreach ($athletes as $a): ?>
            <tr>
                <td><?php echo htmlspecialchars($a['corash_lotterynumber']); ?></td>
                <td><?php echo htmlspecialchars($a['fullname']); ?></td>
                <td><?php echo htmlspecialchars($a['noc_code']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
<?php if ($autoprint): ?>
<script>window.onload = () => window.print();</script>
<?php endif; ?>
</html>

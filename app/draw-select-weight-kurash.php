<?php
/**
 * Item 8 — weight category picker. Choosing one goes to that category's
 * draw/reveal page.
 */
require_once __DIR__ . '/boot.php';
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

$weightIds = !empty($championSubInfo['corashweights']) ? explode('/', $championSubInfo['corashweights']) : [];
$weightTexts = !empty($championSubInfo['corashweights_text']) ? explode('/', $championSubInfo['corashweights_text']) : [];

$counts = [];
foreach ($weightIds as $wid) {
    $stmt = $pdocon->prepare("SELECT COUNT(*) FROM championregisterathletes WHERE champion_id = ? AND championsub_id = ? AND corashweight = ?");
    $stmt->execute([$champion_id, $championsub_id, $wid]);
    $counts[$wid] = (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Select Weight Category</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; margin-top: 20px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; text-decoration: none; color: #0d1b3d; transition: box-shadow .15s; }
        .card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .card .weight { font-size: 20px; font-weight: bold; }
        .card .count { font-size: 13px; color: #888; margin-top: 6px; }
    </style>
</head>
<body>
<div class="container">
    <h2>Select Weight Category</h2>
    <p style="color:#888;"><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?> — <?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?></p>
    <div class="grid">
        <?php foreach ($weightIds as $idx => $wid): ?>
            <a class="card" href="draw-reveal-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>&weight=<?php echo urlencode($wid); ?>">
                <div class="weight"><?php echo htmlspecialchars($weightTexts[$idx] ?? $wid); ?> kg</div>
                <div class="count"><?php echo $counts[$wid]; ?> athletes</div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>

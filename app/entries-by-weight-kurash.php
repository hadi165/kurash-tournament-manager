<?php
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
$formTitle = "Number of Entries by Weight Categories";

$weightIds = !empty($championSubInfo['corashweights']) ? explode('/', $championSubInfo['corashweights']) : [];
$weightTexts = !empty($championSubInfo['corashweights_text']) ? explode('/', $championSubInfo['corashweights_text']) : [];

$rows = [];
foreach ($weightIds as $idx => $wid) {
    $stmt = $pdocon->prepare("
        SELECT COUNT(*) FROM championregisterathletes
        WHERE champion_id = ? AND championsub_id = ? AND corashweight = ? AND weighin_status = 'pass'
    ");
    $stmt->execute([$champion_id, $championsub_id, $wid]);
    $entryCount = (int)$stmt->fetchColumn();

    // Draw Status: Done if this weight's athletes have draw numbers assigned, else Not Started
    $stmt = $pdocon->prepare("
        SELECT COUNT(*) FROM championregisterathletes
        WHERE champion_id = ? AND championsub_id = ? AND corashweight = ? AND corash_lotterynumber IS NOT NULL
    ");
    $stmt->execute([$champion_id, $championsub_id, $wid]);
    $drawnCount = (int)$stmt->fetchColumn();
    $drawStatus = ($drawnCount > 0 && $drawnCount === $entryCount) ? 'Done' : 'Not Started';

    $rows[] = [
        'wid' => $wid,
        'label' => $weightTexts[$idx] ?? $wid,
        'count' => $entryCount,
        'draw_status' => $drawStatus,
    ];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entries by Weight Categories</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: center; font-size: 14px; }
        th { background: #f8f9fa; }
        .icon-btn { background: none; border: 1px solid #ccc; border-radius: 4px; padding: 6px 10px; cursor: pointer; text-decoration: none; color: #333; font-size: 16px; }
        .btn-start { background: #1565c0; color: #fff; border: none; padding: 6px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; text-decoration: none; }
        .status-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-done { background: #e8f5e9; color: #1b5e20; }
        .status-notstarted { background: #eee; color: #666; }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
<?php require './page-header-kurash.php'; ?>
    <table>
        <thead>
            <tr>
                <th>Weight Category</th>
                <th>Number of Entries</th>
                <th>Athlete's List</th>
                <th>Start</th>
                <th>Result Draw</th>
                <th>Status Draw</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['label']); ?></td>
                <td><?php echo $r['count']; ?></td>
                <td>
                    <a class="icon-btn" title="Athlete's List"
                       href="athlete-table-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>&weight=<?php echo $r['wid']; ?>&download=1">
                        &#9776;
                    </a>
                </td>
                <td>
                    <a class="btn-start" href="draw-reveal-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>&weight=<?php echo $r['wid']; ?>">Start</a>
                </td>
                <td>
                    <a class="icon-btn" title="Result"
                       href="results-print-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>&weight=<?php echo $r['wid']; ?>&download=1">
                        &#9776;
                    </a>
                </td>
                <td>
                    <span class="status-badge <?php echo $r['draw_status'] === 'Done' ? 'status-done' : 'status-notstarted'; ?>">
                        <?php echo htmlspecialchars($r['draw_status']); ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="6" style="color:#888;">No weight categories defined yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

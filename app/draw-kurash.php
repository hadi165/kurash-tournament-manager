<?php
/**
 * Kurash Draw — read-only view of draw numbers per weight category.
 * Draw numbers themselves are now assigned via Start Competition
 * (Table 2 upload), not here. This page will become the animated
 * "reveal into bracket" wizard in the next phase.
 */
session_start();

include_once './control/config.php';
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

$athletesByWeight = [];
foreach ($weightIds as $idx => $wid) {
    $stmt = $pdocon->prepare("
        SELECT * FROM championregisterathletes
        WHERE champion_id = ? AND championsub_id = ? AND corashweight = ?
        ORDER BY corash_lotterynumber IS NULL, corash_lotterynumber ASC, fullname ASC
    ");
    $stmt->execute([$champion_id, $championsub_id, $wid]);
    $athletesByWeight[$wid] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Draw - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .back-link { display: inline-block; margin-bottom: 16px; color: #1565c0; text-decoration: none; font-size: 14px; }
        .weight-block { margin-bottom: 24px; border: 1px solid #eee; border-radius: 6px; overflow: hidden; }
        .weight-header { background: #f0f4ff; padding: 10px 16px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
        .empty { padding: 16px; color: #888; text-align: center; }
        .no-number { color: #b71c1c; font-size: 12px; }
        .note { background: #fff8e1; border: 1px solid #ffe082; padding: 12px 16px; border-radius: 6px; font-size: 13px; color: #7a5c00; margin-bottom: 20px; }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
    <a class="back-link" href="main-dashboard.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">&larr; Back to Dashboard</a>
    <h2>Draw</h2>
    <p><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?> — <?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?></p>

    <div class="note">
        Draw numbers are assigned via <a href="start-competition-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">Start Competition</a> (Table 2 upload).
        This page shows the current assignment per weight category.
    </div>

    <?php foreach ($weightIds as $idx => $wid): ?>
        <div class="weight-block">
            <div class="weight-header"><?php echo htmlspecialchars($weightTexts[$idx] ?? $wid); ?> kg</div>
            <?php if (empty($athletesByWeight[$wid])): ?>
                <div class="empty">No athletes registered in this weight category yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Draw #</th><th>IKA ID</th><th>Name</th><th>NOC</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($athletesByWeight[$wid] as $a): ?>
                        <tr>
                            <td>
                                <?php if ($a['corash_lotterynumber']): ?>
                                    <?php echo htmlspecialchars($a['corash_lotterynumber']); ?>
                                <?php else: ?>
                                    <span class="no-number">not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($a['ika_id'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($a['fullname']); ?></td>
                            <td><?php echo htmlspecialchars($a['noc_code']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</div>
</body>
</html>

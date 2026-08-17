<?php
/**
 * Archive — finished matches (weight categories whose bracket has a
 * completed final) get listed here for reference.
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

// Pull every weight category, across every championship/age-category, whose
// final round has a recorded winner — i.e. finished competitions.
$stmt = $pdocon->query("
    SELECT p.champion_id, p.championsub_id, p.corashweight, p.corashweight_text,
           p.winner_fullname, MAX(p.roundnumber) as maxround
    FROM championplaytablekurash p
    GROUP BY p.champion_id, p.championsub_id, p.corashweight
    HAVING p.winner_fullname IS NOT NULL
    ORDER BY p.champion_id DESC, p.corashweight ASC
");
$finished = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Attach championship/age-category titles for display
$titleCache = [];
foreach ($finished as &$f) {
    $key = $f['champion_id'] . '-' . $f['championsub_id'];
    if (!isset($titleCache[$key])) {
        $champ = $dbcon->getTableById('champions', $f['champion_id']);
        $sub = $dbcon->getTableById('championsubs', $f['championsub_id']);
        $titleCache[$key] = [
            'champion_title' => $champ['title'] ?? '',
            'sub_title' => $sub['subtitle'] ?? '',
        ];
    }
    $f['champion_title'] = $titleCache[$key]['champion_title'];
    $f['sub_title'] = $titleCache[$key]['sub_title'];
}
unset($f);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archive</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
        .btn-link { background: #eef2ff; color: #3730a3; padding: 5px 12px; border-radius: 4px; text-decoration: none; font-size: 12px; }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
    <h2>Archive</h2>
    <p style="color:#888;">Completed weight categories (finals decided), across all championships.</p>

    <table>
        <thead>
            <tr>
                <th>Championship</th>
                <th>Age Category</th>
                <th>Weight</th>
                <th>Winner</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($finished as $f): ?>
            <tr>
                <td><?php echo htmlspecialchars($f['champion_title']); ?></td>
                <td><?php echo htmlspecialchars($f['sub_title']); ?></td>
                <td><?php echo htmlspecialchars($f['corashweight_text']); ?> kg</td>
                <td><?php echo htmlspecialchars($f['winner_fullname']); ?></td>
                <td>
                    <a class="btn-link" target="_blank" href="results-print-kurash.php?champion_id=<?php echo $f['champion_id']; ?>&championsub_id=<?php echo $f['championsub_id']; ?>&weight=<?php echo $f['corashweight']; ?>">View Result</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($finished)): ?>
            <tr><td colspan="5" style="text-align:center; color:#888;">No completed weight categories yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

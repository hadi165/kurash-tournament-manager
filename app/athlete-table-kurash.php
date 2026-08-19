<?php
/**
 * Table 1 — "Total Athlete's Information Table" (per the spec).
 * Public-facing: Name, ID, Flag, NOC, Gender, Weight Category, Bracket title.
 * Deliberately does NOT include the draw/lottery number — that only exists
 * in Table 2 (see draw-kurash.php), which is upload-only and not shown here.
 */
require_once __DIR__ . '/boot.php';
require_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './bracket-helpers.php';
require_once './control/class.php';
require_once './validate-online.php';

$dbcon = new DB();
$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);
$weightFilter = filter_input(INPUT_GET, 'weight', FILTER_VALIDATE_INT);
$autoprint = isset($_GET['autoprint']);
$forceDownload = isset($_GET['download']);
if ($forceDownload) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="athlete-list.html"');
}

$championInfo = $dbcon->getTableById('champions', $champion_id);
$championSubInfo = $dbcon->getTableById('championsubs', $championsub_id);

$sql = "SELECT * FROM championregisterathletes WHERE champion_id = ? AND championsub_id = ?";
$params = [$champion_id, $championsub_id];
if ($weightFilter) {
    $sql .= " AND corashweight = ?";
    $params[] = $weightFilter;
}
$sql .= " ORDER BY corashweight ASC, fullname ASC";
$stmt = $pdocon->prepare($sql);
$stmt->execute($params);
$athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count athletes per weight category, to derive the bracket title.
$countsByWeight = [];
foreach ($athletes as $a) {
    $w = $a['corashweight'];
    $countsByWeight[$w] = ($countsByWeight[$w] ?? 0) + 1;
}

/**
 * Bracket title from headcount in the weight category, per federation rule:
 * 2 -> Final | 3-5 -> Round Robin | 6-8 -> 1/8 Final | 9-16 -> 1/16 Final
 */
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Athlete's Information Table - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .back-link { display: inline-block; margin-bottom: 16px; color: #1565c0; text-decoration: none; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
        .toolbar { margin-bottom: 10px; display: flex; gap: 10px; }
        .btn { background: #1565c0; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-outline { background: #fff; color: #1565c0; border: 1px solid #1565c0; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
        @media print { .toolbar, .back-link { display: none; } }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
    <a class="back-link" href="main-dashboard.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">&larr; Back to Dashboard</a>
    <h2>Athlete's Information Table</h2>
    <p><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?> — <?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?></p>

    <div class="toolbar">
        <a class="btn" href="athlete-table-export-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">Export Excel</a>
        <button class="btn-outline" onclick="window.print()">Export PDF</button>
    </div>

    <table>
        <thead>
            <tr>
                <th>Athlete's Name</th>
                <th>Athlete's ID (IKA)</th>
                <th>NOC</th>
                <th>Gender</th>
                <th>Weight Category</th>
                <th>Bracket Title</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($athletes as $a): ?>
            <tr>
                <td><?php echo htmlspecialchars($a['fullname']); ?></td>
                <td><?php echo htmlspecialchars($a['ika_id'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($a['noc_code']); ?></td>
                <td><?php echo $a['gender'] === 'M' ? 'Male' : ($a['gender'] === 'F' ? 'Female' : ''); ?></td>
                <td><?php echo htmlspecialchars($a['corashweight_text']); ?> kg</td>
                <td><?php echo htmlspecialchars(bracketTitleFromCount($countsByWeight[$a['corashweight']] ?? 0)); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($athletes)): ?>
            <tr><td colspan="6" style="text-align:center; color:#888;">No athletes registered yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
<?php if ($autoprint): ?>
<script>window.onload = () => window.print();</script>
<?php endif; ?>
</html>

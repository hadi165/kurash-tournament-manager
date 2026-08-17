<?php
session_start();
require_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './control/class.php';
require_once './validate-online.php';
require_once './medal-helpers.php';

$dbcon = new DB();
$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championInfo = $dbcon->getTableById('champions', $champion_id);
$formTitle = 'Medal Standing';

$events = computeMedalEvents($pdocon, $champion_id);

// Aggregate per NOC, split by gender (boys/girls), per spec: scope is this championship only
$standing = []; // noc => ['boys'=>[g,s,b], 'girls'=>[g,s,b]]
$ensureNoc = function ($noc) use (&$standing) {
    if (!isset($standing[$noc])) {
        $standing[$noc] = ['boys' => [0, 0, 0], 'girls' => [0, 0, 0]];
    }
};
$genderKey = fn($g) => $g === 'F' ? 'girls' : 'boys';

foreach ($events as $ev) {
    if ($ev['gold']) {
        $noc = $ev['gold']['noc_code'];
        $ensureNoc($noc);
        $standing[$noc][$genderKey($ev['gold']['gender'])][0]++;
    }
    if ($ev['silver']) {
        $noc = $ev['silver']['noc_code'];
        $ensureNoc($noc);
        $standing[$noc][$genderKey($ev['silver']['gender'])][1]++;
    }
    foreach ($ev['bronze'] as $b) {
        if (!$b) continue;
        $noc = $b['noc_code'];
        $ensureNoc($noc);
        $standing[$noc][$genderKey($b['gender'])][2]++;
    }
}

$rows = [];
foreach ($standing as $noc => $data) {
    $boysTotal = array_sum($data['boys']);
    $girlsTotal = array_sum($data['girls']);
    $totalG = $data['boys'][0] + $data['girls'][0];
    $totalS = $data['boys'][1] + $data['girls'][1];
    $totalB = $data['boys'][2] + $data['girls'][2];
    $grandTotal = $totalG + $totalS + $totalB;
    $rows[] = [
        'noc' => $noc,
        'boys' => $data['boys'], 'boys_total' => $boysTotal,
        'girls' => $data['girls'], 'girls_total' => $girlsTotal,
        'total' => [$totalG, $totalS, $totalB], 'grand_total' => $grandTotal,
    ];
}

// Rank by Gold, then Silver, then Bronze (classic medal-table ranking)
usort($rows, fn($a, $b) => $b['total'][0] <=> $a['total'][0]
    ?: $b['total'][1] <=> $a['total'][1]
    ?: $b['total'][2] <=> $a['total'][2]);

$rank = 0;
$prevKey = null;
foreach ($rows as $i => &$r) {
    $key = implode(',', $r['total']);
    if ($key !== $prevKey) $rank = $i + 1;
    $r['rank'] = ($i > 0 && $key === $prevKey) ? '=' . $rank : (string)$rank;
    $prevKey = $key;
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medal Standing</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: center; font-size: 13px; }
        th { background: #f8f9fa; }
        td:nth-child(2) { text-align: left; }
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
            <tr>
                <th rowspan="2">Rank</th>
                <th rowspan="2">NOC</th>
                <th colspan="4">Boys</th>
                <th colspan="4">Girls</th>
                <th colspan="4">Total</th>
            </tr>
            <tr>
                <th>G</th><th>S</th><th>B</th><th>Tot.</th>
                <th>G</th><th>S</th><th>B</th><th>Tot.</th>
                <th>G</th><th>S</th><th>B</th><th>Tot.</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo $r['rank']; ?></td>
                <td><?php echo htmlspecialchars($r['noc']); ?></td>
                <td><?php echo $r['boys'][0]; ?></td><td><?php echo $r['boys'][1]; ?></td><td><?php echo $r['boys'][2]; ?></td><td><strong><?php echo $r['boys_total']; ?></strong></td>
                <td><?php echo $r['girls'][0]; ?></td><td><?php echo $r['girls'][1]; ?></td><td><?php echo $r['girls'][2]; ?></td><td><strong><?php echo $r['girls_total']; ?></strong></td>
                <td><?php echo $r['total'][0]; ?></td><td><?php echo $r['total'][1]; ?></td><td><?php echo $r['total'][2]; ?></td><td><strong><?php echo $r['grand_total']; ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="14" style="color:#888;">No medals decided yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

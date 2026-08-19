<?php
/**
 * Fight Order — auto-reads every CSV in ../related-to-fight-order/ that
 * matches one of this age category's weight/gender combinations, and
 * lists every match whose "Fight Number" cell has been filled in by hand,
 * sorted by fight number.
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
$formTitle = 'Fight Order';

$weightIds = !empty($championSubInfo['corashweights']) ? explode('/', $championSubInfo['corashweights']) : [];
$weightTexts = !empty($championSubInfo['corashweights_text']) ? explode('/', $championSubInfo['corashweights_text']) : [];

$fightOrderDir = __DIR__ . '/../related-to-fight-order';
$fights = [];

foreach ($weightTexts as $weightLabel) {
    foreach (['Male', 'Female'] as $genderLabel) {
        $baseName = preg_replace('/[^A-Za-z0-9 \-+]/', '_', trim($weightLabel) . ' ' . $genderLabel) . '-Draw';
        $path = $fightOrderDir . '/' . $baseName . '.csv';
        if (!is_file($path)) continue;

        $handle = fopen($path, 'r');
        if (!$handle) continue;
        $genderWeightRow = fgetcsv($handle); // "Gender / Weight Category", "Male / -91"
        fgetcsv($handle); // column headers: Match#, Phase, Blue, Green, Fight Number, Winner
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 6) continue;
            [$matchNum, $phase, $blue, $green, $fightNumber, $winner] = $row;
            if (trim($fightNumber) === '') continue; // only show matches the manager has scheduled
            $fights[] = [
                'fight_number' => (int)$fightNumber,
                'phase' => $phase,
                'blue' => $blue,
                'green' => $green,
                'winner' => trim($winner),
                'category' => $genderWeightRow[1] ?? ($genderLabel . ' / ' . $weightLabel),
            ];
        }
        fclose($handle);
    }
}

usort($fights, fn($a, $b) => $a['fight_number'] <=> $b['fight_number']);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fight Order</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px 10px; text-align: center; font-size: 13px; }
        th { background: #f8f9fa; }
        .color-blue { color: #1565c0; font-weight: bold; }
        .color-green { color: #2e7d32; font-weight: bold; }
        .winner-row { background: #e8f5e9; }
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
                <th>Fight Number</th>
                <th>Phase</th>
                <th>Color</th>
                <th>Athlete's Name</th>
                <th>Winner</th>
                <th>Gender/Weight Category</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fights as $f): ?>
            <tr class="<?php echo (trim($f['winner']) !== '' && $f['winner'] === $f['blue']) ? 'winner-row' : ''; ?>">
                <td rowspan="2"><?php echo $f['fight_number']; ?></td>
                <td rowspan="2"><?php echo htmlspecialchars($f['phase']); ?></td>
                <td class="color-blue">Blue</td>
                <td><?php echo htmlspecialchars($f['blue']); ?></td>
                <td rowspan="2"><?php echo htmlspecialchars($f['winner']); ?></td>
                <td rowspan="2"><?php echo htmlspecialchars($f['category']); ?></td>
            </tr>
            <tr class="<?php echo (trim($f['winner']) !== '' && $f['winner'] === $f['green']) ? 'winner-row' : ''; ?>">
                <td class="color-green">Green</td>
                <td><?php echo htmlspecialchars($f['green']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($fights)): ?>
            <tr><td colspan="6" style="color:#888;">No fight numbers assigned yet. Fill them in on the saved draw sheets in related-to-fight-order/.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

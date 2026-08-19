<?php
/**
 * Start Competition — imports Table 2: the same Athlete's Information Table
 * (Table 1) with one extra column appended at the end, "Draw Number",
 * filled in by the executive management outside the system. Uploading
 * here assigns each athlete's draw/lottery number by matching IKA ID.
 */
require_once __DIR__ . '/boot.php';
require_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './control/class.php';
require_once './validate-online.php';

csrf_verify();

$dbcon = new DB();
$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);

$championInfo = $dbcon->getTableById('champions', $champion_id);
$championSubInfo = $dbcon->getTableById('championsubs', $championsub_id);

$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['table2_file']) && $_FILES['table2_file']['error'] === UPLOAD_ERR_OK) {
    $handle = fopen($_FILES['table2_file']['tmp_name'], 'r');
    $updated = 0;
    $notFound = [];
    if ($handle) {
        fgetcsv($handle); // header row: Name, IKA ID, NOC, Gender, Weight Category, Bracket Title, Draw Number
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) continue;
            $ikaId = trim($row[1] ?? '');
            $drawNumber = (int)trim($row[count($row) - 1]); // last column = Draw Number
            if ($ikaId === '' || $drawNumber <= 0) continue;

            $upd = $pdocon->prepare("
                UPDATE championregisterathletes SET corash_lotterynumber = ?
                WHERE ika_id = ? AND champion_id = ? AND championsub_id = ?
            ");
            $upd->execute([$drawNumber, $ikaId, $champion_id, $championsub_id]);
            if ($upd->rowCount() > 0) $updated++; else $notFound[] = $ikaId;
        }
        fclose($handle);
    }
    $message = "{$updated} athlete(s) assigned a draw number." . (!empty($notFound) ? ' Not matched: ' . implode(', ', $notFound) : '');
    $messageType = empty($notFound) ? 'success' : 'error';
}

$stmt = $pdocon->prepare("SELECT COUNT(*) FROM championregisterathletes WHERE champion_id = ? AND championsub_id = ?");
$stmt->execute([$champion_id, $championsub_id]);
$totalAthletes = (int)$stmt->fetchColumn();

$stmt = $pdocon->prepare("SELECT COUNT(*) FROM championregisterathletes WHERE champion_id = ? AND championsub_id = ? AND corash_lotterynumber IS NOT NULL");
$stmt->execute([$champion_id, $championsub_id]);
$assignedCount = (int)$stmt->fetchColumn();

$readyToStart = $totalAthletes > 0 && $assignedCount === $totalAthletes;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Start Competition - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 700px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .back-link { display: inline-block; margin-bottom: 16px; color: #1565c0; text-decoration: none; font-size: 14px; }
        .btn { background: #1565c0; color: #fff; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn:hover { background: #0d47a1; }
        .btn:disabled { background: #ccc; cursor: not-allowed; }
        .btn-start { background: #2e7d32; padding: 14px 36px; font-size: 16px; }
        .btn-start:hover { background: #1b5e20; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; }
        .alert-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        .progress { background: #eee; border-radius: 6px; overflow: hidden; height: 10px; margin: 10px 0 4px; }
        .progress-bar { background: #1565c0; height: 100%; }
        .status-text { font-size: 13px; color: #666; }
        .start-block { text-align: center; margin-top: 30px; padding-top: 24px; border-top: 1px solid #eee; }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
    <a class="back-link" href="main-dashboard.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">&larr; Back to Dashboard</a>
    <h2>Start Competition</h2>
    <p><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?> — <?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?></p>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <p style="font-size:13px; color:#666;">
        Take the exported <strong>Athlete's Information Table</strong>, add one column at the end named
        <strong>Draw Number</strong>, fill it in, save as CSV, then upload it below.
    </p>

    <form method="POST" action="" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center;"><?php echo csrf_field(); ?>
        <input type="file" name="table2_file" accept=".csv" required>
        <button type="submit" class="btn">Upload Table 2</button>
    </form>

    <div style="margin-top:20px;">
        <div class="status-text"><?php echo $assignedCount; ?> / <?php echo $totalAthletes; ?> athletes have a draw number</div>
        <div class="progress"><div class="progress-bar" style="width: <?php echo $totalAthletes > 0 ? round($assignedCount / $totalAthletes * 100) : 0; ?>%;"></div></div>
    </div>

    <div class="start-block">
        <form method="POST" action="wizard-welcome-kurash.php?champion_id=<?php echo $champion_id; ?><?php echo csrf_field(); ?>&championsub_id=<?php echo $championsub_id; ?>">
            <button type="submit" class="btn btn-start" <?php echo $readyToStart ? '' : 'disabled'; ?>>Start</button>
            <?php if (!$readyToStart): ?>
                <p class="status-text">All registered athletes need a draw number before you can start.</p>
            <?php endif; ?>
        </form>
    </div>
</div>
</div>
</body>
</html>

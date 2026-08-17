<?php
/**
 * Kurash Weigh-in Form
 * Item 2 of the implementation plan: Athlete's Name, ID Number, Nationality,
 * Weight-in (measured value), Weight Category (declared / confirmed).
 */
ob_start();
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

$message = null;
$messageType = null;

// Handle weigh-in submission for a single athlete (AJAX-friendly or normal POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['athlete_id'])) {
    $athlete_id = filter_input(INPUT_POST, 'athlete_id', FILTER_VALIDATE_INT);
    $weighin_value = filter_input(INPUT_POST, 'weighin_value', FILTER_VALIDATE_FLOAT);

    if (!$athlete_id || $weighin_value === false || $weighin_value === null) {
        $message = 'Invalid athlete or weight value.';
        $messageType = 'error';
    } else {
        $stmt = $pdocon->prepare("SELECT corashweight_text FROM championregisterathletes WHERE id = ?");
        $stmt->execute([$athlete_id]);
        $athlete = $stmt->fetch(PDO::FETCH_ASSOC);

        // Tolerance rule (0.5kg), sign-aware:
        //   "-91" (max 91kg): OK only within [90.5, 91]
        //   "+91" (min 91kg): OK only at 91.5 and above (no upper bound)
        $status = 'pending';
        if ($athlete && preg_match('/^([+-]?)(\d+(?:\.\d+)?)/', trim($athlete['corashweight_text']), $m)) {
            $sign = $m[1];
            $limit = (float)$m[2];
            if ($sign === '+') {
                if ($weighin_value >= $limit + 0.5) {
                    $status = 'pass';
                }
            } else {
                // default to "-" behavior when no sign is present
                if ($weighin_value >= $limit - 0.5 && $weighin_value <= $limit) {
                    $status = 'pass';
                }
            }
        }

        $stmt = $pdocon->prepare("
            UPDATE championregisterathletes
            SET weighin_value = ?, weighin_status = ?, weighin_datetime = datetime('now'),
                corashweight_net = ?
            WHERE id = ?
        ");
        $stmt->execute([$weighin_value, $status, $weighin_value, $athlete_id]);

        $message = 'Weigh-in recorded: ' . ($status === 'pass' ? 'OK' : 'PENDING');
        $messageType = $status === 'pass' ? 'success' : 'error';
    }
}

$filterWeight = filter_input(INPUT_GET, 'weight_filter', FILTER_VALIDATE_INT);

$weightIds = !empty($championSubInfo['corashweights']) ? explode('/', $championSubInfo['corashweights']) : [];
$weightTexts = !empty($championSubInfo['corashweights_text']) ? explode('/', $championSubInfo['corashweights_text']) : [];

$sql = "SELECT * FROM championregisterathletes WHERE champion_id = ? AND championsub_id = ? AND wushutype = 'Kurash'";
$params = [$champion_id, $championsub_id];
if ($filterWeight) {
    $sql .= " AND corashweight = ?";
    $params[] = $filterWeight;
}
$sql .= " ORDER BY corashweight ASC, fullname ASC";
$stmt = $pdocon->prepare($sql);
$stmt->execute($params);
$athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$formTitle = "Weigh-in Form";
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kurash Weigh-in - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        h2 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
        input[type=number] { width: 90px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; }
        .btn-small { background: #1565c0; color: #fff; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .btn-small:hover { background: #0d47a1; }
        .badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .badge-pending { background: #fff3e0; color: #e65100; }
        .badge-pass { background: #e8f5e9; color: #1b5e20; }
        .badge-fail { background: #fdecea; color: #b71c1c; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; }
        .alert-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
<?php require './page-header-kurash.php'; ?>
    <h2>Kurash Weigh-in</h2>
    <p><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?> — <?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?></p>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="GET" action="" style="margin-bottom:12px; display:flex; gap:10px; align-items:center;">
        <input type="hidden" name="champion_id" value="<?php echo $champion_id; ?>">
        <input type="hidden" name="championsub_id" value="<?php echo $championsub_id; ?>">
        <label style="font-size:13px; font-weight:bold;">Filter by Weight Category:</label>
        <select name="weight_filter" onchange="this.form.submit()">
            <option value="">-- All --</option>
            <?php foreach ($weightIds as $idx => $wid): ?>
                <option value="<?php echo $wid; ?>" <?php echo $filterWeight == $wid ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($weightTexts[$idx] ?? $wid); ?> kg
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <button class="btn-small" onclick="window.print()">Export PDF</button>
    <?php if ($filterWeight): ?>
        <a class="btn-small" style="text-decoration:none; background:#2e7d32;"
           href="weighin-export-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>&weight=<?php echo $filterWeight; ?>">
            Export Excel (Confirmed List)
        </a>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>IKA ID</th>
                <th>Athlete's Name</th>
                <th>Nationality</th>
                <th>Weight Category</th>
                <th>Weigh-in (kg)</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($athletes as $a): ?>
            <tr>
                <td><?php echo htmlspecialchars($a['ika_id'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($a['fullname']); ?></td>
                <td><?php echo htmlspecialchars($a['noc_code']); ?></td>
                <td><?php echo htmlspecialchars($a['corashweight_text']); ?> kg</td>
                <td>
                    <form method="POST" action="" style="display:flex; gap:6px;">
                        <input type="hidden" name="athlete_id" value="<?php echo $a['id']; ?>">
                        <input type="number" step="0.01" name="weighin_value" value="<?php echo htmlspecialchars($a['weighin_value'] ?? ''); ?>" required>
                        <button type="submit" class="btn-small">Save</button>
                    </form>
                </td>
                <td>
                    <?php
                        if ($a['weighin_value'] === null) {
                            $label = 'No weigh-in';
                            $status = 'pending';
                        } elseif (($a['weighin_status'] ?? '') === 'pass') {
                            $label = 'OK';
                            $status = 'pass';
                        } else {
                            $label = 'Pending';
                            $status = 'pending';
                        }
                    ?>
                    <span class="badge badge-<?php echo $status; ?>"><?php echo $label; ?></span>
                </td>
                <td></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($athletes)): ?>
            <tr><td colspan="7" style="text-align:center; color:#888;">No registered athletes found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

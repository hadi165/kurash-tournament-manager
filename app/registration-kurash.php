<?php
/**
 * Kurash Athlete Registration
 * Generates an independent international IKA ID for every athlete on
 * registration, separate from any national ID/passport number.
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
$board_id = filter_input(INPUT_GET, 'board_id', FILTER_VALIDATE_INT);

$championInfo = $dbcon->getTableById('champions', $champion_id);
$championSubInfo = $dbcon->getTableById('championsubs', $championsub_id);

$weightIds = !empty($championSubInfo['corashweights']) ? explode('/', $championSubInfo['corashweights']) : [];
$weightTexts = !empty($championSubInfo['corashweights_text']) ? explode('/', $championSubInfo['corashweights_text']) : [];

$errors = [];
$success = false;
$newIkaId = null;

// Handle editing an existing athlete (separate form/action from the "create" form below)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_athlete_id'])) {
    $editId = (int)$_POST['edit_athlete_id'];
    $fullname = trim(filter_input(INPUT_POST, 'fullname', FILTER_SANITIZE_SPECIAL_CHARS));
    $noc_code = trim(filter_input(INPUT_POST, 'noc_code', FILTER_SANITIZE_SPECIAL_CHARS));
    $noc_name = trim(filter_input(INPUT_POST, 'noc_name', FILTER_SANITIZE_SPECIAL_CHARS));
    $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_SPECIAL_CHARS);
    $weight_index = filter_input(INPUT_POST, 'weight_category', FILTER_VALIDATE_INT);
    $nationalcode = trim(filter_input(INPUT_POST, 'nationalcode', FILTER_SANITIZE_SPECIAL_CHARS));

    if ($fullname !== '' && $noc_code !== '' && in_array($gender, ['M', 'F'], true) && isset($weightIds[$weight_index])) {
        $stmt = $pdocon->prepare("
            UPDATE championregisterathletes
            SET fullname = ?, noc_code = ?, noc_name = ?, gender = ?, nationalcode = ?,
                corashweight = ?, corashweight_text = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $fullname, $noc_code, $noc_name, $gender, $nationalcode !== '' ? $nationalcode : null,
            $weightIds[$weight_index], $weightTexts[$weight_index] ?? '',
            $editId
        ]);
    }
    header('Location: registration-kurash.php?champion_id=' . $champion_id . '&championsub_id=' . $championsub_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim(filter_input(INPUT_POST, 'fullname', FILTER_SANITIZE_SPECIAL_CHARS));
    $nationalcode = trim(filter_input(INPUT_POST, 'nationalcode', FILTER_SANITIZE_SPECIAL_CHARS)); // optional
    $noc_code = trim(filter_input(INPUT_POST, 'noc_code', FILTER_SANITIZE_SPECIAL_CHARS));
    $noc_name = trim(filter_input(INPUT_POST, 'noc_name', FILTER_SANITIZE_SPECIAL_CHARS));
    $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_SPECIAL_CHARS);
    $weight_index = filter_input(INPUT_POST, 'weight_category', FILTER_VALIDATE_INT);

    if ($fullname === '') $errors[] = 'Athlete name is required.';
    if ($noc_code === '') $errors[] = 'Nationality (NOC) is required.';
    if (!in_array($gender, ['M', 'F'], true)) $errors[] = 'Gender must be selected.';
    if ($weight_index === false || $weight_index === null || !isset($weightIds[$weight_index])) {
        $errors[] = 'Weight category is required.';
    }

    if (empty($errors)) {
        $corashweight = $weightIds[$weight_index];
        $corashweight_text = $weightTexts[$weight_index] ?? '';

        $stmt = $pdocon->prepare("
            INSERT INTO championregisterathletes
                (register_id, board_id, champion_id, championsub_id, agecategory_text,
                 nationalcode, fullname, gender, noc_code, noc_name,
                 corashweight, corashweight_text, wushutype)
            VALUES
                (NULL, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?,
                 ?, ?, 'Kurash')
        ");
        $stmt->execute([
            $board_id,
            $champion_id,
            $championsub_id,
            $championSubInfo['subtitle'] ?? null,
            $nationalcode !== '' ? $nationalcode : null,
            $fullname,
            $gender,
            $noc_code,
            $noc_name,
            $corashweight,
            $corashweight_text
        ]);

        // Generate the independent IKA ID from the row's own auto-increment id
        $newId = (int)$pdocon->lastInsertId();
        $newIkaId = 'IKA' . str_pad((string)$newId, 6, '0', STR_PAD_LEFT);

        $update = $pdocon->prepare("UPDATE championregisterathletes SET ika_id = ? WHERE id = ?");
        $update->execute([$newIkaId, $newId]);

        $success = true;
    }
}

$stmt = $pdocon->prepare("
    SELECT * FROM championregisterathletes
    WHERE champion_id = ? AND championsub_id = ? AND wushutype = 'Kurash'
    ORDER BY id DESC
");
$stmt->execute([$champion_id, $championsub_id]);
$registeredAthletes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$formTitle = "Athlete's Registration";
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kurash Athlete Registration - <?php echo htmlspecialchars($championInfo['title'] ?? ''); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        h2 { margin-top: 0; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        label { font-weight: bold; margin-bottom: 6px; font-size: 14px; }
        input, select { padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .btn { background: #1565c0; color: #fff; border: none; padding: 10px 24px; border-radius: 4px; font-size: 15px; cursor: pointer; }
        .btn:hover { background: #0d47a1; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; }
        .alert-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
        .ika-badge { display: inline-block; background: #eef2ff; color: #3730a3; font-weight: bold; padding: 3px 10px; border-radius: 12px; font-size: 13px; }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
<?php require './page-header-kurash.php'; ?>
    <h2>Kurash Athlete Registration</h2>
    <p><?php echo htmlspecialchars($championInfo['title'] ?? ''); ?> — <?php echo htmlspecialchars($championSubInfo['subtitle'] ?? ''); ?></p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul style="margin:0; padding-left:18px;">
                <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            Athlete registered successfully. IKA ID:
            <span class="ika-badge"><?php echo htmlspecialchars($newIkaId); ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-grid">
            <div class="form-group">
                <label for="fullname">Athlete's Name</label>
                <input type="text" id="fullname" name="fullname" required>
            </div>
            <div class="form-group">
                <label for="nationalcode">National ID / Passport (optional)</label>
                <input type="text" id="nationalcode" name="nationalcode">
            </div>
            <div class="form-group">
                <label for="noc_code">Nationality (NOC Code)</label>
                <input type="text" id="noc_code" name="noc_code" maxlength="10" placeholder="e.g. UZB" required>
            </div>
            <div class="form-group">
                <label for="noc_name">Nationality (Full Name)</label>
                <input type="text" id="noc_name" name="noc_name" placeholder="e.g. Uzbekistan">
            </div>
            <div class="form-group">
                <label for="gender">Gender</label>
                <select id="gender" name="gender" required>
                    <option value="">-- Select --</option>
                    <option value="M">Male</option>
                    <option value="F">Female</option>
                </select>
            </div>
            <div class="form-group">
                <label for="weight_category">Athlete's Weight (Declared Category)</label>
                <select id="weight_category" name="weight_category" required>
                    <option value="">-- Select --</option>
                    <?php foreach ($weightIds as $idx => $wid): ?>
                        <option value="<?php echo $idx; ?>"><?php echo htmlspecialchars($weightTexts[$idx] ?? $wid); ?> kg</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn">Register Athlete</button>
    </form>

    <h3>Registered Athletes</h3>
    <button class="btn" style="background:#fff; color:#1565c0; border:1px solid #1565c0; margin-bottom:10px;" onclick="window.print()">Export PDF</button>
    <a class="btn" style="background:#2e7d32; text-decoration:none; margin-bottom:10px; display:inline-block;" href="athlete-table-export-kurash.php?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>">Export Excel</a>
    <table>
        <thead>
            <tr>
                <th>IKA ID</th>
                <th>Athlete's Name</th>
                <th>National ID</th>
                <th>Nationality (NOC)</th>
                <th>Gender</th>
                <th>Weight Category</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php $editAthleteId = filter_input(INPUT_GET, 'edit_athlete', FILTER_VALIDATE_INT); ?>
            <?php foreach ($registeredAthletes as $a): ?>
            <?php if ($editAthleteId === (int)$a['id']): ?>
            <tr>
                <td colspan="7">
                    <form method="POST" action="" style="display:grid; grid-template-columns: repeat(3,1fr); gap:10px; align-items:end;">
                        <input type="hidden" name="edit_athlete_id" value="<?php echo $a['id']; ?>">
                        <div class="form-group"><label>Name</label><input type="text" name="fullname" value="<?php echo htmlspecialchars($a['fullname']); ?>" required></div>
                        <div class="form-group"><label>National ID</label><input type="text" name="nationalcode" value="<?php echo htmlspecialchars($a['nationalcode'] ?? ''); ?>"></div>
                        <div class="form-group"><label>NOC Code</label><input type="text" name="noc_code" value="<?php echo htmlspecialchars($a['noc_code']); ?>" required></div>
                        <div class="form-group"><label>NOC Name</label><input type="text" name="noc_name" value="<?php echo htmlspecialchars($a['noc_name'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Gender</label>
                            <select name="gender" required>
                                <option value="M" <?php echo $a['gender'] === 'M' ? 'selected' : ''; ?>>Male</option>
                                <option value="F" <?php echo $a['gender'] === 'F' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group"><label>Weight Category</label>
                            <select name="weight_category" required>
                                <?php foreach ($weightIds as $idx => $wid): ?>
                                    <option value="<?php echo $idx; ?>" <?php echo $a['corashweight'] == $wid ? 'selected' : ''; ?>><?php echo htmlspecialchars($weightTexts[$idx] ?? $wid); ?> kg</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button type="submit" class="btn">Save</button>
                            <a href="?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>" class="btn" style="background:#eee; color:#333;">Cancel</a>
                        </div>
                    </form>
                </td>
            </tr>
            <?php else: ?>
            <tr>
                <td><span class="ika-badge"><?php echo htmlspecialchars($a['ika_id'] ?? '-'); ?></span></td>
                <td><?php echo htmlspecialchars($a['fullname']); ?></td>
                <td><?php echo htmlspecialchars($a['nationalcode'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($a['noc_code']); ?><?php echo $a['noc_name'] ? ' - ' . htmlspecialchars($a['noc_name']) : ''; ?></td>
                <td><?php echo $a['gender'] === 'M' ? 'Male' : ($a['gender'] === 'F' ? 'Female' : ''); ?></td>
                <td><?php echo htmlspecialchars($a['corashweight_text']); ?> kg</td>
                <td><a href="?champion_id=<?php echo $champion_id; ?>&championsub_id=<?php echo $championsub_id; ?>&edit_athlete=<?php echo $a['id']; ?>" class="btn-link" style="background:#eef2ff; color:#3730a3; padding:4px 10px; border-radius:4px; text-decoration:none; font-size:12px;">Edit</a></td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if (empty($registeredAthletes)): ?>
            <tr><td colspan="7" style="text-align:center; color:#888;">No athletes registered yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

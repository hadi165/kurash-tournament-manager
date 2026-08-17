<?php
/**
 * Age/weight category (championsub) management for a given championship.
 * Step 2: for each championship, define its age categories (e.g. "Men Senior")
 * and the Kurash weight classes within it (e.g. -66, -73, -81, -90).
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
$championsub_id = null;
if (!$champion_id) {
    die('Missing champion_id. Go back to <a href="champions-manage.php">Championship Management</a> first.');
}

$championInfo = $dbcon->getTableById('champions', $champion_id);
if (empty($championInfo)) {
    die('Championship not found.');
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delId = (int)$_POST['delete_id'];
    $pdocon->prepare("DELETE FROM championsubs WHERE id = ?")->execute([$delId]);
    header('Location: championsubs-manage.php?champion_id=' . $champion_id);
    exit;
}

$editingId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editingSub = null;
if ($editingId) {
    $stmt = $pdocon->prepare("SELECT * FROM championsubs WHERE id = ?");
    $stmt->execute([$editingId]);
    $editingSub = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subtitle = trim(filter_input(INPUT_POST, 'subtitle', FILTER_SANITIZE_SPECIAL_CHARS));
    $weightsRaw = trim(filter_input(INPUT_POST, 'weights', FILTER_SANITIZE_SPECIAL_CHARS));
    $editId = filter_input(INPUT_POST, 'edit_id', FILTER_VALIDATE_INT);

    if ($subtitle === '') $errors[] = 'Age category name is required.';
    if ($weightsRaw === '') $errors[] = 'At least one weight category is required.';

    if (empty($errors)) {
        // Turn "-66, -73, -81, -90" into corashweights = "1/2/3/4" and
        // corashweights_text = "-66/-73/-81/-90" (the format the rest of
        // the system — registration, brackets, rankings — expects).
        $labels = array_filter(array_map('trim', explode(',', $weightsRaw)));
        $labels = array_values($labels);
        $ids = range(1, count($labels));

        $corashweights = implode('/', $ids);
        $corashweights_text = implode('/', $labels);

        if ($editId) {
            $stmt = $pdocon->prepare("UPDATE championsubs SET subtitle = ?, corashweights = ?, corashweights_text = ? WHERE id = ?");
            $stmt->execute([$subtitle, $corashweights, $corashweights_text, $editId]);
        } else {
            $stmt = $pdocon->prepare("
                INSERT INTO championsubs (champion_id, subtitle, corashweights, corashweights_text)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$champion_id, $subtitle, $corashweights, $corashweights_text]);
        }
        header('Location: championsubs-manage.php?champion_id=' . $champion_id);
        exit;
    }
}

$stmt = $pdocon->prepare("SELECT * FROM championsubs WHERE champion_id = ? ORDER BY id DESC");
$stmt->execute([$champion_id]);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Age Categories - <?php echo htmlspecialchars($championInfo['title']); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        h2 { margin-top: 0; }
        .back-link { display: inline-block; margin-bottom: 16px; color: #1565c0; text-decoration: none; font-size: 14px; }
        .form-grid { display: grid; grid-template-columns: 1fr 2fr auto; gap: 12px; margin-bottom: 8px; align-items: end; }
        .form-group { display: flex; flex-direction: column; }
        label { font-weight: bold; margin-bottom: 6px; font-size: 13px; }
        input[type=text] { padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .btn { background: #1565c0; color: #fff; border: none; padding: 10px 24px; border-radius: 4px; font-size: 15px; cursor: pointer; height: 40px; }
        .btn:hover { background: #0d47a1; }
        .btn-link { background: #eef2ff; color: #3730a3; text-decoration: none; padding: 6px 14px; border-radius: 4px; font-size: 13px; margin-right: 6px; display: inline-block; }
        .btn-link:hover { background: #dde3ff; }
        .hint { color: #888; font-size: 12px; margin-bottom: 20px; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; }
        .alert-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
    <a class="back-link" href="champions-manage.php">&larr; Back to Championships</a>
    <h2>Age Categories</h2>
    <p><?php echo htmlspecialchars($championInfo['title']); ?></p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?php foreach ($errors as $e) echo htmlspecialchars($e); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success">Age category created successfully.</div>
    <?php endif; ?>

    <?php if ($editingSub): ?>
        <p style="font-size:13px; color:#1565c0;">Editing: <?php echo htmlspecialchars($editingSub['subtitle']); ?></p>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="edit_id" value="<?php echo $editingSub['id'] ?? ''; ?>">
        <div class="form-grid">
            <div class="form-group">
                <label>Age Category Name</label>
                <input type="text" name="subtitle" placeholder="e.g. Men Senior" value="<?php echo htmlspecialchars($editingSub['subtitle'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label>Weight Categories (comma-separated)</label>
                <input type="text" name="weights" placeholder="e.g. -66, -73, -81, -90, +90"
                       value="<?php echo $editingSub ? htmlspecialchars(str_replace('/', ', ', $editingSub['corashweights_text'])) : ''; ?>" required>
            </div>
            <button type="submit" class="btn"><?php echo $editingSub ? 'Save Changes' : 'Add'; ?></button>
        </div>
        <?php if ($editingSub): ?>
            <a href="championsubs-manage.php?champion_id=<?php echo $champion_id; ?>" style="font-size:13px; color:#888;">Cancel edit</a>
        <?php endif; ?>
    </form>
    <p class="hint">Type each weight class separated by a comma, in the order you want them displayed. Example: -60, -66, -73, -81, -90, +90</p>

    <div style="margin-bottom:10px;">
        <button class="btn-link" style="border:1px solid #1565c0; background:#fff; cursor:pointer;" onclick="window.print()">Export PDF</button>
    </div>

    <table>
        <thead>
            <tr>
                <th>Age Category</th>
                <th>Weight Classes</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($subs as $s): ?>
            <tr>
                <td><?php echo htmlspecialchars($s['subtitle']); ?></td>
                <td><?php echo htmlspecialchars(str_replace('/', ', ', $s['corashweights_text'] ?? '')); ?> kg</td>
                <td>
                    <a class="btn-link" href="?champion_id=<?php echo $champion_id; ?>&edit=<?php echo $s['id']; ?>">Edit</a>
                    <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Delete this age category?');">
                        <input type="hidden" name="delete_id" value="<?php echo $s['id']; ?>">
                        <button type="submit" class="btn-link" style="background:#fdecea; color:#b71c1c; border:none; cursor:pointer;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($subs)): ?>
            <tr><td colspan="3" style="text-align:center; color:#888;">No age categories yet — add one above.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

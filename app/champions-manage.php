<?php
/**
 * Championship (tournament) management.
 * This is the very first step: define a tournament before anything else
 * (age categories, registration, brackets...) can happen.
 */
ob_start();
session_start();

include_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './control/class.php';
require_once './validate-online.php';

$dbcon = new DB();
$champion_id = null;
$championsub_id = null;

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delId = (int)$_POST['delete_id'];
    $pdocon->prepare("DELETE FROM champions WHERE id = ?")->execute([$delId]);
    header('Location: champions-manage.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $editId = (int)$_POST['edit_id'];
    $newTitle = trim(filter_input(INPUT_POST, 'edit_title', FILTER_SANITIZE_SPECIAL_CHARS));
    if ($newTitle !== '') {
        $pdocon->prepare("UPDATE champions SET title = ? WHERE id = ?")->execute([$newTitle, $editId]);
    }
    header('Location: champions-manage.php');
    exit;
}

$editingId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim(filter_input(INPUT_POST, 'title', FILTER_SANITIZE_SPECIAL_CHARS));

    if ($title === '') {
        $errors[] = 'Championship title is required.';
    }

    if (empty($errors)) {
        $stmt = $pdocon->prepare("INSERT INTO champions (title) VALUES (?)");
        $stmt->execute([$title]);
        $success = true;
    }
}

$championships = $pdocon->query("SELECT * FROM champions ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Count age categories per championship, for display
$subCounts = [];
$countRows = $pdocon->query("SELECT champion_id, COUNT(*) as cnt FROM championsubs GROUP BY champion_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($countRows as $row) {
    $subCounts[$row['champion_id']] = $row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Championship Management</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        h2 { margin-top: 0; }
        .form-row { display: flex; gap: 12px; margin-bottom: 20px; }
        input[type=text] { flex: 1; padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .btn { background: #1565c0; color: #fff; border: none; padding: 10px 24px; border-radius: 4px; font-size: 15px; cursor: pointer; white-space: nowrap; }
        .btn:hover { background: #0d47a1; }
        .btn-link { background: #eef2ff; color: #3730a3; text-decoration: none; padding: 6px 14px; border-radius: 4px; font-size: 13px; margin-right: 6px; display: inline-block; }
        .btn-link:hover { background: #dde3ff; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; }
        .alert-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 14px; }
        th { background: #f8f9fa; }
        .muted { color: #888; }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
<div class="container">
    <h2>Championship Management</h2>
    <p class="muted">Step 1: define a tournament. After creating one, you'll add its age/weight categories, then move on to athlete registration.</p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?php foreach ($errors as $e) echo htmlspecialchars($e); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success">Championship created successfully.</div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-row">
            <input type="text" name="title" placeholder="e.g. Asian Kurash Championship 2026" required>
            <button type="submit" class="btn">Create Championship</button>
        </div>
    </form>

    <div style="margin-top:10px;">
        <button class="btn" style="background:#fff; color:#1565c0; border:1px solid #1565c0;" onclick="window.print()">Export PDF</button>
    </div>

    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>No. of Categories</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($championships as $c): ?>
            <tr>
                <td>
                    <?php if ($editingId === (int)$c['id']): ?>
                        <form method="POST" action="" style="display:flex; gap:6px;">
                            <input type="hidden" name="edit_id" value="<?php echo $c['id']; ?>">
                            <input type="text" name="edit_title" value="<?php echo htmlspecialchars($c['title']); ?>" required>
                            <button type="submit" class="btn" style="padding:4px 10px;">Save</button>
                        </form>
                    <?php else: ?>
                        <a href="main-dashboard.php?champion_id=<?php echo $c['id']; ?>" style="color:#0d1b3d; text-decoration:none; font-weight:bold;">
                            <?php echo htmlspecialchars($c['title']); ?>
                        </a>
                    <?php endif; ?>
                </td>
                <td><?php echo (int)($subCounts[$c['id']] ?? 0); ?></td>
                <td>
                    <a class="btn-link" href="?edit=<?php echo $c['id']; ?>">Edit</a>
                    <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Delete this championship and all its data?');">
                        <input type="hidden" name="delete_id" value="<?php echo $c['id']; ?>">
                        <button type="submit" class="btn-link" style="background:#fdecea; color:#b71c1c; border:none; cursor:pointer;">Delete</button>
                    </form>
                    <a class="btn-link" href="championsubs-manage.php?champion_id=<?php echo $c['id']; ?>">Manage Categories</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($championships)): ?>
            <tr><td colspan="3" style="text-align:center; color:#888;">No championships yet — create one above.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>

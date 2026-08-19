<?php
require_once __DIR__ . '/boot.php';
require_once './control/config.php';
require_once './control/DB.php';
require_once './control/pdo-connection.php';
require_once './control/class.php';
require_once './validate-online.php';

$dbcon = new DB();
$champion_id = filter_input(INPUT_GET, 'champion_id', FILTER_VALIDATE_INT);
$championsub_id = filter_input(INPUT_GET, 'championsub_id', FILTER_VALIDATE_INT);

$championships = $pdocon->query("SELECT * FROM champions ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$subs = [];
if ($champion_id) {
    $stmt = $pdocon->prepare("SELECT * FROM championsubs WHERE champion_id = ? ORDER BY id DESC");
    $stmt->execute([$champion_id]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Championship Management Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f5f5f5; margin: 0; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .user-badge { font-size: 13px; color: #555; }
        .card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 2px 4px rgba(0,0,0,.08); }
        select { padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; margin-right: 10px; }
        .hint { color: #888; font-size: 13px; margin-top: 10px; }
    </style>
</head>
<body class="with-sidebar">
<?php include 'sidebar-kurash.php'; ?>
<div class="app-main">
    <div class="topbar">
        <h2 style="margin:0;">Championship Management Dashboard</h2>
        <div class="user-badge">Logged in as <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></div>
    </div>
    <div class="card">
        <form method="GET" action="">
            <label><strong>Championship:</strong></label>
            <select name="champion_id" onchange="this.form.submit()">
                <option value="">-- Select --</option>
                <?php foreach ($championships as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] == $champion_id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($champion_id): ?>
                <label><strong>Age Category:</strong></label>
                <select name="championsub_id" onchange="this.form.submit()">
                    <option value="">-- Select --</option>
                    <?php foreach ($subs as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo $s['id'] == $championsub_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['subtitle']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </form>

        <?php if (!$champion_id || !$championsub_id): ?>
            <p class="hint">Select (or first create, under "Championship Management") a championship and age category to unlock the sidebar.</p>
        <?php else: ?>
            <p class="hint">Ready — use the sidebar to move through registration, weigh-in, and the entries tables for this age category.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

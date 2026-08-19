<?php
require_once __DIR__ . '/boot.php';
require_once './control/pdo-connection.php';

if (!empty($_SESSION['logged_in'])) {
    header('Location: welcome.php');
    exit;
}

$error = null;
$notice = isset($_GET['expired']) ? 'You were signed out after 30 minutes of inactivity.' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdocon->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // New session ID on privilege change, so a session fixed by an
        // attacker before login is worthless after it.
        session_regenerate_id(true);

        $_SESSION['logged_in'] = true;
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['last_seen'] = time();
        header('Location: welcome.php');
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Kurash Championship System</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #0d1b3d; margin: 0; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-box { background: #fff; padding: 36px; border-radius: 10px; width: 320px; box-shadow: 0 8px 24px rgba(0,0,0,.3); }
        h2 { margin-top: 0; text-align: center; color: #0d1b3d; }
        .form-group { margin-bottom: 16px; display: flex; flex-direction: column; }
        label { font-size: 13px; font-weight: bold; margin-bottom: 6px; }
        input { padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .btn { width: 100%; background: #1565c0; color: #fff; border: none; padding: 12px; border-radius: 4px; font-size: 15px; cursor: pointer; margin-top: 8px; }
        .btn:hover { background: #0d47a1; }
        .alert-error { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; padding: 10px; border-radius: 4px; margin-bottom: 16px; font-size: 13px; }
        .alert-notice { background: #e8f0fe; color: #0d47a1; border: 1px solid #c5d9f7; padding: 10px; border-radius: 4px; margin-bottom: 16px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Kurash System</h2>
        <?php if ($notice): ?>
            <div class="alert-notice"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn">Log In</button>
        </form>
    </div>
</body>
</html>

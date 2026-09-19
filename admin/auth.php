<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/app_config.php';

$config = applicationConfig();
$adminConfig = $config['admin'];
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');

function completeLogin(): never
{
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    unset($_SESSION['login_csrf_token']);
    header('Location: index.php');
    exit;
}

if (isset($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

if (ipAllowed($clientIp, $adminConfig['ip_allowlist'])) {
    completeLogin();
}

if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $submittedPassword = (string)($_POST['password'] ?? '');
    if ($submittedToken === '' || !hash_equals((string)$_SESSION['login_csrf_token'], $submittedToken)) {
        http_response_code(403);
        $error = 'Invalid request token.';
    } elseif (password_verify($submittedPassword, $adminConfig['password_hash'])) {
        completeLogin();
    } else {
        http_response_code(401);
        $error = 'Invalid password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Printer Admin Login</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="card">
    <form method="post">
        <h2>Admin Login</h2>
        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['login_csrf_token']) ?>">
        <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
        <button class="button" type="submit">Login</button>
    </form>
</div>
</body>
</html>

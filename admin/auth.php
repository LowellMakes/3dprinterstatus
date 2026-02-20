<?php
session_start();

$allowed_subnet = '192.168.1.'; 
$password = '{{ auth_password }}';

#if (!str_starts_with($_SERVER['REMOTE_ADDR'], $allowed_subnet)) {
#    die('Access denied (not on local network)');
#}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['password'] === $password) {
        $_SESSION['logged_in'] = true;
        header('Location: index.php');
        exit;
    }
}

?>
<form method="post">
    <h2>Admin Login</h2>
    <input type="password" name="password" placeholder="Password">
    <button type="submit">Login</button>
</form>


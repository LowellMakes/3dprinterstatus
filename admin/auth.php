<?php
session_start();

$password = '{{ 3dps_auth_password }}';
$ip_allowlist = explode(',', '{{ 3dps_allowed_ips }}');
$ip_allowlist = array_map('trim', $ip_allowlist);
$client_ip = $_SERVER['REMOTE_ADDR'];

function ip_allowed($client_ip, $allowlist) {

    foreach ($allowlist as $rule) {

        // CIDR
        if (strpos($rule, '/') !== false) {
            [$subnet, $mask] = explode('/', $rule);

            if ((ip2long($client_ip) & ~((1 << (32 - $mask)) - 1)) == ip2long($subnet)) {
                return true;
            }
        }

        // exact match
        if ($client_ip === $rule) {
            return true;
        }

        // prefix match (partial IP like 10.0.0.)
        if (str_ends_with($rule, '.') && str_starts_with($client_ip, $rule)) {
            return true;
        }
    }

    return false;
}

if (ip_allowed($client_ip, $ip_allowlist)) {
    $_SESSION['logged_in'] = true;
    header('Location: index.php');
    exit;
}else {
    die('Access denied (not on local network)');
}


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

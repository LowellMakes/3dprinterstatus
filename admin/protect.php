<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['logged_in'])) {
    header('Location: auth.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string
{
    return (string)$_SESSION['csrf_token'];
}

function require_csrf_token(): void
{
    $submitted = (string)($_POST['csrf_token'] ?? '');
    if ($submitted === '' || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

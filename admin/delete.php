<?php

declare(strict_types=1);

require 'protect.php';
require_once __DIR__ . '/../lib/printer_providers.php';
require_once __DIR__ . '/../lib/app_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

require_csrf_token();

$applicationConfig = applicationConfig();
$file = $applicationConfig['printers_file'];
$cacheFile = $applicationConfig['cache_file'];
$printers = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
$printers = is_array($printers) ? $printers : [];

try {
    $id = validatedPrinterId($_POST['id'] ?? null, count($printers));
} catch (InvalidArgumentException) {
    http_response_code(404);
    exit('Printer not found.');
}

unset($printers[$id]);
writeJsonFile($file, array_values($printers));
if (is_file($cacheFile)) {
    unlink($cacheFile);
}

header('Location: index.php');

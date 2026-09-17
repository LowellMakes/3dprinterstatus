<?php

declare(strict_types=1);

require 'protect.php';
require_once __DIR__ . '/../lib/printer_providers.php';

header('Content-Type: application/json');
require_csrf_token();

if (isset($_POST['id'])) {
    $printersFile = __DIR__ . '/../../private/printers.json';
    $printers = is_file($printersFile)
        ? json_decode((string)file_get_contents($printersFile), true)
        : [];
    try {
        $id = validatedPrinterId($_POST['id'], is_array($printers) ? count($printers) : 0);
    } catch (InvalidArgumentException) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Printer not found']);
        exit;
    }
    if (!is_array($printers) || !isset($printers[$id])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Printer not found']);
        exit;
    }
    $printer = $printers[$id];
    $provider = printerProvider($printer);
} else {
    $provider = printerProvider(['provider' => $_POST['provider'] ?? 'octoprint']);
    $printer = [
        'provider' => $provider,
        'printerName' => 'Unknown printer',
        'active' => true,
    ];

    if ($provider === 'homeassistant') {
        $printer['entityPrefix'] = strtolower(trim((string)($_POST['entityPrefix'] ?? '')));
    } else {
        $printer['url'] = trim((string)($_POST['url'] ?? ''));
        $printer['apiKey'] = trim((string)($_POST['apiKey'] ?? ''));
    }
}

try {
    if ($provider === 'homeassistant') {
        buildHomeAssistantTemplate((string)$printer['entityPrefix']);
    } else {
        $printer['url'] = validatedHttpBaseUrl((string)($printer['url'] ?? ''));
    }

    $homeAssistantConfig = loadHomeAssistantConfig(__DIR__ . '/../../private/homeassistant.json');
    $result = fetchPrinter($printer, $homeAssistantConfig);
    if ($result['status'] === 'Offline') {
        throw new RuntimeException('Connection failed');
    }

    echo json_encode([
        'success' => true,
        'name' => $result['name'],
        'model' => $result['model'],
    ]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}

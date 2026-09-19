<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/printer_providers.php';

$printersFile = __DIR__ . '/../private/printers.json';
$homeAssistantFile = __DIR__ . '/../private/homeassistant.json';
$cacheFile = '/tmp/printer_data_cache.json';

function loadPrinters(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function fetchPrinterData(array $printers, array $homeAssistantConfig): array
{
    $printerData = [];
    foreach ($printers as $printer) {
        if (!printerIsActive($printer)) {
            continue;
        }

        $printerData[] = fetchPrinter($printer, $homeAssistantConfig);
    }

    return $printerData;
}

function getCachedData(string $cacheFile, int $maxAge = 60): string|false
{
    if (!is_file($cacheFile)) {
        return false;
    }

    return time() - filemtime($cacheFile) < $maxAge
        ? file_get_contents($cacheFile)
        : false;
}

$cachedData = getCachedData($cacheFile);
if ($cachedData === false) {
    $printers = loadPrinters($printersFile);
    $homeAssistantConfig = loadHomeAssistantConfig($homeAssistantFile);
    $cachedData = json_encode(
        fetchPrinterData($printers, $homeAssistantConfig),
        JSON_UNESCAPED_SLASHES
    );
    if ($cachedData === false) {
        $cachedData = '[]';
    }
    file_put_contents($cacheFile, $cachedData, LOCK_EX);
}

header('Content-Type: application/json');
echo $cachedData;

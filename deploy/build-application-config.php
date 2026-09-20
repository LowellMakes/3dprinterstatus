<?php

declare(strict_types=1);

require_once __DIR__ . '/migrate-legacy-auth.php';

if ($argc !== 6) {
    fwrite(STDERR, "Usage: php build-application-config.php <legacy-ha.json> <legacy-auth.php> <printers-file> <cache-file> <environment>\n");
    exit(2);
}

try {
    [$script, $legacyHaPath, $legacyAuthPath, $printersFile, $cacheFile, $environment] = $argv;
    if (!in_array($environment, ['live', 'staging'], true)) {
        throw new RuntimeException('Environment must be live or staging.');
    }
    $homeAssistant = json_decode((string)file_get_contents($legacyHaPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($homeAssistant)
        || !is_string($homeAssistant['url'] ?? null)
        || !is_string($homeAssistant['token'] ?? null)
        || trim($homeAssistant['url']) === ''
        || trim($homeAssistant['token']) === '') {
        throw new RuntimeException('Legacy Home Assistant configuration is invalid.');
    }
    $admin = extractLegacyAuth($legacyAuthPath);
    echo json_encode([
        'printers_file' => $printersFile,
        'cache_file' => $cacheFile,
        'home_assistant' => [
            'url' => $homeAssistant['url'],
            'token' => $homeAssistant['token'],
        ],
        'admin' => $admin,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

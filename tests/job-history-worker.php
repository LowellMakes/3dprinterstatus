<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 6) {
    exit(2);
}

require_once __DIR__ . '/../lib/printer_providers.php';
require_once __DIR__ . '/../lib/job_history.php';

[, $historyPath, $gatePath, $workerId, $url, $filename] = $argv;
file_put_contents($gatePath . '.ready.' . $workerId, '');
$deadline = microtime(true) + 10;
while (!is_file($gatePath)) {
    if (microtime(true) >= $deadline) {
        exit(3);
    }
    usleep(1000);
}

$rows = applyPrinterJobHistory(
    $historyPath,
    [['provider' => 'octoprint', 'url' => $url, 'active' => true]],
    [['name' => $workerId, 'file' => $filename]],
    static function (string $message): void {
    }
);

exit(($rows[0]['file'] ?? '') === $filename ? 0 : 4);

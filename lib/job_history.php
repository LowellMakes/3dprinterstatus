<?php

declare(strict_types=1);

function printerJobHistoryPath(string $cacheFile): string
{
    return $cacheFile . '.last-jobs.json';
}

function printerJobHistoryKey(array $printer): ?string
{
    $provider = printerProvider($printer);
    if ($provider === 'homeassistant') {
        $identity = strtolower(trim((string)($printer['entityPrefix'] ?? '')));
        if (!preg_match('/^[a-z0-9_]+$/', $identity)) {
            return null;
        }

        return hash('sha256', $provider . "\0" . $identity);
    }

    try {
        $url = validatedHttpBaseUrl((string)($printer['url'] ?? ''));
    } catch (InvalidArgumentException) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }

    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    if (str_contains($host, ':') && $host[0] !== '[') {
        $host = '[' . $host . ']';
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = rtrim((string)($parts['path'] ?? ''), '/');
    $identity = $scheme . '://' . $host . $port . $path;

    return hash('sha256', $provider . "\0" . $identity);
}

function normalizedJobFilename(mixed $filename): string
{
    if (!is_string($filename)) {
        return '';
    }
    $encoded = json_encode($filename, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $filename = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
    $filename = trim($filename);
    if ($filename === '') {
        return '';
    }

    $filename = basename(str_replace('\\', '/', $filename));
    if (strlen($filename) <= 512) {
        return $filename;
    }

    $filename = substr($filename, 0, 512);
    while ($filename !== '' && preg_match('//u', $filename) !== 1) {
        $filename = substr($filename, 0, -1);
    }

    return $filename;
}

function loadPrinterJobHistory(string $path): array
{
    $size = @filesize($path);
    if (!is_file($path) || $size === false || $size > 1048576) {
        return [];
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return [];
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }

    $history = [];
    foreach ($decoded as $key => $filename) {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/', $key) || !is_string($filename)) {
            continue;
        }
        $filename = normalizedJobFilename($filename);
        if ($filename !== '') {
            $history[$key] = $filename;
        }
    }

    return $history;
}

function writePrinterJobHistory(string $path, array $history): void
{
    $directory = dirname($path);
    $temporary = @tempnam($directory, '.last-jobs.');
    if ($temporary === false) {
        throw new RuntimeException('Could not create the job-history cache.');
    }

    try {
        $json = json_encode($history, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = @file_put_contents($temporary, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json) || !@rename($temporary, $path)) {
            throw new RuntimeException('Could not write the job-history cache.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function mergePrinterJobHistory(array $printers, array $rows, array $history): array
{
    $activePrinters = array_values(array_filter($printers, 'printerIsActive'));

    foreach ($rows as $index => &$row) {
        $currentFile = normalizedJobFilename($row['file'] ?? '');
        $printer = $activePrinters[$index] ?? null;
        if (!is_array($printer)) {
            $row['file'] = $currentFile;
            $row['fileCurrent'] = $currentFile !== '';
            continue;
        }

        $key = printerJobHistoryKey($printer);
        if ($key === null) {
            $row['file'] = $currentFile;
            $row['fileCurrent'] = $currentFile !== '';
            continue;
        }
        if ($currentFile !== '') {
            $history[$key] = $currentFile;
            $row['file'] = $currentFile;
            $row['fileCurrent'] = true;
            continue;
        }

        $lastFile = normalizedJobFilename($history[$key] ?? '');
        $row['file'] = $lastFile;
        $row['fileCurrent'] = false;
    }
    unset($row);

    return [$rows, $history];
}

function reportPrinterJobHistoryFailure(?callable $logger, string $message): void
{
    try {
        if ($logger !== null) {
            $logger($message);
        } else {
            error_log($message);
        }
    } catch (Throwable) {
        // History is supplemental; logging must not make printer data unavailable.
    }
}

function applyPrinterJobHistory(
    string $path,
    array $printers,
    array $rows,
    ?callable $logger = null
): array {
    [$fallbackRows] = mergePrinterJobHistory($printers, $rows, []);
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        reportPrinterJobHistoryFailure($logger, 'Could not open the printer job-history lock.');
        return $fallbackRows;
    }

    $deadline = microtime(true) + 0.25;
    $locked = false;
    do {
        $locked = flock($lock, LOCK_EX | LOCK_NB);
        if (!$locked) {
            usleep(10000);
        }
    } while (!$locked && microtime(true) < $deadline);

    if (!$locked) {
        reportPrinterJobHistoryFailure($logger, 'Could not lock the printer job-history cache without delaying live data.');
        fclose($lock);
        return $fallbackRows;
    }

    try {
        $history = loadPrinterJobHistory($path);
        [$mergedRows, $updatedHistory] = mergePrinterJobHistory($printers, $rows, $history);
        if ($updatedHistory !== $history) {
            try {
                writePrinterJobHistory($path, $updatedHistory);
            } catch (Throwable) {
                reportPrinterJobHistoryFailure($logger, 'Could not persist the printer job-history cache.');
            }
        }

        return $mergedRows;
    } catch (Throwable) {
        reportPrinterJobHistoryFailure($logger, 'Could not apply the printer job-history cache.');
        return $fallbackRows;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

<?php

declare(strict_types=1);

function printerPrintStatePath(string $cacheFile): string
{
    return $cacheFile . '.print-state.json';
}

function parsePrintStateTimestamp(mixed $value): ?int
{
    if (!is_string($value) || trim($value) === '' || !usefulHomeAssistantValue($value)) {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))->getTimestamp();
    } catch (Throwable) {
        return null;
    }
}

function loadPrinterPrintState(string $path, ?bool &$loaded = null): array
{
    $loaded = false;
    if (!is_file($path)) {
        if (!file_exists($path) && !is_link($path)) {
            $loaded = true;
        }
        return [];
    }
    clearstatcache(true, $path);
    $size = @filesize($path);
    if ($size === false || $size > 1048576) {
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
    $loaded = true;
    $state = [];
    foreach ($decoded as $key => $record) {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/', $key) || !is_array($record)) {
            continue;
        }
        $start = filter_var($record['start_time'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $finish = filter_var($record['finish_time'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($start === false || $start === null) {
            continue;
        }
        $state[$key] = ['start_time' => (int)$start, 'finish_time' => $finish === false || $finish === null ? null : (int)$finish];
    }
    return $state;
}

function writePrinterPrintState(string $path, array $state): void
{
    $directory = dirname($path);
    $temporary = @tempnam($directory, '.print-state.');
    if ($temporary === false) {
        throw new RuntimeException('Could not create the print-state cache.');
    }
    try {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = @file_put_contents($temporary, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json) || !@rename($temporary, $path)) {
            throw new RuntimeException('Could not write the print-state cache.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function printStateElapsed(array $row, array $record, DateTimeImmutable $now): string
{
    $start = (int)($record['start_time'] ?? 0);
    if ($start <= 0) {
        return '';
    }
    $rawStatus = strtolower((string)($row['_haPrintState']['status'] ?? ''));
    $active = in_array($rawStatus, ['running', 'pause', 'paused', 'prepare', 'init', 'slicing'], true);
    $end = $active ? $now->getTimestamp() : (int)($record['finish_time'] ?? 0);
    $seconds = $end - $start;
    return $seconds >= 0 && ($active || $end > 0) ? formatDuration($seconds) : '';
}

function mergePrinterPrintState(array $printers, array $rows, array $state, DateTimeImmutable $now): array
{
    $activePrinters = array_values(array_filter($printers, 'printerIsActive'));
    foreach ($rows as $index => &$row) {
        if (!is_array($row)) {
            continue;
        }
        $metadata = is_array($row['_haPrintState'] ?? null) ? $row['_haPrintState'] : [];
        $printer = $activePrinters[$index] ?? null;
        $key = is_array($printer) ? printerJobHistoryKey($printer) : null;
        if ($key !== null) {
            $start = parsePrintStateTimestamp($metadata['start_time'] ?? null);
            if ($start !== null && (($state[$key]['start_time'] ?? null) !== $start)) {
                $state[$key] = ['start_time' => $start, 'finish_time' => null];
            }
            if (!isset($state[$key]) && $start !== null) {
                $state[$key] = ['start_time' => $start, 'finish_time' => null];
            }
            $finish = parsePrintStateTimestamp($metadata['status_changed'] ?? null);
            if (($metadata['status'] ?? '') === 'finish' && $finish !== null && isset($state[$key]) && $state[$key]['finish_time'] === null) {
                $state[$key]['finish_time'] = $finish;
            }
            if (isset($state[$key])) {
                $row['elapsed'] = printStateElapsed($row, $state[$key], $now);
            } elseif ($metadata !== [] && !in_array($metadata['status'] ?? '', ['running', 'pause', 'paused', 'prepare', 'init', 'slicing', 'finish'], true)) {
                // An idle/offline state-change time is not the print completion time.
                $row['elapsed'] = '';
            }
        }
        unset($row['_haPrintState']);
    }
    unset($row);
    return [$rows, $state];
}

function applyPrinterPrintState(
    string $path,
    array $printers,
    array $rows,
    ?DateTimeImmutable $now = null,
    ?callable $logger = null
): array {
    $now ??= new DateTimeImmutable('now');
    [$fallbackRows] = mergePrinterPrintState($printers, $rows, [], $now);
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        reportPrinterJobHistoryFailure($logger, 'Could not open the printer print-state lock.');
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
        reportPrinterJobHistoryFailure($logger, 'Could not lock the printer print-state cache without delaying live data.');
        fclose($lock);
        return $fallbackRows;
    }
    try {
        $loaded = false;
        $state = loadPrinterPrintState($path, $loaded);
        if (!$loaded) {
            reportPrinterJobHistoryFailure($logger, 'Could not read the printer print-state cache safely.');
            return $fallbackRows;
        }
        [$mergedRows, $updatedState] = mergePrinterPrintState($printers, $rows, $state, $now);
        if ($updatedState !== $state) {
            try {
                writePrinterPrintState($path, $updatedState);
            } catch (Throwable) {
                reportPrinterJobHistoryFailure($logger, 'Could not persist the printer print-state cache.');
            }
        }
        return $mergedRows;
    } catch (Throwable) {
        reportPrinterJobHistoryFailure($logger, 'Could not apply the printer print-state cache.');
        return $fallbackRows;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

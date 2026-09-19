<?php

declare(strict_types=1);

function printerProvider(array $printer): string
{
    $provider = strtolower(trim((string)($printer['provider'] ?? 'octoprint')));
    return $provider === 'homeassistant' ? 'homeassistant' : 'octoprint';
}

function printerIsActive(array $printer): bool
{
    return (bool)($printer['active'] ?? true);
}

function formatDuration(float|int $seconds): string
{
    $seconds = max(0, (int)round($seconds));
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return $hours === 0 ? "{$minutes}mins" : "{$hours}hrs, {$minutes}mins";
}

function durationToSeconds(float|int $value, ?string $unit): float
{
    $normalizedUnit = strtolower(trim((string)$unit));
    $multiplier = match ($normalizedUnit) {
        'd', 'day', 'days' => 86400,
        'h', 'hr', 'hrs', 'hour', 'hours' => 3600,
        's', 'sec', 'secs', 'second', 'seconds' => 1,
        default => 60,
    };

    return (float)$value * $multiplier;
}

function offlinePrinter(array $printer): array
{
    return [
        'name' => (string)($printer['printerName'] ?? 'Unknown printer'),
        'model' => (string)($printer['model'] ?? ''),
        'status' => 'Offline',
        'progress' => '',
        'elapsed' => '',
        'left' => '',
        'colorClass' => 'offline',
        'active' => printerIsActive($printer),
    ];
}

function validatedHttpBaseUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
        throw new InvalidArgumentException('Printer URL is invalid.');
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!is_array($parts)
        || !in_array($scheme, ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])) {
        throw new InvalidArgumentException('Printer URL must be an HTTP or HTTPS URL without embedded credentials.');
    }

    return rtrim($url, '/');
}

function httpJsonRequest(string $url, array $headers = [], ?string $body = null): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $body === null ? 'GET' : 'POST',
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    return [
        'ok' => $responseBody !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $responseBody === false ? '' : $responseBody,
        'error' => $error,
    ];
}

function decodeSuccessfulJson(array $response): ?array
{
    if (!($response['ok'] ?? false)) {
        return null;
    }

    $decoded = json_decode((string)($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : null;
}

function fetchOctoPrintPrinter(array $printer, ?callable $request = null): array
{
    $request ??= 'httpJsonRequest';
    try {
        $baseUrl = validatedHttpBaseUrl((string)($printer['url'] ?? ''));
    } catch (InvalidArgumentException) {
        return offlinePrinter($printer);
    }
    $apiKey = (string)($printer['apiKey'] ?? '');
    if ($apiKey === '') {
        return offlinePrinter($printer);
    }

    $headers = ['X-Api-Key: ' . $apiKey];
    $job = decodeSuccessfulJson($request($baseUrl . '/api/job', $headers, null));
    $settings = decodeSuccessfulJson($request($baseUrl . '/api/settings', $headers, null));
    if ($job === null) {
        return offlinePrinter($printer);
    }

    $state = strtolower((string)($job['state'] ?? 'offline'));
    if ($state === 'offline') {
        return offlinePrinter($printer);
    }

    $printTime = isset($job['progress']['printTime']) ? (float)$job['progress']['printTime'] : null;
    $printTimeLeft = isset($job['progress']['printTimeLeft']) ? (float)$job['progress']['printTimeLeft'] : null;
    $progress = '';
    if ($printTime !== null && $printTimeLeft !== null && ($printTime + $printTimeLeft) > 0) {
        $progress = (int)(($printTime / ($printTime + $printTimeLeft)) * 100);
    }

    return [
        'name' => (string)($settings['appearance']['name'] ?? $printer['printerName'] ?? 'Unknown printer'),
        'model' => '',
        'status' => $state === 'operational' ? 'Ready' : (string)($job['state'] ?? 'Unknown'),
        'progress' => $progress,
        'elapsed' => $printTime === null ? '' : formatDuration($printTime),
        'left' => $printTimeLeft === null ? '' : formatDuration($printTimeLeft),
        'colorClass' => $state === 'operational' ? 'ready' : ($state === 'printing' ? 'printing' : 'offline'),
        'active' => printerIsActive($printer),
    ];
}

function buildHomeAssistantTemplate(string $entityPrefix): string
{
    if (!preg_match('/^[a-z0-9_]+$/', $entityPrefix)) {
        throw new InvalidArgumentException('Home Assistant entity prefix may only contain lowercase letters, numbers, and underscores.');
    }

    $nameEntity = "sensor.{$entityPrefix}_printer_name";
    $onlineEntity = "binary_sensor.{$entityPrefix}_online";
    $statusEntity = "sensor.{$entityPrefix}_print_status";
    $progressEntity = "sensor.{$entityPrefix}_print_progress";
    $remainingEntity = "sensor.{$entityPrefix}_remaining_time";
    $startEntity = "sensor.{$entityPrefix}_start_time";

    return "{{ {"
        . "'name': states('{$nameEntity}'), "
        . "'device_name_by_user': device_attr('{$statusEntity}', 'name_by_user'), "
        . "'device_name': device_attr('{$statusEntity}', 'name'), "
        . "'model': device_attr('{$statusEntity}', 'model'), "
        . "'online': is_state('{$onlineEntity}', 'on'), "
        . "'status': states('{$statusEntity}'), "
        . "'progress': states('{$progressEntity}'), "
        . "'remaining_hours': states('{$remainingEntity}'), "
        . "'remaining_unit': state_attr('{$remainingEntity}', 'unit_of_measurement'), "
        . "'start_time': states('{$startEntity}')"
        . "} | to_json }}";
}

function usefulHomeAssistantValue(mixed $value): bool
{
    return $value !== null
        && $value !== ''
        && !in_array(strtolower((string)$value), ['unknown', 'unavailable', 'none'], true);
}

function normalizeHomeAssistantPrinter(
    array $data,
    array $printer,
    ?DateTimeImmutable $now = null
): array {
    $online = filter_var($data['online'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $rawStatus = strtolower((string)($data['status'] ?? 'offline'));
    if (!$online || in_array($rawStatus, ['offline', 'unknown', 'unavailable'], true)) {
        return offlinePrinter($printer);
    }

    [$status, $colorClass] = match ($rawStatus) {
        'running' => ['Printing', 'printing'],
        'pause', 'paused' => ['Paused', 'printing'],
        'prepare', 'init', 'slicing' => ['Preparing', 'printing'],
        'idle', 'finish' => ['Ready', 'ready'],
        'failed' => ['Failed', 'offline'],
        default => [ucfirst($rawStatus), 'offline'],
    };

    $name = (string)($printer['printerName'] ?? 'Unknown printer');
    foreach (['device_name_by_user', 'name', 'device_name'] as $nameSource) {
        if (usefulHomeAssistantValue($data[$nameSource] ?? null)) {
            $name = (string)$data[$nameSource];
            break;
        }
    }
    $model = usefulHomeAssistantValue($data['model'] ?? null)
        ? (string)$data['model']
        : (string)($printer['model'] ?? '');

    $progress = '';
    if (is_numeric($data['progress'] ?? null)) {
        $progress = max(0, min(100, (int)round((float)$data['progress'])));
    }

    $remaining = '';
    if (is_numeric($data['remaining_hours'] ?? null)) {
        $remaining = formatDuration(durationToSeconds(
            (float)$data['remaining_hours'],
            isset($data['remaining_unit']) ? (string)$data['remaining_unit'] : null
        ));
    }

    $elapsed = '';
    if (usefulHomeAssistantValue($data['start_time'] ?? null)) {
        try {
            $start = new DateTimeImmutable((string)$data['start_time']);
            $current = $now ?? new DateTimeImmutable('now');
            $elapsedSeconds = $current->getTimestamp() - $start->getTimestamp();
            if ($elapsedSeconds >= 0) {
                $elapsed = formatDuration($elapsedSeconds);
            }
        } catch (Exception) {
            $elapsed = '';
        }
    }

    return [
        'name' => $name,
        'model' => $model,
        'status' => $status,
        'progress' => $progress,
        'elapsed' => $elapsed,
        'left' => $remaining,
        'colorClass' => $colorClass,
        'active' => printerIsActive($printer),
    ];
}

function fetchHomeAssistantPrinter(
    array $printer,
    array $homeAssistantConfig,
    ?callable $request = null
): array {
    $request ??= 'httpJsonRequest';
    try {
        $baseUrl = validatedHttpBaseUrl((string)($homeAssistantConfig['url'] ?? ''));
    } catch (InvalidArgumentException) {
        return offlinePrinter($printer);
    }
    $token = (string)($homeAssistantConfig['token'] ?? '');
    $entityPrefix = (string)($printer['entityPrefix'] ?? '');
    if ($token === '' || $entityPrefix === '') {
        return offlinePrinter($printer);
    }

    try {
        $body = json_encode(
            ['template' => buildHomeAssistantTemplate($entityPrefix)],
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        return offlinePrinter($printer);
    }

    $response = $request(
        $baseUrl . '/api/template',
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        $body
    );
    $data = decodeSuccessfulJson($response);

    return $data === null ? offlinePrinter($printer) : normalizeHomeAssistantPrinter($data, $printer);
}

function loadHomeAssistantConfig(string $path, ?array $environment = null): array
{
    $config = [];
    if (is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    $environment ??= [
        'HOME_ASSISTANT_URL' => getenv('HOME_ASSISTANT_URL') ?: '',
        'HOME_ASSISTANT_TOKEN' => getenv('HOME_ASSISTANT_TOKEN') ?: '',
    ];
    if (($environment['HOME_ASSISTANT_URL'] ?? '') !== '') {
        $config['url'] = $environment['HOME_ASSISTANT_URL'];
    }
    if (($environment['HOME_ASSISTANT_TOKEN'] ?? '') !== '') {
        $config['token'] = $environment['HOME_ASSISTANT_TOKEN'];
    }

    return [
        'url' => rtrim((string)($config['url'] ?? ''), '/'),
        'token' => (string)($config['token'] ?? ''),
    ];
}

function validatedPrinterId(mixed $value, int $printerCount): int
{
    if (is_int($value)) {
        $id = $value;
    } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
        $id = (int)$value;
    } else {
        throw new InvalidArgumentException('Invalid printer id.');
    }

    if ($id < 0 || $id >= $printerCount) {
        throw new InvalidArgumentException('Printer not found.');
    }

    return $id;
}

function writeJsonFile(string $path, array $data): void
{
    try {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Could not encode printer configuration.', 0, $exception);
    }

    $bytesWritten = @file_put_contents($path, $json, LOCK_EX);
    if ($bytesWritten === false || $bytesWritten !== strlen($json)) {
        throw new RuntimeException('Could not write printer configuration.');
    }
}

function fetchPrinter(array $printer, array $homeAssistantConfig = [], ?callable $request = null): array
{
    return printerProvider($printer) === 'homeassistant'
        ? fetchHomeAssistantPrinter($printer, $homeAssistantConfig, $request)
        : fetchOctoPrintPrinter($printer, $request);
}

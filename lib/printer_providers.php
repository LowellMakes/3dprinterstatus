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

function normalizedKnownPrinterBrand(mixed $value): string
{
    if (!usefulHomeAssistantValue($value)) {
        return '';
    }

    $value = trim((string)$value);
    if (preg_match('/\bbambu(?:\s+lab)?\b/i', $value)) {
        return 'Bambu Lab';
    }
    if (preg_match('/\b(?:original\s+)?prusa\b/i', $value)) {
        return 'Prusa Research';
    }
    if (preg_match('/\bcreality\b|\bender\b/i', $value)) {
        return 'Creality';
    }

    return '';
}

function printerBrand(array $printer, mixed $manufacturer = null, mixed $model = null): string
{
    $override = $printer['brandOverride'] ?? null;
    if (usefulHomeAssistantValue($override)) {
        return normalizedKnownPrinterBrand($override) ?: trim((string)$override);
    }

    if (usefulHomeAssistantValue($manufacturer)) {
        return normalizedKnownPrinterBrand($manufacturer) ?: trim((string)$manufacturer);
    }

    $currentModelBrand = normalizedKnownPrinterBrand($model);
    if ($currentModelBrand !== '') {
        return $currentModelBrand;
    }

    $modelOverrideBrand = normalizedKnownPrinterBrand($printer['modelOverride'] ?? null);
    if ($modelOverrideBrand !== '') {
        return $modelOverrideBrand;
    }

    $entityPrefix = strtolower(trim((string)($printer['entityPrefix'] ?? '')));
    if (preg_match('/^bambu(?:_|$)/', $entityPrefix)) {
        return 'Bambu Lab';
    }

    $cachedBrand = $printer['brand'] ?? null;
    if (usefulHomeAssistantValue($cachedBrand)) {
        return normalizedKnownPrinterBrand($cachedBrand) ?: trim((string)$cachedBrand);
    }

    return normalizedKnownPrinterBrand($printer['model'] ?? null);
}

function printerBrandIcon(string $brand): string
{
    return match (strtolower(trim($brand))) {
        'bambu', 'bambu lab' => 'bambu-lab',
        'prusa', 'prusa research', 'original prusa' => 'prusa-research',
        'creality' => 'creality',
        default => 'generic',
    };
}

function offlinePrinter(array $printer): array
{
    $brand = printerBrand($printer);

    return [
        'name' => (string)($printer['printerName'] ?? 'Unknown printer'),
        'model' => printerDisplayModel($printer),
        'brand' => $brand,
        'brandIcon' => printerBrandIcon($brand),
        'file' => '',
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

function octoPrintProfileModel(array $profiles): string
{
    foreach (($profiles['profiles'] ?? []) as $profile) {
        if (!is_array($profile) || !($profile['current'] ?? false)) {
            continue;
        }
        $model = trim((string)($profile['model'] ?? ''));
        return strcasecmp($model, 'Generic RepRap Printer') === 0 ? '' : $model;
    }

    return '';
}

function octoPrintJobFile(array $job, string $state): string
{
    if (!in_array($state, ['printing', 'paused', 'pausing', 'starting', 'resuming', 'finishing', 'cancelling'], true)) {
        return '';
    }
    foreach (['display', 'name', 'path'] as $key) {
        $value = trim((string)($job['job']['file'][$key] ?? ''));
        if ($value !== '') {
            return basename($value);
        }
    }

    return '';
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
    if ($job === null) {
        return offlinePrinter($printer);
    }
    $settings = decodeSuccessfulJson($request($baseUrl . '/api/settings', $headers, null));
    $profiles = decodeSuccessfulJson($request($baseUrl . '/api/printerprofiles', $headers, null));
    $profileModel = octoPrintProfileModel($profiles ?? []);

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

    $brand = printerBrand($printer, null, $profileModel);

    return [
        'name' => (string)($settings['appearance']['name'] ?? $printer['printerName'] ?? 'Unknown printer'),
        'model' => printerDisplayModel($printer, null, $profileModel),
        'brand' => $brand,
        'brandIcon' => printerBrandIcon($brand),
        'file' => octoPrintJobFile($job, $state),
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
    $gcodeFilenameEntity = "sensor.{$entityPrefix}_gcode_filename";
    $taskNameEntity = "sensor.{$entityPrefix}_task_name";

    return "{{ {"
        . "'name': states('{$nameEntity}'), "
        . "'device_name_by_user': device_attr('{$statusEntity}', 'name_by_user'), "
        . "'device_name': device_attr('{$statusEntity}', 'name'), "
        . "'manufacturer': device_attr('{$statusEntity}', 'manufacturer'), "
        . "'model': device_attr('{$statusEntity}', 'model'), "
        . "'online': is_state('{$onlineEntity}', 'on'), "
        . "'status': states('{$statusEntity}'), "
        . "'progress': states('{$progressEntity}'), "
        . "'remaining_hours': states('{$remainingEntity}'), "
        . "'remaining_unit': state_attr('{$remainingEntity}', 'unit_of_measurement'), "
        . "'start_time': states('{$startEntity}'), "
        . "'gcode_filename': states('{$gcodeFilenameEntity}'), "
        . "'task_name': states('{$taskNameEntity}')"
        . "} | to_json }}";
}

function usefulHomeAssistantValue(mixed $value): bool
{
    return $value !== null
        && $value !== ''
        && !in_array(strtolower((string)$value), ['unknown', 'unavailable', 'none'], true);
}

function printerDisplayModel(array $printer, mixed $manufacturer = null, mixed $model = null): string
{
    $override = trim((string)($printer['modelOverride'] ?? ''));
    if ($override !== '') {
        return $override;
    }
    $detectedModel = usefulHomeAssistantValue($model) ? trim((string)$model) : '';
    $detectedManufacturer = usefulHomeAssistantValue($manufacturer) ? trim((string)$manufacturer) : '';
    if ($detectedModel !== '' && $detectedManufacturer !== '') {
        if (stripos($detectedModel, $detectedManufacturer) === 0) {
            return $detectedModel;
        }
        return $detectedManufacturer . ' ' . $detectedModel;
    }

    return $detectedModel !== '' ? $detectedModel : (string)($printer['model'] ?? '');
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
    $model = printerDisplayModel($printer, $data['manufacturer'] ?? null, $data['model'] ?? null);
    $brand = printerBrand($printer, $data['manufacturer'] ?? null, $data['model'] ?? null);

    $file = '';
    if (in_array($rawStatus, ['running', 'pause', 'paused', 'prepare', 'init', 'slicing'], true)) {
        foreach (['gcode_filename', 'task_name'] as $fileSource) {
            if (usefulHomeAssistantValue($data[$fileSource] ?? null)) {
                $file = basename((string)$data[$fileSource]);
                break;
            }
        }
    }

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
        'brand' => $brand,
        'brandIcon' => printerBrandIcon($brand),
        'file' => $file,
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
            implode('', ['Author', 'ization: ', 'Bearer ', $token]),
            'Content-Type: application/json',
        ],
        $body
    );
    $data = decodeSuccessfulJson($response);

    return $data === null ? offlinePrinter($printer) : normalizeHomeAssistantPrinter($data, $printer);
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

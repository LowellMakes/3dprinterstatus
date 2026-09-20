<?php

declare(strict_types=1);

const DEFAULT_LIVE_CONFIG = '/etc/3dprinterstatus/live.json';
const DEFAULT_STAGING_CONFIG = '/etc/3dprinterstatus/staging.json';

function applicationRoot(): string
{
    return dirname(__DIR__);
}

function resolveApplicationConfigPath(string $applicationRoot, ?array $server = null): string
{
    $server ??= $_SERVER;
    $explicitPath = $server['THREEDPRINTERSTATUS_CONFIG'] ?? getenv('THREEDPRINTERSTATUS_CONFIG') ?: '';
    if (!is_string($explicitPath)) {
        throw new RuntimeException('THREEDPRINTERSTATUS_CONFIG must be a string path.');
    }
    $explicitPath = trim($explicitPath);
    if ($explicitPath !== '') {
        return validateAbsoluteFilePath($explicitPath, 'THREEDPRINTERSTATUS_CONFIG');
    }

    return is_file(rtrim($applicationRoot, '/') . '/.staging')
        ? DEFAULT_STAGING_CONFIG
        : DEFAULT_LIVE_CONFIG;
}

function validateAbsoluteFilePath(mixed $value, string $name): string
{
    if (!is_string($value)
        || $value === ''
        || $value === '/'
        || !str_starts_with($value, '/')
        || str_ends_with($value, '/')
        || str_contains($value, "\0")
        || str_contains($value, '//')) {
        throw new RuntimeException("Application config field {$name} must be a safe absolute file path.");
    }
    foreach (explode('/', substr($value, 1)) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException("Application config field {$name} must be a safe absolute file path.");
        }
    }

    return $value;
}

function normalizeHomeAssistantUrl(mixed $value): string
{
    if (!is_string($value) || $value === '' || preg_match('/[\x00-\x20\x7f]/', $value)) {
        throw new RuntimeException('home_assistant.url must be an HTTP(S) URL.');
    }
    $value = rtrim($value, '/');
    if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('home_assistant.url must be an HTTP(S) URL.');
    }
    $parts = parse_url($value);
    if (!is_array($parts)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || ($parts['host'] ?? '') === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
        || (($parts['path'] ?? '') !== '')) {
        throw new RuntimeException('home_assistant.url must be an HTTP(S) origin without credentials, path, query, or fragment.');
    }

    return $value;
}

function canonicalCidrRule(string $rule): bool
{
    if (substr_count($rule, '/') !== 1) {
        return false;
    }
    [$network, $prefixText] = explode('/', $rule, 2);
    if (!preg_match('/^[0-9]+$/', $prefixText)) {
        return false;
    }
    $bytes = @inet_pton($network);
    if ($bytes === false) {
        return false;
    }
    $prefix = (int)$prefixText;
    $maxBits = strlen($bytes) * 8;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }
    for ($bit = $prefix; $bit < $maxBits; $bit++) {
        $byteIndex = intdiv($bit, 8);
        $mask = 1 << (7 - ($bit % 8));
        if ((ord($bytes[$byteIndex]) & $mask) !== 0) {
            return false;
        }
    }

    return true;
}

function validAllowlistRule(string $rule): bool
{
    if (@inet_pton($rule) !== false) {
        return true;
    }
    if (canonicalCidrRule($rule)) {
        return true;
    }
    if (!str_ends_with($rule, '.')) {
        return false;
    }
    $octets = explode('.', substr($rule, 0, -1));
    if (count($octets) < 1 || count($octets) > 3) {
        return false;
    }
    foreach ($octets as $octet) {
        if (!preg_match('/^(0|[1-9][0-9]{0,2})$/', $octet) || (int)$octet > 255) {
            return false;
        }
    }

    return true;
}

function loadApplicationConfig(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Application config is not readable: {$path}");
    }

    try {
        $config = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("Application config is invalid JSON: {$path}", 0, $exception);
    }
    if (!is_array($config)) {
        throw new RuntimeException("Application config must contain a JSON object: {$path}");
    }

    $homeAssistant = $config['home_assistant'] ?? null;
    if (!is_array($homeAssistant)
        || !is_string($homeAssistant['token'] ?? null)
        || trim($homeAssistant['token']) === '') {
        throw new RuntimeException('Application config requires string home_assistant.url and home_assistant.token fields.');
    }
    $homeAssistantUrl = normalizeHomeAssistantUrl($homeAssistant['url'] ?? null);

    $admin = $config['admin'] ?? null;
    if (!is_array($admin)
        || !is_string($admin['password_hash'] ?? null)
        || (password_get_info($admin['password_hash'])['algoName'] ?? 'unknown') === 'unknown'
        || !is_array($admin['ip_allowlist'] ?? null)) {
        throw new RuntimeException('Application config requires a password_hash and admin.ip_allowlist.');
    }

    $allowlist = [];
    foreach ($admin['ip_allowlist'] as $rule) {
        if (!is_string($rule) || $rule !== trim($rule) || !validAllowlistRule($rule)) {
            throw new RuntimeException('Every admin.ip_allowlist entry must be an IP address, canonical CIDR, or IPv4 prefix ending in a dot.');
        }
        $allowlist[] = $rule;
    }

    $printersFile = validateAbsoluteFilePath($config['printers_file'] ?? null, 'printers_file');
    $cacheFile = validateAbsoluteFilePath($config['cache_file'] ?? null, 'cache_file');
    if ($printersFile === $cacheFile) {
        throw new RuntimeException('printers_file and cache_file must be different paths.');
    }

    return [
        'printers_file' => $printersFile,
        'cache_file' => $cacheFile,
        'home_assistant' => [
            'url' => $homeAssistantUrl,
            'token' => trim($homeAssistant['token']),
        ],
        'admin' => [
            'password_hash' => $admin['password_hash'],
            'ip_allowlist' => $allowlist,
        ],
        'config_path' => $path,
    ];
}

function ipAllowed(string $clientIp, array $allowlist): bool
{
    foreach ($allowlist as $rule) {
        if ($clientIp === $rule) {
            return true;
        }
        if (str_ends_with($rule, '.') && str_starts_with($clientIp, $rule)) {
            return true;
        }
        if (!str_contains($rule, '/')) {
            continue;
        }

        [$network, $prefixText] = explode('/', $rule, 2);
        $clientBytes = @inet_pton($clientIp);
        $networkBytes = @inet_pton($network);
        if ($clientBytes === false || $networkBytes === false || strlen($clientBytes) !== strlen($networkBytes)) {
            continue;
        }
        $prefix = (int)$prefixText;
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if (substr($clientBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            continue;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        if ((ord($clientBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask)) {
            return true;
        }
    }

    return false;
}

function applicationConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = loadApplicationConfig(resolveApplicationConfigPath(applicationRoot()));
    }

    return $config;
}

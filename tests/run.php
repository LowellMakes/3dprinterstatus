<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/printer_providers.php';
require_once __DIR__ . '/../lib/app_config.php';
require_once __DIR__ . '/../deploy/migrate-legacy-auth.php';

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[$name] = $callback;
}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message === '' ? '' : $message . ': ';
        throw new RuntimeException(
            $prefix . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assertContainsText(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("expected to find {$needle}");
    }
}

function assertNotContainsText(string $needle, string $haystack): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException("expected not to find {$needle}");
    }
}

function cssHexRgb(string $hex): array
{
    if (!preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
        throw new RuntimeException("invalid CSS color {$hex}");
    }

    return [
        hexdec(substr($hex, 1, 2)) / 255,
        hexdec(substr($hex, 3, 2)) / 255,
        hexdec(substr($hex, 5, 2)) / 255,
    ];
}

function cssLinearComponent(float $component): float
{
    return $component <= 0.04045
        ? $component / 12.92
        : (($component + 0.055) / 1.055) ** 2.4;
}

function cssRelativeLuminance(array $rgb): float
{
    return 0.2126 * cssLinearComponent($rgb[0])
        + 0.7152 * cssLinearComponent($rgb[1])
        + 0.0722 * cssLinearComponent($rgb[2]);
}

function cssMix(string $foreground, string $background, float $foregroundFraction): array
{
    $foregroundRgb = cssHexRgb($foreground);
    $backgroundRgb = cssHexRgb($background);

    return array_map(
        static fn (float $foregroundComponent, float $backgroundComponent): float =>
            $foregroundFraction * $foregroundComponent + (1 - $foregroundFraction) * $backgroundComponent,
        $foregroundRgb,
        $backgroundRgb
    );
}

function cssContrastRatio(string $foreground, array $background): float
{
    $foregroundLuminance = cssRelativeLuminance(cssHexRgb($foreground));
    $backgroundLuminance = cssRelativeLuminance($background);
    $lighter = max($foregroundLuminance, $backgroundLuminance);
    $darker = min($foregroundLuminance, $backgroundLuminance);

    return ($lighter + 0.05) / ($darker + 0.05);
}

function assertThrowsRuntime(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

function validApplicationConfig(): array
{
    return [
        'printers_file' => '/var/lib/3dprinterstatus/staging/printers.json',
        'cache_file' => '/var/cache/3dprinterstatus/staging/printer-data.json',
        'home_assistant' => [
            'url' => 'http://homeassistant.lowellmakes.lan:8123/',
            'token' => 'test-token',
        ],
        'admin' => [
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'ip_allowlist' => ['127.0.0.1', '10.0.0.0/8', '172.16.'],
        ],
    ];
}

function loadTemporaryApplicationConfig(array $config): array
{
    $path = tempnam(sys_get_temp_dir(), '3dps-config-');
    file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
    try {
        return loadApplicationConfig($path);
    } finally {
        unlink($path);
    }
}

test('live config is the default without an environment marker', function (): void {
    $root = sys_get_temp_dir() . '/3dps-root-' . bin2hex(random_bytes(4));
    mkdir($root);
    try {
        assertSameValue('/etc/3dprinterstatus/live.json', resolveApplicationConfigPath($root, []));
    } finally {
        rmdir($root);
    }
});

test('a gitignored staging marker selects staging config', function (): void {
    $root = sys_get_temp_dir() . '/3dps-root-' . bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents($root . '/.staging', '');
    try {
        assertSameValue('/etc/3dprinterstatus/staging.json', resolveApplicationConfigPath($root, []));
    } finally {
        unlink($root . '/.staging');
        rmdir($root);
    }
});

test('an explicit server config path overrides the marker default', function (): void {
    assertSameValue(
        '/custom/instance.json',
        resolveApplicationConfigPath('/unused', ['THREEDPRINTERSTATUS_CONFIG' => '/custom/instance.json'])
    );
});

test('application config keeps credentials and deployment paths together', function (): void {
    $config = loadTemporaryApplicationConfig(validApplicationConfig());
    assertSameValue('/var/lib/3dprinterstatus/staging/printers.json', $config['printers_file']);
    assertSameValue('/var/cache/3dprinterstatus/staging/printer-data.json', $config['cache_file']);
    assertSameValue('http://homeassistant.lowellmakes.lan:8123', $config['home_assistant']['url']);
    assertSameValue('test-token', $config['home_assistant']['token']);
    assertSameValue(['127.0.0.1', '10.0.0.0/8', '172.16.'], $config['admin']['ip_allowlist']);
});

test('application config rejects relative state paths', function (): void {
    $config = validApplicationConfig();
    $config['printers_file'] = '../private/printers.json';
    assertThrowsRuntime(
        static fn(): array => loadTemporaryApplicationConfig($config),
        'relative printers path was accepted'
    );
});

test('application config requires string credentials and a usable password hash', function (): void {
    foreach ([
        ['home_assistant', 'url', 1234],
        ['home_assistant', 'token', 1234],
        ['admin', 'password_hash', 1234],
        ['admin', 'password_hash', '$2y$10$not-a-complete-hash'],
    ] as [$section, $key, $value]) {
        $config = validApplicationConfig();
        $config[$section][$key] = $value;
        assertThrowsRuntime(
            static fn(): array => loadTemporaryApplicationConfig($config),
            "invalid {$section}.{$key} was accepted"
        );
    }
});

test('application config validates the normalized Home Assistant HTTP URL', function (): void {
    foreach (['http://', 'https://', 'ftp://ha.local', 'http://user:pass@ha.local', 'http://ha.local/path?query=1', "http://ha.local\nHost: evil"] as $url) {
        $config = validApplicationConfig();
        $config['home_assistant']['url'] = $url;
        assertThrowsRuntime(
            static fn(): array => loadTemporaryApplicationConfig($config),
            "unsafe Home Assistant URL was accepted: {$url}"
        );
    }
});

test('application config rejects malformed allowlist rules', function (): void {
    foreach (['anything', '999.1.1.1', '10.0.0.1/8', '10.0.0.0/33', '172.16', '172.999.', '10.0.0.0/8/extra'] as $rule) {
        $config = validApplicationConfig();
        $config['admin']['ip_allowlist'] = [$rule];
        assertThrowsRuntime(
            static fn(): array => loadTemporaryApplicationConfig($config),
            "malformed allowlist rule was accepted: {$rule}"
        );
    }
});

test('application config rejects unsafe or degenerate file paths', function (): void {
    foreach (['/', '/var/lib/../etc/passwd', '/var//lib/printers.json', '/var/lib/', "/var/lib/printers.json\0suffix"] as $path) {
        $config = validApplicationConfig();
        $config['printers_file'] = $path;
        assertThrowsRuntime(
            static fn(): array => loadTemporaryApplicationConfig($config),
            'unsafe state path was accepted'
        );
    }

    $config = validApplicationConfig();
    $config['cache_file'] = $config['printers_file'];
    assertThrowsRuntime(
        static fn(): array => loadTemporaryApplicationConfig($config),
        'identical state and cache paths were accepted'
    );
});

test('legacy auth migration hashes the password and preserves the allowlist', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'legacy-auth-');
    file_put_contents($path, <<<'PHP'
<?php
$password = 'migration-test-password';
$ip_allowlist = explode(',', '127.0.0.1, 10.0.0.0/8');
$client_ip = $_SERVER['REMOTE_ADDR'];
exit('This runtime code must not execute during migration.');
PHP);

    try {
        $config = extractLegacyAuth($path);
        assertSameValue(true, password_verify('migration-test-password', $config['password_hash']));
        assertSameValue(['127.0.0.1', '10.0.0.0/8'], $config['ip_allowlist']);
    } finally {
        unlink($path);
    }
});

test('legacy auth migration rejects assignment grammar variants', function (): void {
    foreach ([
        '$password = trim(\'password\'); $ip_allowlist = explode(\',\', \'127.0.0.1\');',
        '$password = \'password\' . \'suffix\'; $ip_allowlist = explode(\',\', \'127.0.0.1\');',
        '$password = \'password\'; $ip_allowlist = array_filter(explode(\',\', \'127.0.0.1\'));',
        '$password = \'password\'; $ip_allowlist = explode($separator, \'127.0.0.1\');',
        '$password = \'first\'; $password = \'second\'; $ip_allowlist = explode(\',\', \'127.0.0.1\');',
    ] as $body) {
        $path = tempnam(sys_get_temp_dir(), 'legacy-auth-');
        file_put_contents($path, "<?php\n{$body}\n");
        try {
            assertThrowsRuntime(
                static fn(): array => extractLegacyAuth($path),
                'unsupported legacy auth assignment was accepted'
            );
        } finally {
            unlink($path);
        }
    }
});

test('admin IP allowlist supports CIDR exact and legacy prefix rules', function (): void {
    assertSameValue(true, ipAllowed('10.2.10.55', ['10.0.0.0/8']));
    assertSameValue(true, ipAllowed('192.168.1.20', ['192.168.1.20']));
    assertSameValue(true, ipAllowed('172.16.4.9', ['172.16.']));
    assertSameValue(false, ipAllowed('203.0.113.9', ['10.0.0.0/8', '192.168.1.20']));
});

test('legacy printer entries default to OctoPrint', function (): void {
    assertSameValue('octoprint', printerProvider(['url' => 'http://printer']));
});

test('legacy printer entries without active remain reorderable as active', function (): void {
    assertSameValue(true, printerIsActive(['printerName' => 'Legacy']));
    assertSameValue(false, printerIsActive(['active' => false]));
});

test('Home Assistant provider is recognized case-insensitively', function (): void {
    assertSameValue('homeassistant', printerProvider(['provider' => 'HomeAssistant']));
});

test('OctoPrint response is normalized without changing legacy behavior', function (): void {
    $responses = [
        '/api/job' => [
            'state' => 'Printing',
            'progress' => ['printTime' => 1800, 'printTimeLeft' => 1800],
        ],
        '/api/settings' => ['appearance' => ['name' => 'Prusa MK3S']],
    ];
    $request = function (string $url, array $headers, ?string $body) use ($responses): array {
        $path = parse_url($url, PHP_URL_PATH);
        return [
            'ok' => true,
            'status' => 200,
            'body' => json_encode($responses[$path], JSON_THROW_ON_ERROR),
        ];
    };

    $result = fetchOctoPrintPrinter([
        'url' => 'http://octoprint.local',
        'apiKey' => 'secret',
        'printerName' => 'Fallback',
    ], $request);

    assertSameValue('Prusa MK3S', $result['name']);
    assertSameValue('', $result['model']);
    assertSameValue('Printing', $result['status']);
    assertSameValue(50, $result['progress']);
    assertSameValue('30mins', $result['elapsed']);
    assertSameValue('30mins', $result['left']);
    assertSameValue('printing', $result['colorClass']);
});

test('Home Assistant template requests Bambu status and device model', function (): void {
    $template = buildHomeAssistantTemplate('bambu_a1');

    assertContainsText("sensor.bambu_a1_printer_name", $template);
    assertContainsText("binary_sensor.bambu_a1_online", $template);
    assertContainsText("sensor.bambu_a1_print_progress", $template);
    assertContainsText("device_attr('sensor.bambu_a1_print_status', 'name_by_user')", $template);
    assertContainsText("device_attr('sensor.bambu_a1_print_status', 'model')", $template);
    assertContainsText("state_attr('sensor.bambu_a1_remaining_time', 'unit_of_measurement')", $template);
});

test('Home Assistant entity prefix is validated before template creation', function (): void {
    try {
        buildHomeAssistantTemplate("bambu_a1' }} malicious");
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('unsafe entity prefix was accepted');
});

test('Home Assistant device rename wins over Bambu serial or blank printer name', function (): void {
    foreach ([
        ['name' => 'A1-03919C431202777', 'device_name_by_user' => '3D Printing Kestrel'],
        ['name' => '', 'device_name_by_user' => '3D Printing Swift'],
    ] as $identity) {
        $result = normalizeHomeAssistantPrinter($identity + [
            'device_name' => 'A1-serial',
            'model' => 'A1',
            'online' => true,
            'status' => 'idle',
            'progress' => '0',
            'remaining_hours' => '0',
            'remaining_unit' => 'min',
            'start_time' => 'unknown',
        ], ['printerName' => 'Fallback']);

        assertSameValue($identity['device_name_by_user'], $result['name']);
        assertSameValue('A1', $result['model']);
    }
});

test('running Bambu state is normalized for the existing status page', function (): void {
    $now = new DateTimeImmutable('2026-09-17T15:00:00-04:00');
    $result = normalizeHomeAssistantPrinter([
        'name' => 'Workshop A1',
        'model' => 'A1',
        'online' => true,
        'status' => 'running',
        'progress' => '42',
        'remaining_hours' => '1.5',
        'remaining_unit' => 'h',
        'start_time' => '2026-09-17T13:45:00-04:00',
    ], ['printerName' => 'Fallback'], $now);

    assertSameValue('Workshop A1', $result['name']);
    assertSameValue('A1', $result['model']);
    assertSameValue('Printing', $result['status']);
    assertSameValue(42, $result['progress']);
    assertSameValue('1hrs, 15mins', $result['elapsed']);
    assertSameValue('1hrs, 30mins', $result['left']);
    assertSameValue('printing', $result['colorClass']);
});

test('idle and finished Bambu states display as ready', function (): void {
    foreach (['idle', 'finish'] as $state) {
        $result = normalizeHomeAssistantPrinter([
            'name' => 'Bambu A1',
            'model' => 'A1',
            'online' => true,
            'status' => $state,
            'progress' => '100',
            'remaining_hours' => '0',
            'start_time' => 'unknown',
        ], []);

        assertSameValue('Ready', $result['status']);
        assertSameValue('ready', $result['colorClass']);
        assertSameValue('', $result['elapsed']);
    }
});

test('offline Bambu state keeps configured fallback identity', function (): void {
    $result = normalizeHomeAssistantPrinter([
        'name' => 'unavailable',
        'model' => null,
        'online' => false,
        'status' => 'unavailable',
    ], ['printerName' => 'Bambu Upstairs', 'model' => 'P1S']);

    assertSameValue('Bambu Upstairs', $result['name']);
    assertSameValue('P1S', $result['model']);
    assertSameValue('Offline', $result['status']);
    assertSameValue('', $result['progress']);
    assertSameValue('offline', $result['colorClass']);
});

test('Home Assistant fetch posts one compact template request', function (): void {
    $calls = [];
    $request = function (string $url, array $headers, ?string $body) use (&$calls): array {
        $calls[] = compact('url', 'headers', 'body');
        return [
            'ok' => true,
            'status' => 200,
            'body' => json_encode([
                'name' => 'Workshop A1',
                'model' => 'A1',
                'online' => true,
                'status' => 'running',
                'progress' => '25',
                'remaining_hours' => '0.5',
                'start_time' => 'unknown',
            ], JSON_THROW_ON_ERROR),
        ];
    };

    $result = fetchHomeAssistantPrinter(
        ['provider' => 'homeassistant', 'entityPrefix' => 'bambu_a1', 'printerName' => 'Fallback'],
        ['url' => 'http://homeassistant.local:8123', 'token' => 'test-token'],
        $request
    );

    assertSameValue(1, count($calls));
    assertSameValue('http://homeassistant.local:8123/api/template', $calls[0]['url']);
    assertSameValue('Workshop A1', $result['name']);
    assertSameValue('A1', $result['model']);
    assertContainsText('Bearer test-token', implode("\n", $calls[0]['headers']));
});

test('failed Home Assistant request returns an offline row', function (): void {
    $request = fn(string $url, array $headers, ?string $body): array => [
        'ok' => false,
        'status' => 503,
        'body' => '',
    ];

    $result = fetchHomeAssistantPrinter(
        ['provider' => 'homeassistant', 'entityPrefix' => 'bambu_a1', 'printerName' => 'Bambu A1'],
        ['url' => 'http://homeassistant.local:8123', 'token' => 'test-token'],
        $request
    );

    assertSameValue('Bambu A1', $result['name']);
    assertSameValue('Offline', $result['status']);
});

test('Home Assistant remaining time respects the reported unit', function (): void {
    $base = [
        'name' => 'Bambu A1',
        'model' => null,
        'online' => true,
        'status' => 'running',
        'progress' => '50',
        'start_time' => 'unknown',
    ];

    $minutes = normalizeHomeAssistantPrinter(
        $base + ['remaining_hours' => '90', 'remaining_unit' => 'min'],
        ['printerName' => 'Fallback', 'model' => 'A1']
    );
    $hours = normalizeHomeAssistantPrinter(
        $base + ['remaining_hours' => '1.5', 'remaining_unit' => 'h'],
        ['printerName' => 'Fallback', 'model' => 'A1']
    );

    assertSameValue('1hrs, 30mins', $minutes['left']);
    assertSameValue('1hrs, 30mins', $hours['left']);
    assertSameValue('A1', $minutes['model']);
});

test('printer ids require canonical non-negative integers within bounds', function (): void {
    assertSameValue(0, validatedPrinterId('0', 2));
    assertSameValue(1, validatedPrinterId(1, 2));

    foreach (['foo', '1x', '-1', '2', '01'] as $invalid) {
        try {
            validatedPrinterId($invalid, 2);
        } catch (InvalidArgumentException) {
            continue;
        }
        throw new RuntimeException('invalid printer id was accepted: ' . $invalid);
    }
});

test('printer API URLs only allow HTTP without embedded credentials', function (): void {
    assertSameValue('http://octoprint.local', validatedHttpBaseUrl('http://octoprint.local/'));
    assertSameValue('https://192.168.1.20/api', validatedHttpBaseUrl('https://192.168.1.20/api'));

    foreach (['file:///etc/passwd', 'ftp://printer.local', 'http://user:pass@printer.local', "http://printer.local\nHost:evil"] as $invalid) {
        try {
            validatedHttpBaseUrl($invalid);
        } catch (InvalidArgumentException) {
            continue;
        }
        throw new RuntimeException('unsafe printer URL was accepted: ' . $invalid);
    }
});

test('JSON writes fail loudly when the target cannot be written', function (): void {
    try {
        writeJsonFile('/does/not/exist/printers.json', []);
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException('failed JSON write was reported as successful');
});

test('printer model is rendered inline without special name or model typography', function (): void {
    $index = (string)file_get_contents(__DIR__ . '/../index.php');
    $styles = (string)file_get_contents(__DIR__ . '/../styles.css');

    assertContainsText("printer.name + ' (' + printer.model + ')'", $index);
    assertNotContainsText('printer-name', $index . $styles);
    assertNotContainsText('printer-model', $index . $styles);
    assertNotContainsText('printer-identity', $index . $styles);
});

test('theme controls live in admin settings and persist across public and admin pages', function (): void {
    $dashboard = (string)file_get_contents(__DIR__ . '/../index.php');
    $admin = (string)file_get_contents(__DIR__ . '/../admin/index.php');
    $adminEdit = (string)file_get_contents(__DIR__ . '/../admin/edit.php');
    $adminAuth = (string)file_get_contents(__DIR__ . '/../admin/auth.php');

    assertNotContainsText('data-theme-option=', $dashboard);
    assertContainsText('data-theme-option="light"', $admin);
    assertContainsText('data-theme-option="dark"', $admin);
    assertContainsText('src="theme.js"', $dashboard);
    assertContainsText('src="../theme.js"', $admin);
    assertContainsText('src="../theme.js"', $adminEdit);
    assertContainsText('src="../theme.js"', $adminAuth);
    $sharedTheme = (string)file_get_contents(__DIR__ . '/../theme.js');
    assertContainsText("const storageKey = '3dprinterstatus-theme'", $sharedTheme);
    assertContainsText('localStorage.getItem(storageKey)', $sharedTheme);
    assertContainsText("matchMedia('(prefers-color-scheme: dark)')", $sharedTheme);
});

test('admin connection test submits complete provider fields', function (): void {
    $adminEdit = (string)file_get_contents(__DIR__ . '/../admin/edit.php');

    assertContainsText("url: $('input[name=\"url\"]').val()", $adminEdit);
    assertContainsText("apiKey: $('input[name=\"apiKey\"]').val()", $adminEdit);
    assertContainsText("entityPrefix: $('input[name=\"entityPrefix\"]').val()", $adminEdit);
});

test('dashboard renders live status summary and progress bars', function (): void {
    $index = (string)file_get_contents(__DIR__ . '/../index.php');

    foreach (['summary-total', 'summary-printing', 'summary-ready', 'summary-failed', 'summary-offline'] as $id) {
        assertContainsText('id="' . $id . '"', $index);
    }
    assertContainsText('class="progress-track"', $index);
    assertContainsText('updateSummary(data)', $index);
});

test('dashboard omits titles controls footer and generated branding', function (): void {
    $index = strtolower((string)file_get_contents(__DIR__ . '/../index.php'));

    foreach ([
        '>3dprinterstatus<',
        'monitor. print. build. together.',
        'agent: hermes',
        'class="floor-status"',
        '>factory floor<',
        '>live monitoring<',
        'printers shown',
        'auto-refresh',
        'dashboard-footer',
    ] as $forbidden) {
        assertNotContainsText($forbidden, $index);
    }
});

test('dashboard handles unavailable theme storage and empty printer fleets', function (): void {
    $index = (string)file_get_contents(__DIR__ . '/../index.php');
    $sharedTheme = (string)file_get_contents(__DIR__ . '/../theme.js');

    assertContainsText('try {', $sharedTheme);
    assertContainsText("localStorage.setItem(storageKey, theme)", $sharedTheme);
    assertContainsText('id="empty-state"', $index);
    assertContainsText("$('#empty-state').prop('hidden', hasPrinters)", $index);
});

test('light theme status badges meet WCAG AA on normal and alternate rows', function (): void {
    $styles = (string)file_get_contents(__DIR__ . '/../styles.css');
    $colors = [];
    foreach (['green', 'yellow', 'red', 'offline'] as $name) {
        if (!preg_match('/--' . $name . ':\s*(#[0-9a-f]{6});/i', $styles, $match)) {
            throw new RuntimeException("missing light-theme --{$name} color");
        }
        $colors[$name] = strtolower($match[1]);
    }

    $badges = [
        'ready' => [$colors['green'], 0.10],
        'printing' => [$colors['yellow'], 0.10],
        'failed' => [$colors['red'], 0.10],
        'offline' => [$colors['offline'], 0.09],
    ];

    foreach (['#fafdff', '#f0f7fd'] as $rowBackground) {
        foreach ($badges as $name => [$foreground, $tint]) {
            $ratio = cssContrastRatio($foreground, cssMix($foreground, $rowBackground, $tint));
            if ($ratio < 4.5) {
                throw new RuntimeException(
                    sprintf('%s badge contrast is %.2f:1 on %s', $name, $ratio, $rowBackground)
                );
            }
        }
    }
});

$failures = 0;
foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo count($tests) . " tests passed\n";

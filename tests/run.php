<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/printer_providers.php';
require_once __DIR__ . '/../lib/job_history.php';
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

test('manual model override wins over provider and cached metadata', function (): void {
    $printer = ['model' => 'Cached A1', 'modelOverride' => 'Workshop Custom'];

    assertSameValue('Workshop Custom', printerDisplayModel($printer, 'Bambu Lab', 'A1'));
    assertSameValue('Workshop Custom', offlinePrinter($printer)['model']);
});

test('printer brands are normalized from manufacturer and OctoPrint model metadata', function (): void {
    foreach ([
        [['model' => 'Cached'], 'Bambu Lab', 'A1', 'Bambu Lab', 'bambu-lab'],
        [[], 'Bambu Lab', 'Ender 5 Plus', 'Bambu Lab', 'bambu-lab'],
        [[], null, 'Prusa i3 mk2.5s', 'Prusa Research', 'prusa-research'],
        [[], null, 'Original Prusa MK3S', 'Prusa Research', 'prusa-research'],
        [[], null, 'Ender 5 Plus', 'Creality', 'creality'],
        [[], null, 'Creality K1 Max', 'Creality', 'creality'],
        [['brand' => 'Prusa Research'], null, 'Ender 5 Plus', 'Creality', 'creality'],
        [['provider' => 'homeassistant', 'entityPrefix' => 'bambu_a1'], null, null, 'Bambu Lab', 'bambu-lab'],
    ] as [$printer, $manufacturer, $model, $brand, $icon]) {
        assertSameValue($brand, printerBrand($printer, $manufacturer, $model));
        assertSameValue($icon, printerBrandIcon($brand));
    }
    assertSameValue('generic', printerBrandIcon('../admin/auth'));
});

test('cached and manually overridden printer brands remain available offline', function (): void {
    assertSameValue('Prusa Research', printerBrand(['brand' => 'Prusa Research']));
    assertSameValue('Bambu Lab', printerBrand([
        'brand' => 'Creality',
        'brandOverride' => 'Bambu Lab',
    ]));

    $offline = offlinePrinter([
        'printerName' => 'Offline printer',
        'brand' => 'Prusa Research',
    ]);
    assertSameValue('Prusa Research', $offline['brand']);
    assertSameValue('prusa-research', $offline['brandIcon']);
});

test('OctoPrint profile metadata falls back to the cached model', function (): void {
    $printer = ['model' => 'Cached Prusa'];

    foreach ([
        [],
        ['profiles' => ['default' => ['current' => false, 'model' => 'Prusa MK3S']]],
        ['profiles' => ['default' => ['current' => true, 'model' => 'Generic RepRap Printer']]],
        ['profiles' => ['default' => 'malformed']],
    ] as $profiles) {
        assertSameValue('', octoPrintProfileModel($profiles));
        assertSameValue('Cached Prusa', printerDisplayModel($printer, null, octoPrintProfileModel($profiles)));
    }
});

test('OctoPrint keeps the active filename through print transition states', function (): void {
    $job = ['job' => ['file' => ['display' => 'part.gcode']]];

    foreach (['printing', 'paused', 'pausing', 'starting', 'resuming', 'finishing', 'cancelling'] as $state) {
        assertSameValue('part.gcode', octoPrintJobFile($job, $state));
    }
    assertSameValue('', octoPrintJobFile($job, 'operational'));
});

test('job history keeps the latest filename after a printer becomes idle', function (): void {
    $printers = [[
        'provider' => 'octoprint',
        'url' => 'http://octoprint.local',
        'apiKey' => 'not-part-of-the-key',
        'active' => true,
    ]];
    [$printingRows, $history] = mergePrinterJobHistory(
        $printers,
        [['name' => 'Ares', 'file' => 'bracket.gcode']],
        []
    );
    assertSameValue('bracket.gcode', $printingRows[0]['file']);
    assertSameValue(true, $printingRows[0]['fileCurrent']);

    [$idleRows, $retainedHistory] = mergePrinterJobHistory(
        $printers,
        [['name' => 'Ares', 'file' => '']],
        $history
    );
    assertSameValue('bracket.gcode', $idleRows[0]['file']);
    assertSameValue(false, $idleRows[0]['fileCurrent']);
    assertSameValue($history, $retainedHistory);
});

test('job history seeds an idle printer from the provider retained filename', function (): void {
    $printers = [[
        'provider' => 'octoprint',
        'url' => 'http://octoprint.local',
        'active' => true,
    ]];

    [$rows, $history] = mergePrinterJobHistory(
        $printers,
        [[
            'name' => 'Ares',
            'file' => '',
            'lastFileCandidate' => 'folder/previous-job.gcode',
        ]],
        []
    );

    assertSameValue('previous-job.gcode', $rows[0]['file']);
    assertSameValue(false, $rows[0]['fileCurrent']);
    assertSameValue(false, array_key_exists('lastFileCandidate', $rows[0]));
    assertSameValue('previous-job.gcode', $history[printerJobHistoryKey($printers[0])] ?? null);
});

test('stale idle filename candidates cannot overwrite newer job history', function (): void {
    $printers = [[
        'provider' => 'octoprint',
        'url' => 'http://octoprint.local',
        'active' => true,
    ]];
    $key = printerJobHistoryKey($printers[0]);

    [$rows, $history] = mergePrinterJobHistory(
        $printers,
        [[
            'name' => 'Ares',
            'file' => '',
            'lastFileCandidate' => 'older-job.gcode',
        ]],
        [$key => 'newer-job.gcode']
    );

    assertSameValue('newer-job.gcode', $rows[0]['file']);
    assertSameValue(false, $rows[0]['fileCurrent']);
    assertSameValue(false, array_key_exists('lastFileCandidate', $rows[0]));
    assertSameValue('newer-job.gcode', $history[$key] ?? null);
});

test('job history follows stable printer identity rather than display order', function (): void {
    $ares = ['provider' => 'octoprint', 'url' => 'http://ares.local', 'apiKey' => 'first', 'active' => true];
    $kestrel = ['provider' => 'homeassistant', 'entityPrefix' => 'bambu_kestrel', 'active' => true];
    [, $history] = mergePrinterJobHistory(
        [$ares, $kestrel],
        [
            ['name' => 'Ares', 'file' => 'ares.gcode'],
            ['name' => 'Kestrel', 'file' => 'kestrel.3mf'],
        ],
        []
    );

    [$rows] = mergePrinterJobHistory(
        [$kestrel, $ares + ['apiKey' => 'rotated-secret']],
        [
            ['name' => 'Kestrel', 'file' => ''],
            ['name' => 'Ares', 'file' => ''],
        ],
        $history
    );
    assertSameValue('kestrel.3mf', $rows[0]['file']);
    assertSameValue('ares.gcode', $rows[1]['file']);
    assertSameValue(false, $rows[0]['fileCurrent']);
    assertSameValue(false, $rows[1]['fileCurrent']);
});

test('printers without current or historical jobs retain an empty file', function (): void {
    [$rows, $history] = mergePrinterJobHistory(
        [['provider' => 'homeassistant', 'entityPrefix' => 'future_printer', 'active' => true]],
        [['name' => 'Future printer', 'file' => '']],
        []
    );
    assertSameValue('', $rows[0]['file']);
    assertSameValue(false, $rows[0]['fileCurrent']);
    assertSameValue([], $history);
});

test('job filenames remain valid UTF-8 when truncated at the cache limit', function (): void {
    $filename = str_repeat('a', 511) . 'é.gcode';
    $normalized = normalizedJobFilename($filename);

    if (strlen($normalized) > 512 || preg_match('//u', $normalized) !== 1) {
        throw new RuntimeException('Truncated job filename is not valid UTF-8 within the byte limit.');
    }
    json_encode($normalized, JSON_THROW_ON_ERROR);

    $invalid = normalizedJobFilename("bad-\xC3-name.gcode");
    if (preg_match('//u', $invalid) !== 1) {
        throw new RuntimeException('Invalid provider filename was not sanitized to UTF-8.');
    }
    json_encode($invalid, JSON_THROW_ON_ERROR);
});

test('job history refuses missing or unsafe printer identities', function (): void {
    assertSameValue(null, printerJobHistoryKey(['provider' => 'octoprint', 'printerName' => 'Shared name']));
    assertSameValue(null, printerJobHistoryKey(['provider' => 'homeassistant', 'printerName' => 'Shared name']));
    assertSameValue(null, printerJobHistoryKey([
        'provider' => 'octoprint',
        'url' => 'http://user:password@octoprint.local',
    ]));
    assertSameValue(null, printerJobHistoryKey([
        'provider' => 'octoprint',
        'url' => 'http://octoprint.local/?token=secret',
    ]));

    $first = printerJobHistoryKey([
        'provider' => 'octoprint',
        'url' => 'HTTP://OCTOPRINT.LOCAL/',
        'apiKey' => 'first-secret',
    ]);
    $second = printerJobHistoryKey([
        'provider' => 'octoprint',
        'url' => 'http://octoprint.local',
        'apiKey' => 'rotated-secret',
    ]);
    assertSameValue($first, $second);

    [$rows] = mergePrinterJobHistory(
        [['provider' => 'octoprint', 'printerName' => 'No stable identity', 'active' => true]],
        [['name' => 'Ares', 'file' => '', 'lastFileCandidate' => 'private-candidate.gcode']],
        []
    );
    assertSameValue(false, array_key_exists('lastFileCandidate', $rows[0]));
});

test('job history is persisted separately and malformed entries are ignored', function (): void {
    $path = tempnam(sys_get_temp_dir(), '3dps-history-');
    if ($path === false) {
        throw new RuntimeException('Could not create history test file.');
    }
    unlink($path);
    $key = str_repeat('a', 64);

    try {
        writePrinterJobHistory($path, [$key => 'folder/last-job.gcode']);
        assertSameValue([$key => 'last-job.gcode'], loadPrinterJobHistory($path));

        file_put_contents($path, json_encode([
            'invalid-key' => 'ignored.gcode',
            str_repeat('b', 64) => '',
            str_repeat('c', 64) => ['nested' => 'array.gcode'],
            str_repeat('d', 64) => 1234,
            str_repeat('e', 64) => true,
        ], JSON_THROW_ON_ERROR));
        assertSameValue([], loadPrinterJobHistory($path));
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('unreadable job history cannot be mistaken for empty history and reseeded', function (): void {
    $path = tempnam(sys_get_temp_dir(), '3dps-history-unreadable-');
    if ($path === false) {
        throw new RuntimeException('Could not create unreadable history test file.');
    }
    file_put_contents($path, str_repeat('x', 1048577));
    $messages = [];

    try {
        $rows = applyPrinterJobHistory(
            $path,
            [['provider' => 'octoprint', 'url' => 'http://ares.local', 'active' => true]],
            [['name' => 'Ares', 'file' => '', 'lastFileCandidate' => 'older-job.gcode']],
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        assertSameValue('', $rows[0]['file']);
        assertSameValue(false, $rows[0]['fileCurrent']);
        assertSameValue(false, array_key_exists('lastFileCandidate', $rows[0]));
        assertSameValue(1048577, filesize($path));
        assertSameValue(true, count($messages) > 0);
    } finally {
        foreach ([$path, $path . '.lock'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
});

test('locked job-history updates preserve filenames learned by separate refreshes', function (): void {
    $path = tempnam(sys_get_temp_dir(), '3dps-history-lock-');
    if ($path === false) {
        throw new RuntimeException('Could not create locked history test file.');
    }
    unlink($path);
    $printers = [
        ['provider' => 'octoprint', 'url' => 'http://ares.local', 'active' => true],
        ['provider' => 'homeassistant', 'entityPrefix' => 'bambu_kestrel', 'active' => true],
    ];

    try {
        applyPrinterJobHistory($path, $printers, [
            ['name' => 'Ares', 'file' => 'ares.gcode'],
            ['name' => 'Kestrel', 'file' => ''],
        ]);
        applyPrinterJobHistory($path, $printers, [
            ['name' => 'Ares', 'file' => ''],
            ['name' => 'Kestrel', 'file' => 'kestrel.3mf'],
        ]);

        $history = loadPrinterJobHistory($path);
        assertSameValue('ares.gcode', $history[printerJobHistoryKey($printers[0])]);
        assertSameValue('kestrel.3mf', $history[printerJobHistoryKey($printers[1])]);
    } finally {
        foreach ([$path, $path . '.lock'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
});

test('concurrent job-history updates do not lose filenames', function (): void {
    if (!function_exists('proc_open')) {
        return;
    }

    $historyPath = tempnam(sys_get_temp_dir(), '3dps-history-race-');
    if ($historyPath === false) {
        throw new RuntimeException('Could not create concurrent history test file.');
    }
    unlink($historyPath);
    $gatePath = $historyPath . '.gate';
    $processes = [];
    $workerCount = 8;

    try {
        for ($index = 0; $index < $workerCount; $index++) {
            $process = proc_open([
                PHP_BINARY,
                __DIR__ . '/job-history-worker.php',
                $historyPath,
                $gatePath,
                (string)$index,
                "http://printer-{$index}.local",
                "job-{$index}.gcode",
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start job-history worker.');
            }
            $processes[] = $process;
        }

        $deadline = microtime(true) + 10;
        while (count(glob($gatePath . '.ready.*') ?: []) < $workerCount) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Job-history workers did not become ready.');
            }
            usleep(1000);
        }
        touch($gatePath);

        foreach ($processes as $process) {
            assertSameValue(0, proc_close($process), 'job-history worker exit status');
        }
        $processes = [];

        $history = loadPrinterJobHistory($historyPath);
        assertSameValue($workerCount, count($history));
        for ($index = 0; $index < $workerCount; $index++) {
            $key = printerJobHistoryKey([
                'provider' => 'octoprint',
                'url' => "http://printer-{$index}.local",
            ]);
            assertSameValue("job-{$index}.gcode", $history[$key] ?? null);
        }
    } finally {
        foreach ($processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach (glob($gatePath . '.ready.*') ?: [] as $readyPath) {
            unlink($readyPath);
        }
        foreach ([$historyPath, $historyPath . '.lock', $gatePath] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
});

test('job-history storage failure does not hide current printer data', function (): void {
    $rows = applyPrinterJobHistory(
        '/does/not/exist/printer-history.json',
        [['provider' => 'octoprint', 'url' => 'http://ares.local', 'active' => true]],
        [['name' => 'Ares', 'file' => 'current.gcode', 'lastFileCandidate' => 'current.gcode']],
        static function (string $message): void {
        }
    );
    assertSameValue('current.gcode', $rows[0]['file']);
    assertSameValue(true, $rows[0]['fileCurrent']);
    assertSameValue(false, array_key_exists('lastFileCandidate', $rows[0]));
});

test('busy job-history lock falls back to current data within a bounded time', function (): void {
    $path = tempnam(sys_get_temp_dir(), '3dps-history-busy-');
    if ($path === false) {
        throw new RuntimeException('Could not create busy-lock test file.');
    }
    unlink($path);
    $printers = [
        ['provider' => 'octoprint', 'url' => 'http://ares.local', 'active' => true],
        ['provider' => 'octoprint', 'url' => 'http://kestrel.local', 'active' => true],
    ];
    $kestrelKey = printerJobHistoryKey($printers[1]);
    writePrinterJobHistory($path, [$kestrelKey => 'newer-job.gcode']);
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not hold the history test lock.');
    }

    try {
        $started = microtime(true);
        $rows = applyPrinterJobHistory(
            $path,
            $printers,
            [
                ['name' => 'Ares', 'file' => 'current.gcode', 'lastFileCandidate' => 'current.gcode'],
                ['name' => 'Kestrel', 'file' => '', 'lastFileCandidate' => 'older-job.gcode'],
            ],
            static function (string $message): void {
            }
        );
        $elapsed = microtime(true) - $started;
        if ($elapsed > 0.75) {
            throw new RuntimeException("Busy history lock blocked for {$elapsed} seconds.");
        }
        assertSameValue('current.gcode', $rows[0]['file']);
        assertSameValue(true, $rows[0]['fileCurrent']);
        assertSameValue(false, array_key_exists('lastFileCandidate', $rows[0]));
        assertSameValue('', $rows[1]['file']);
        assertSameValue(false, $rows[1]['fileCurrent']);
        assertSameValue(false, array_key_exists('lastFileCandidate', $rows[1]));
        assertSameValue('newer-job.gcode', loadPrinterJobHistory($path)[$kestrelKey] ?? null);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        foreach ([$path, $path . '.lock'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
});

test('OctoPrint response is normalized without changing legacy behavior', function (): void {
    $responses = [
        '/api/job' => [
            'state' => 'Printing',
            'job' => ['file' => ['display' => 'bracket-v2.gcode', 'name' => 'bracket-v2.gcode']],
            'progress' => ['printTime' => 1800, 'printTimeLeft' => 1800],
        ],
        '/api/settings' => ['appearance' => ['name' => 'Prusa MK3S']],
        '/api/printerprofiles' => ['profiles' => [
            '_default' => ['current' => true, 'model' => 'Prusa MK3S'],
        ]],
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
    assertSameValue('Prusa MK3S', $result['model']);
    assertSameValue('Prusa Research', $result['brand']);
    assertSameValue('prusa-research', $result['brandIcon']);
    assertSameValue('bracket-v2.gcode', $result['file']);
    assertSameValue('Printing', $result['status']);
    assertSameValue(50, $result['progress']);
    assertSameValue('30mins', $result['elapsed']);
    assertSameValue('30mins', $result['left']);
    assertSameValue('printing', $result['colorClass']);
});

test('idle OctoPrint exposes its retained API job as a history candidate', function (): void {
    $responses = [
        '/api/job' => [
            'state' => 'Operational',
            'job' => ['file' => ['display' => 'previous-bracket.gcode']],
            'progress' => ['printTime' => null, 'printTimeLeft' => null],
        ],
        '/api/settings' => ['appearance' => ['name' => 'Ares']],
        '/api/printerprofiles' => ['profiles' => []],
    ];
    $request = static function (string $url, array $headers, ?string $body) use ($responses): array {
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
    ], $request);

    assertSameValue('', $result['file']);
    assertSameValue('previous-bracket.gcode', $result['lastFileCandidate']);
    assertSameValue('Ready', $result['status']);
});

test('offline OctoPrint stops after the failed job request', function (): void {
    $calls = [];
    $request = function (string $url, array $headers, ?string $body) use (&$calls): array {
        $calls[] = parse_url($url, PHP_URL_PATH);
        return ['ok' => false, 'status' => 503, 'body' => ''];
    };

    $result = fetchOctoPrintPrinter([
        'url' => 'http://octoprint.local',
        'apiKey' => 'secret',
        'printerName' => 'Offline printer',
    ], $request);

    assertSameValue(['/api/job'], $calls);
    assertSameValue('Offline', $result['status']);
});

test('Home Assistant template requests Bambu status and device model', function (): void {
    $template = buildHomeAssistantTemplate('bambu_a1');

    assertContainsText("sensor.bambu_a1_printer_name", $template);
    assertContainsText("binary_sensor.bambu_a1_online", $template);
    assertContainsText("sensor.bambu_a1_print_progress", $template);
    assertContainsText("device_attr('sensor.bambu_a1_print_status', 'name_by_user')", $template);
    assertContainsText("device_attr('sensor.bambu_a1_print_status', 'manufacturer')", $template);
    assertContainsText("device_attr('sensor.bambu_a1_print_status', 'model')", $template);
    assertContainsText("sensor.bambu_a1_gcode_filename", $template);
    assertContainsText("sensor.bambu_a1_task_name", $template);
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
        'manufacturer' => 'Bambu Lab',
        'model' => 'A1',
        'online' => true,
        'status' => 'running',
        'progress' => '42',
        'remaining_hours' => '1.5',
        'remaining_unit' => 'h',
        'start_time' => '2026-09-17T13:45:00-04:00',
        'gcode_filename' => 'dragon-v4.gcode.3mf',
        'task_name' => 'Dragon v4',
    ], ['printerName' => 'Fallback'], $now);

    assertSameValue('Workshop A1', $result['name']);
    assertSameValue('Bambu Lab A1', $result['model']);
    assertSameValue('Bambu Lab', $result['brand']);
    assertSameValue('bambu-lab', $result['brandIcon']);
    assertSameValue('dragon-v4.gcode.3mf', $result['file']);
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
            'gcode_filename' => 'last-print.gcode.3mf',
        ], []);

        assertSameValue('Ready', $result['status']);
        assertSameValue('ready', $result['colorClass']);
        assertSameValue('', $result['elapsed']);
        assertSameValue('', $result['file']);
        assertSameValue('last-print.gcode.3mf', $result['lastFileCandidate']);
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
    assertContainsText('name="modelOverride"', $adminEdit);
    assertContainsText("\$_POST['modelOverride']", $adminEdit);
    assertContainsText('name="brandOverride"', $adminEdit);
    assertContainsText("\$_POST['brandOverride']", $adminEdit);
});

test('dashboard uses locally cached brand icons beside printer names', function (): void {
    $index = (string)file_get_contents(__DIR__ . '/../index.php');
    $styles = (string)file_get_contents(__DIR__ . '/../styles.css');

    assertContainsText("'assets/brand-icons/' + brandIcon + '.svg'", $index);
    assertContainsText("['bambu-lab', 'prusa-research', 'creality'].includes(printer.brandIcon)", $index);
    assertContainsText(".addClass('printer-brand-icon')", $index);
    assertNotContainsText(".append(createIcon('printer-3d'))", $index);
    assertContainsText('.printer-brand-icon', $styles);

    foreach (['bambu-lab', 'prusa-research', 'creality', 'generic'] as $slug) {
        $path = __DIR__ . '/../assets/brand-icons/' . $slug . '.svg';
        if (!is_file($path)) {
            throw new RuntimeException("missing cached brand icon {$slug}");
        }
        $svg = (string)file_get_contents($path);
        assertContainsText('<svg', $svg);
        assertNotContainsText('<script', strtolower($svg));
        assertNotContainsText('onload=', strtolower($svg));
    }
});

test('dashboard distinguishes current files from cached last-job filenames', function (): void {
    $index = (string)file_get_contents(__DIR__ . '/../index.php');
    $styles = (string)file_get_contents(__DIR__ . '/../styles.css');

    assertContainsText("printer.fileCurrent === false ? 'Last: ' : ''", $index);
    assertContainsText(".toggleClass('file-last', printer.fileCurrent === false", $index);
    assertContainsText('.file-last', $styles);
});

test('dashboard restores reference iconography glow and print file metadata', function (): void {
    $index = (string)file_get_contents(__DIR__ . '/../index.php');
    $styles = (string)file_get_contents(__DIR__ . '/../styles.css');

    foreach (['icon-printer-3d', 'icon-printing', 'icon-ready', 'icon-failed', 'icon-offline', 'icon-file', 'icon-clock', 'icon-timer'] as $icon) {
        assertContainsText('id="' . $icon . '"', $index);
    }
    assertContainsText('data-label', $index);
    assertContainsText("printer.file ? filePrefix + printer.file : '—'", $index);
    assertContainsText(".attr('title', identity)", $index);
    assertContainsText(".attr('title', printer.file ? filePrefix + printer.file : '')", $index);
    assertContainsText('.summary-printing', $styles);
    assertContainsText('.summary-ready', $styles);
    assertContainsText('.summary-failed', $styles);
    assertContainsText('box-shadow:', $styles);
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

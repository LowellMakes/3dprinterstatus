<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/printer_providers.php';

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

test('Home Assistant configuration loads from a private JSON file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ha-config-');
    file_put_contents($path, json_encode([
        'url' => 'http://ha.local:8123/',
        'token' => 'file-token',
    ], JSON_THROW_ON_ERROR));

    try {
        $config = loadHomeAssistantConfig($path, []);
        assertSameValue('http://ha.local:8123', $config['url']);
        assertSameValue('file-token', $config['token']);
    } finally {
        unlink($path);
    }
});

test('Home Assistant environment variables override private JSON', function (): void {
    $config = loadHomeAssistantConfig('/does/not/exist', [
        'HOME_ASSISTANT_URL' => 'http://ha-env.local:8123/',
        'HOME_ASSISTANT_TOKEN' => 'env-token',
    ]);

    assertSameValue('http://ha-env.local:8123', $config['url']);
    assertSameValue('env-token', $config['token']);
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

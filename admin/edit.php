<?php

declare(strict_types=1);

require 'protect.php';
require_once __DIR__ . '/../lib/printer_providers.php';
require_once __DIR__ . '/../lib/app_config.php';

$applicationConfig = applicationConfig();
$file = $applicationConfig['printers_file'];
$cacheFile = $applicationConfig['cache_file'];
$printers = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
$printers = is_array($printers) ? $printers : [];
$id = null;
if (isset($_GET['id'])) {
    try {
        $id = validatedPrinterId($_GET['id'], count($printers));
    } catch (InvalidArgumentException) {
        http_response_code(404);
        exit('Printer not found.');
    }
}
$printer = $id !== null ? $printers[$id] : [
    'provider' => 'octoprint',
    'printerName' => '',
    'model' => '',
    'modelOverride' => '',
    'url' => '',
    'apiKey' => '',
    'entityPrefix' => '',
    'active' => true,
];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $provider = printerProvider(['provider' => $_POST['provider'] ?? 'octoprint']);
    $data = [
        'provider' => $provider,
        'printerName' => (string)($printer['printerName'] ?? ''),
        'model' => (string)($printer['model'] ?? ''),
        'modelOverride' => trim((string)($_POST['modelOverride'] ?? '')),
        'active' => isset($_POST['active']),
    ];

    if ($provider === 'homeassistant') {
        $data['entityPrefix'] = strtolower(trim((string)($_POST['entityPrefix'] ?? '')));
    } else {
        $data['url'] = trim((string)($_POST['url'] ?? ''));
        $data['apiKey'] = trim((string)($_POST['apiKey'] ?? ''));
    }

    try {
        if ($provider === 'homeassistant') {
            buildHomeAssistantTemplate((string)$data['entityPrefix']);
        } else {
            $data['url'] = validatedHttpBaseUrl((string)$data['url']);
        }

        $live = fetchPrinter($data, $applicationConfig['home_assistant']);
        if ($live['status'] === 'Offline') {
            if ($id === null) {
                throw new RuntimeException('Could not contact the printer through ' . ($provider === 'homeassistant' ? 'Home Assistant.' : 'OctoPrint.'));
            }
        } else {
            $data['printerName'] = $live['name'];
            if ($data['modelOverride'] === '') {
                $data['model'] = $live['model'];
            }
        }

        if ($id !== null) {
            $printers[$id] = $data;
        } else {
            $printers[] = $data;
        }

        writeJsonFile($file, $printers);
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }
        header('Location: index.php');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $printer = $data;
    }
}

$provider = printerProvider($printer);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= $id !== null ? 'Edit Printer' : 'Add Printer' ?></title>
    <link rel="stylesheet" href="admin.css">
    <script src="../theme.js"></script>
</head>
<body>
<main class="form-shell">
    <div class="card form-card">
        <p class="eyebrow">Printer inventory</p>
        <h1><?= $id !== null ? 'Edit printer' : 'Add printer' ?></h1>
        <p class="subtitle">Configure the provider connection and dashboard availability.</p>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" class="printer-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <?php if ($id !== null && !empty($printer['printerName'])): ?>
                <div class="field-group">
                    <label for="printer-name">Printer</label>
                    <input id="printer-name" class="printer-name" value="<?= htmlspecialchars((string)$printer['printerName']) ?>" readonly>
                </div>
            <?php endif; ?>

            <div class="field-group">
                <label for="provider">Provider</label>
                <select name="provider" id="provider">
                    <option value="octoprint" <?= $provider === 'octoprint' ? 'selected' : '' ?>>OctoPrint</option>
                    <option value="homeassistant" <?= $provider === 'homeassistant' ? 'selected' : '' ?>>Home Assistant / Bambu Lab</option>
                </select>
            </div>

            <div class="field-group">
                <label for="model-override">Make / model override <span class="optional">Optional</span></label>
                <input id="model-override" type="text" name="modelOverride"
                       value="<?= htmlspecialchars((string)($printer['modelOverride'] ?? '')) ?>"
                       placeholder="Auto-detect from provider">
                <span class="field-help">
                    Leave blank to use provider metadata.
                    <?php if (!empty($printer['model'])): ?>
                        Currently detected as <?= htmlspecialchars((string)$printer['model']) ?>.
                    <?php endif; ?>
                </span>
            </div>

            <div class="provider-fields field-grid" data-provider="octoprint">
                <div class="field-group">
                    <label for="printer-url">OctoPrint URL</label>
                    <input id="printer-url" type="url" name="url"
                           value="<?= htmlspecialchars((string)($printer['url'] ?? '')) ?>"
                           placeholder="http://printer.local">
                </div>
                <div class="field-group">
                    <label for="printer-api-key">API key</label>
                    <input id="printer-api-key" type="password" name="apiKey"
                           value="<?= htmlspecialchars((string)($printer['apiKey'] ?? '')) ?>"
                           placeholder="OctoPrint API key" autocomplete="off">
                </div>
            </div>

            <div class="provider-fields" data-provider="homeassistant">
                <div class="field-group">
                    <label for="entity-prefix">Entity prefix</label>
                    <input id="entity-prefix" type="text" name="entityPrefix"
                           value="<?= htmlspecialchars((string)($printer['entityPrefix'] ?? '')) ?>"
                           placeholder="bambu_a1" pattern="[a-z0-9_]+">
                    <span class="field-help">The shared prefix used by this printer's Home Assistant entities.</span>
                </div>
            </div>

            <div class="connection-row">
                <button class="button secondary" type="button" id="test-connection">Test connection</button>
                <span id="test-result" class="test-result" role="status"></span>
            </div>

            <label class="checkbox-row">
                <input type="checkbox" name="active" <?= ($printer['active'] ?? true) ? 'checked' : '' ?>>
                <span>Show this printer on the dashboard</span>
            </label>

            <div class="form-actions">
                <button class="button" type="submit">Save printer</button>
                <a class="button secondary" href="index.php">Cancel</a>
            </div>
        </form>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function updateProviderFields() {
    const provider = $('#provider').val();
    $('.provider-fields').hide();
    $('.provider-fields[data-provider="' + provider + '"]').show();
}

$('#provider').on('change', updateProviderFields);
updateProviderFields();

$('#test-connection').click(function () {
    $('#test-result').text('Testing...');

    $.post('test_printer.php', {
        csrf_token: <?= json_encode(csrf_token()) ?>,
        provider: $('#provider').val(),
        url: $('input[name="url"]').val(),
        apiKey: $('input[name="apiKey"]').val(),
        entityPrefix: $('input[name="entityPrefix"]').val()
    }, function (res) {
        if (res.success) {
            const model = res.model ? ' (' + res.model + ')' : '';
            $('#test-result').text('Succeeded: ' + res.name + model).removeClass('failed').addClass('succeeded');
        } else {
            $('#test-result').text(res.message || 'Failed').removeClass('succeeded').addClass('failed');
        }
    }, 'json').fail(function () {
        $('#test-result').text('Request failed').removeClass('succeeded').addClass('failed');
    });
});
</script>
</body>
</html>

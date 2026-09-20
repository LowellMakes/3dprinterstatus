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
            $data['model'] = $live['model'];
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

<link rel="stylesheet" href="admin.css">

<div class="card">
<h1><?= $id !== null ? 'Edit Printer' : 'Add Printer' ?></h1>

<?php if ($error !== ''): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
<div class="form-row">
  <?php if ($id !== null && !empty($printer['printerName'])): ?>
    <input class="printer-name" value="<?= htmlspecialchars((string)$printer['printerName']) ?>" readonly>
  <?php endif; ?>

  <select name="provider" id="provider">
    <option value="octoprint" <?= $provider === 'octoprint' ? 'selected' : '' ?>>OctoPrint</option>
    <option value="homeassistant" <?= $provider === 'homeassistant' ? 'selected' : '' ?>>Home Assistant / Bambu Lab</option>
  </select>

  <span class="provider-fields" data-provider="octoprint">
    <input type="text" name="url"
           value="<?= htmlspecialchars((string)($printer['url'] ?? '')) ?>"
           placeholder="OctoPrint URL">
    <input type="text" name="apiKey"
           value="<?= htmlspecialchars((string)($printer['apiKey'] ?? '')) ?>"
           placeholder="API Key">
  </span>

  <span class="provider-fields" data-provider="homeassistant">
    <input type="text" name="entityPrefix"
           value="<?= htmlspecialchars((string)($printer['entityPrefix'] ?? '')) ?>"
           placeholder="Entity prefix, e.g. bambu_a1"
           pattern="[a-z0-9_]+">
  </span>

  <button type="button" id="test-connection">Test Connection</button>
  <span id="test-result"></span>

  <label>
    <input type="checkbox" name="active" <?= ($printer['active'] ?? true) ? 'checked' : '' ?>>
    Active
  </label>
</div>

<br><br>
<button class="button">Save</button>
<a class="button secondary" href="index.php">Cancel</a>
</form>
</div>

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

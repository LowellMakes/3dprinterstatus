<?php

declare(strict_types=1);

require 'protect.php';
require_once __DIR__ . '/../lib/printer_providers.php';

$file = __DIR__ . '/../../private/printers.json';
$cacheFile = '/tmp/printer_data_cache.json';
$printers = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
$printers = is_array($printers) ? $printers : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move'])) {
    require_csrf_token();
    try {
        $i = validatedPrinterId($_POST['move'], count($printers));
    } catch (InvalidArgumentException) {
        http_response_code(400);
        exit('Invalid printer id.');
    }
    $dir = $_POST['dir'] ?? '';
    $group = $_POST['group'] ?? 'all';

    if ($group === 'active') {
        $keys = array_keys(array_filter($printers, fn($p) => printerIsActive($p)));
    } elseif ($group === 'inactive') {
        $keys = array_keys(array_filter($printers, fn($p) => !printerIsActive($p)));
    } else {
        $keys = array_keys($printers);
    }

    $pos = array_search($i, $keys, true);
    if ($pos !== false) {
        if ($dir === 'up' && $pos > 0) {
            [$printers[$keys[$pos - 1]], $printers[$keys[$pos]]] = [$printers[$keys[$pos]], $printers[$keys[$pos - 1]]];
        } elseif ($dir === 'down' && $pos < count($keys) - 1) {
            [$printers[$keys[$pos + 1]], $printers[$keys[$pos]]] = [$printers[$keys[$pos]], $printers[$keys[$pos + 1]]];
        }

        writeJsonFile($file, $printers);
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }
    }

    header('Location: index.php');
    exit;
}

$active = [];
$inactive = [];
foreach ($printers as $index => $printer) {
    if (printerIsActive($printer)) {
        $active[$index] = $printer;
    } else {
        $inactive[$index] = $printer;
    }
}

function endpointLabel(array $printer): string
{
    return printerProvider($printer) === 'homeassistant'
        ? (string)($printer['entityPrefix'] ?? '')
        : (string)($printer['url'] ?? '');
}

function printerTable(array $printers, string $group): void
{
    foreach ($printers as $i => $printer) {
        $provider = printerProvider($printer);
        ?>
        <tr class="<?= $group === 'inactive' ? 'inactive' : '' ?>">
            <td><?= htmlspecialchars((string)($printer['printerName'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($printer['model'] ?? '')) ?></td>
            <td><?= $provider === 'homeassistant' ? 'Home Assistant' : 'OctoPrint' ?></td>
            <td><?= htmlspecialchars(endpointLabel($printer)) ?></td>
            <td>
                <a class="button" href="edit.php?id=<?= $i ?>">Edit</a>
                <form method="post" action="delete.php" class="inline-form" onsubmit="return confirm('Delete this printer?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $i ?>">
                    <button class="button red">Delete</button>
                </form>
                <?php if ($group === 'active'): ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="move" value="<?= $i ?>">
                        <input type="hidden" name="dir" value="up">
                        <input type="hidden" name="group" value="active">
                        <button class="button">⬆</button>
                    </form>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="move" value="<?= $i ?>">
                        <input type="hidden" name="dir" value="down">
                        <input type="hidden" name="group" value="active">
                        <button class="button">⬇</button>
                    </form>
                <?php endif; ?>
                <button class="test-btn" data-id="<?= $i ?>">Test</button>
                <span class="test-result"></span>
            </td>
        </tr>
        <?php
    }
}
?>

<link rel="stylesheet" href="admin.css">
<div class="card">
<h1>Printer Admin</h1>
<a class="button" href="edit.php">➕ Add Printer</a>

<h2>Active Printers</h2>
<table>
<tr><th>Name</th><th>Model</th><th>Provider</th><th>Endpoint</th><th>Actions</th></tr>
<?php printerTable($active, 'active'); ?>
</table>

<h2>Inactive Printers</h2>
<table>
<tr><th>Name</th><th>Model</th><th>Provider</th><th>Endpoint</th><th>Actions</th></tr>
<?php printerTable($inactive, 'inactive'); ?>
</table>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$('.test-btn').click(function () {
    const btn = $(this);
    const result = btn.next('.test-result');
    result.text('Testing...');

    $.post('test_printer.php', {
        csrf_token: <?= json_encode(csrf_token()) ?>,
        id: btn.data('id')
    }, function (res) {
        result.text(res.success ? 'Succeeded' : (res.message || 'Failed'))
            .toggleClass('succeeded', res.success)
            .toggleClass('failed', !res.success);
    }, 'json').fail(function (xhr) {
        const response = xhr.responseJSON || {};
        result.text(response.message || 'Request failed').removeClass('succeeded').addClass('failed');
    });
});
</script>

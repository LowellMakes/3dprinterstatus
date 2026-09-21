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
        <tr class="printer-admin-row <?= $group === 'inactive' ? 'inactive' : '' ?>">
            <td><?= htmlspecialchars((string)($printer['printerName'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($printer['model'] ?? '')) ?></td>
            <td><?= $provider === 'homeassistant' ? 'Home Assistant' : 'OctoPrint' ?></td>
            <td><?= htmlspecialchars(endpointLabel($printer)) ?></td>
            <td class="actions-cell">
                <div class="action-group">
                    <a class="button compact" href="edit.php?id=<?= $i ?>">Edit</a>
                    <form method="post" action="delete.php" class="inline-form" onsubmit="return confirm('Delete this printer?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= $i ?>">
                        <button class="button compact red" type="submit">Delete</button>
                    </form>
                    <?php if ($group === 'active'): ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="move" value="<?= $i ?>">
                            <input type="hidden" name="dir" value="up">
                            <input type="hidden" name="group" value="active">
                            <button class="button compact icon-button" type="submit" aria-label="Move printer up">↑</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="move" value="<?= $i ?>">
                            <input type="hidden" name="dir" value="down">
                            <input type="hidden" name="group" value="active">
                            <button class="button compact icon-button" type="submit" aria-label="Move printer down">↓</button>
                        </form>
                    <?php endif; ?>
                    <button class="button compact secondary test-btn" type="button" data-id="<?= $i ?>">Test</button>
                    <span class="test-result"></span>
                </div>
            </td>
        </tr>
        <?php
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Printer Admin</title>
    <link rel="stylesheet" href="admin.css">
    <script src="../theme.js"></script>
</head>
<body>
<main class="admin-shell">
    <header class="admin-header">
        <div>
            <p class="eyebrow">Administration</p>
            <h1>Printer settings</h1>
            <p class="subtitle">Manage printer connections, display order, and dashboard appearance.</p>
        </div>
        <nav class="header-actions" aria-label="Admin actions">
            <a class="button secondary" href="../index.php">View dashboard</a>
            <a class="button" href="edit.php">Add printer</a>
        </nav>
    </header>

    <section class="admin-panel settings-panel" aria-labelledby="appearance-heading">
        <div>
            <p class="section-kicker">Display</p>
            <h2 id="appearance-heading">Dashboard appearance</h2>
            <p class="section-description">Choose the theme used by this browser on both dashboard and admin pages.</p>
        </div>
        <div class="theme-switcher" role="group" aria-label="Color theme">
            <button type="button" data-theme-option="light" aria-label="Use light mode">
                <span aria-hidden="true">☀</span> Light
            </button>
            <button type="button" data-theme-option="dark" aria-label="Use dark mode">
                <span aria-hidden="true">☾</span> Dark
            </button>
        </div>
    </section>

    <section class="admin-panel" aria-labelledby="active-printers-heading">
        <div class="section-heading">
            <div>
                <p class="section-kicker">Online inventory</p>
                <h2 id="active-printers-heading">Active printers</h2>
            </div>
            <span class="count-badge"><?= count($active) ?></span>
        </div>
        <div class="admin-table-scroll">
            <table>
                <thead><tr><th>Name</th><th>Model</th><th>Provider</th><th>Endpoint</th><th>Actions</th></tr></thead>
                <tbody><?php printerTable($active, 'active'); ?></tbody>
            </table>
        </div>
        <?php if ($active === []): ?><p class="empty-message">No active printers are configured.</p><?php endif; ?>
    </section>

    <section class="admin-panel" aria-labelledby="inactive-printers-heading">
        <div class="section-heading">
            <div>
                <p class="section-kicker">Hidden inventory</p>
                <h2 id="inactive-printers-heading">Inactive printers</h2>
            </div>
            <span class="count-badge muted"><?= count($inactive) ?></span>
        </div>
        <div class="admin-table-scroll">
            <table>
                <thead><tr><th>Name</th><th>Model</th><th>Provider</th><th>Endpoint</th><th>Actions</th></tr></thead>
                <tbody><?php printerTable($inactive, 'inactive'); ?></tbody>
            </table>
        </div>
        <?php if ($inactive === []): ?><p class="empty-message">No inactive printers.</p><?php endif; ?>
    </section>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$('.test-btn').click(function () {
    const btn = $(this);
    const result = btn.siblings('.test-result');
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
</body>
</html>

<?php
require 'protect.php';

$file = __DIR__ . '/../../private/printers.json';
$printers = json_decode(file_get_contents($file), true);
$cacheFile = '/tmp/printer_data_cache.json'; // Set the cache file path

$id = $_GET['id'] ?? null;

$printer = $printers[$id] ?? [
    'printerName' => '',
    'url' => '',
    'apiKey' => '',
    'active' => true
];

function getPrinterName($url, $apiKey) {

    $base = rtrim($url, '/');

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "X-Api-Key: $apiKey\r\n",
            "timeout" => 5
        ]
    ];

    $context = stream_context_create($opts);
    $json = @file_get_contents($base . '/api/settings', false, $context);


    if ($json === false) {
        error_log("API FAILED: " . $base . "/api/settings");
        return '';
    }

    $data = json_decode($json, true);

    return $data['appearance']['name'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ALWAYS take values from the form first
    $url    = $_POST['url'] ?? '';
    $apiKey = $_POST['apiKey'] ?? '';

    // Fetch live name using the POSTed values
    $printerName = getPrinterName($url, $apiKey);

    // If editing and API failed → keep existing name
    if ($id !== null && empty($printerName) && !empty($printers[$id]['printerName'])) {
        $printerName = $printers[$id]['printerName'];
    }

    // If adding and API failed → stop
    if ($id === null && empty($printerName)) {
        die("Could not contact printer API.");
    }

    // Build data from POST (THIS IS CORRECT)
    $data = [
        'printerName' => $printerName,
        'url'         => $url,
        'apiKey'      => $apiKey,
        'active'      => isset($_POST['active'])
    ];

    // Save
    if ($id !== null) {
        $printers[$id] = $data;
    } else {
        $printers[] = $data;
    }

    file_put_contents($file, json_encode($printers, JSON_PRETTY_PRINT));

    if (file_exists($cacheFile)) {
        unlink($cacheFile);
    }

    header('Location: index.php');
    exit;
}
?>

<link rel="stylesheet" href="admin.css">

<div class="card">

<h1><?= $id !== null ? "Edit Printer" : "Add Printer" ?></h1>

<form method="post">

<div class="form-row">
  <?php if ($id !== null && !empty($printer['printerName'])): ?>
    <input class="printer-name" value="<?= htmlspecialchars($printer['printerName']) ?>" readonly>
  <?php endif; ?>

  <input type="text" name="url"
         value="<?= htmlspecialchars($printer['url'] ?? '') ?>"
         placeholder="Octoprint URL">

  <input type="text" name="apiKey"
         value="<?= htmlspecialchars($printer['apiKey'] ?? '') ?>"
         placeholder="API Key">

  <button type="button" id="test-connection">Test Connection</button>
  <span id="test-result"></span>

  <label>
    <input type="checkbox" name="active" <?= $printer['active'] ? 'checked' : '' ?>>
    Active
  </label>
</div>

<br><br>

<button class="button">Save</button>

</form>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
$('#test-connection').click(function () {

    let url = $('input[name="url"]').val();
    let key = $('input[name="apiKey"]').val();

    $('#test-result').text('Testing...');

    $.post('test_printer.php', {
        url: url,
        apiKey: key
    }, function (res) {

        if (res.success) {
            $('#test-result').html('<span style="color:green;">Succeeded</span>');
	    $('#printerName').val(res.name);
        } else {
            $('#test-result').html('<span style="color:red;">Failed</span>');
        }

    }, 'json');

});
</script>


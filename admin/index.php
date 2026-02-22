<?php
require 'protect.php';

$file = __DIR__ . '/../../private/printers.json';
$printers = json_decode(file_get_contents($file), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move'])) {

    $i = (int)$_POST['move'];
    $dir = $_POST['dir'] ?? '';
    $group = $_POST['group'] ?? 'all';

    // Build the ordered index list for the requested group
    if ($group === 'active') {
        $keys = array_keys(array_filter($printers, fn($p) => !empty($p['active'])));
    } elseif ($group === 'inactive') {
        $keys = array_keys(array_filter($printers, fn($p) => empty($p['active'])));
    } else {
        // fallback: whole list
        $keys = array_keys($printers);
    }

    $pos = array_search($i, $keys, true);

    if ($pos !== false) {

        // move up within that group
        if ($dir === 'up' && $pos > 0) {
            $a = $keys[$pos];
            $b = $keys[$pos - 1];
            [$printers[$b], $printers[$a]] = [$printers[$a], $printers[$b]];
        }

        // move down within that group
        if ($dir === 'down' && $pos < count($keys) - 1) {
            $a = $keys[$pos];
            $b = $keys[$pos + 1];
            [$printers[$b], $printers[$a]] = [$printers[$a], $printers[$b]];
        }

        file_put_contents($file, json_encode($printers, JSON_PRETTY_PRINT));
	if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    header("Location: index.php");
    exit;
}

$active = [];
$inactive = [];

foreach ($printers as $index => $printer) {
    if ($printer['active']) {
        $active[$index] = $printer;
    } else {
        $inactive[$index] = $printer;
    }
}

?>

<link rel="stylesheet" href="admin.css">

<div class="card">

<h1>Printer Admin</h1>

<a class="button" href="edit.php">➕ Add Printer</a>

<h2>Active Printers</h2>

<table>
<tr>
<th>Name</th>
<th>URL</th>
<th>Actions</th>
</tr>

<?php foreach ($active as $i => $p): ?>
<tr>
<td><?= htmlspecialchars($p['printerName']) ?></td>
<td><?= htmlspecialchars($p['url']) ?></td>
<td>
<a class="button" href="edit.php?id=<?= $i ?>">Edit</a>
<a class="button red" href="delete.php?id=<?= $i ?>"
   onclick="return confirm('Delete this printer?')">Delete</a>
<form method="post" style="display:inline;">
    <input type="hidden" name="move" value="<?= $i ?>">
    <input type="hidden" name="dir" value="up">
    <input type="hidden" name="group" value="active">
    <button class="button">⬆</button>
</form>

<form method="post" style="display:inline;">
    <input type="hidden" name="move" value="<?= $i ?>">
    <input type="hidden" name="dir" value="down">
    <input type="hidden" name="group" value="active">
    <button class="button">⬇</button>
</form>
<button class="test-btn"
        data-url="<?= htmlspecialchars($p['url']) ?>"
        data-key="<?= htmlspecialchars($p['apiKey']) ?>">
    Test
</button>

<span class="test-result"></span>
</td>
</tr>
<?php endforeach; ?>
</table>

<h2>Inactive Printers</h2>

<table>
<tr>
<th>Name</th>
<th>URL</th>
<th>Actions</th>
</tr>

<?php foreach ($inactive as $i => $p): ?>
<tr class="inactive">
<td><?= htmlspecialchars($p['printerName']) ?></td>
<td><?= htmlspecialchars($p['url']) ?></td>
<td>
<a class="button" href="edit.php?id=<?= $i ?>">Edit</a>
<a class="button red" href="delete.php?id=<?= $i ?>"
   onclick="return confirm('Delete this printer?')">Delete</a>
</td>
</tr>
<?php endforeach; ?>
</table>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
$('.test-btn').click(function () {

    let btn = $(this);
    let result = btn.next('.test-result');

    result.text('Testing...');

    $.post('test_printer.php', {
        url: btn.data('url'),
        apiKey: btn.data('key')
    }, function (res) {

        if (res.success) {
            result.html('<span style="color:green;">Succeeded</span>');
        } else {
            result.html('<span style="color:red;">Failed</span>');
        }

    }, 'json');

});
</script>

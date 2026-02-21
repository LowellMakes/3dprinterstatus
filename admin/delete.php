<?php
require 'protect.php';

$file = __DIR__ . '/../../private/printers.json';
$printers = json_decode(file_get_contents($file), true);
$cacheFile = '/tmp/printer_data_cache.json'; // Set the cache file path

$id = $_GET['id'];
unset($printers[$id]);

file_put_contents($file, json_encode(array_values($printers), JSON_PRETTY_PRINT));
if (file_exists($cacheFile)) {
    unlink($cacheFile);
}

header('Location: index.php');

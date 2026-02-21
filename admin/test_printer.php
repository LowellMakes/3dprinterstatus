<?php
require 'protect.php';

$url    = $_POST['url']    ?? '';
$apiKey = $_POST['apiKey'] ?? '';

if (!$url || !$apiKey) {
    echo json_encode(['success' => false, 'message' => 'Missing URL or API key']);
    exit;
}

/*
 * Always normalize to base URL
 * so it works whether user pastes:
 *   http://host
 *   http://host/
 *   http://host/api
 */
$base = preg_replace('#/api/?$#', '', rtrim($url, '/'));
$endpoint = $base . '/api/settings';   // same source your main page uses

$opts = [
    "http" => [
        "method"  => "GET",
        "header"  => "X-Api-Key: $apiKey\r\n",
        "timeout" => 5
    ]
];

$context = stream_context_create($opts);
$response = @file_get_contents($endpoint, false, $context);

// error_log("DEBUG endpoint=" . $endpoint);
// error_log("DEBUG response=" . $response);

if ($response === false) {
    echo json_encode(['success' => false, 'message' => 'Connection failed']);
    exit;
}

$data = json_decode($response, true);

if (!empty($data['appearance']['name'])) {
    echo json_encode(['success' => true, 'name' => $data['appearance']['name']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid API response']);
}

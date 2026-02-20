<?php

$printers = json_decode(file_get_contents(__DIR__ . '/printers.json'), true);

$cacheFile = '/tmp/printer_data_cache.json'; // Set the cache file path

// Function to format time in hours and minutes
function formatTime($time) {
    $hours = floor($time / 3600);
    $minutes = floor(($time % 3600) / 60);

    if ($hours == 0) {
        return "${minutes}mins";
    } else {
        return "${hours}hrs, ${minutes}mins";
    }
}

function fetchPrinterData() {
    global $printers;
    $printerData = array();

    // Loop through each printer
    foreach ($printers as $printer) {
	   if (!($printer['active'] ?? true)) {
               continue;
           }

	    $printerName = $printer['printerName'];
	    $printerUrl = $printer['url'];
	    $printerApiKey = $printer['apiKey'];
	
	    // Make a request to get the current job information
	    $apiBase = rtrim($printerUrl, '/') . '/api/';
	    $urlJob = $apiBase . 'job';
	    $chJob = curl_init();
	    curl_setopt($chJob, CURLOPT_URL, $urlJob);
	    curl_setopt($chJob, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($chJob, CURLOPT_HTTPHEADER, array('X-Api-Key: ' . $printerApiKey));
	    $responseJob = curl_exec($chJob);
	    curl_close($chJob);

	    // Make a request to get the server settings
	    $urlSettings = $apiBase . 'settings';
	    $chSettings = curl_init();
	    curl_setopt($chSettings, CURLOPT_URL, $urlSettings);
	    curl_setopt($chSettings, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($chSettings, CURLOPT_HTTPHEADER, array('X-Api-Key: ' . $printerApiKey));
	    $responseSettings = curl_exec($chSettings);
	    curl_close($chSettings);

	    // Parse the job information response JSON
	    $job = json_decode($responseJob, true);

	    // Parse the server settings response JSON
	    $settings = json_decode($responseSettings, true);

	    // Determine the state and set the color class accordingly
	    $state = isset($job['state']) ? strtolower($job['state']) : 'offline';
	    $colorClass = $state === 'operational' ? 'ready' : ($state === 'printing' ? 'printing' : 'offline');

	   // Assemble printer data
	    if ($state === 'offline') {
	    	$printerData[] = array(
	   	    'name' => $printer['printerName'],
	        'status' => 'Offline',
	        'progress' => '',
	        'elapsed' => '',
	        'left' => '',
		'colorClass' => $colorClass,
		    );
	    } else {
        $printerData[] = array(
            'name' => $settings['appearance']['name'],
            'status' => ($state === 'offline') ? 'Offline' : ($state === 'operational' ? 'Ready' : $job['state']),
            'progress' => (isset($job['progress']['printTime']) && isset($job['progress']['printTimeLeft'])) ? intval(($job['progress']['printTime'] / ($job['progress']['printTime'] + $job['progress']['printTimeLeft'])) * 100) : '',
            'elapsed' => (isset($job['progress']['printTime'])) ? formatTime($job['progress']['printTime']) : '',
            'left' => (isset($job['progress']['printTimeLeft'])) ? formatTime($job['progress']['printTimeLeft']) : '',
            'colorClass' => $colorClass,
	    'active' => $printer['active'] ?? true
        );
    }
}
    usort($printerData, fn($a, $b) => ($b['active'] ?? true) <=> ($a['active'] ?? true));
    return $printerData;
}

function getCachedData() {
    global $cacheFile;

    if (file_exists($cacheFile)) {
        $cacheTime = filemtime($cacheFile);
        $currentTime = time();
        if ($currentTime - $cacheTime < 60) { // 60 seconds = 1 minute
            return file_get_contents($cacheFile);
        }
    }

    return false; // Cache doesn't exist or is outdated
}

function saveDataToCache($data) {
    global $cacheFile;

    file_put_contents($cacheFile, $data);
}

$cachedData = getCachedData();

if ($cachedData) {
    // If cached data is available and not expired, use it
    header('Content-Type: application/json');
    echo $cachedData;
} else {
    $printerData = fetchPrinterData();
    saveDataToCache(json_encode($printerData));

    header('Content-Type: application/json');
    echo json_encode($printerData);
}
?>

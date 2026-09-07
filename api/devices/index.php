<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');


if (empty($_SESSION['loggedIn'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

function fetchJson(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'IPSW-Master/1.0 (PHP)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$body || $code < 200 || $code >= 300) return null;
    $d = json_decode($body, true);
    return is_array($d) ? $d : null;
}

$identifier = $_GET['id'] ?? null;

if ($identifier) {
    // Fetch specific device with its firmwares
    $device = fetchJson("https://api.ipsw.me/v4/device/" . urlencode($identifier) . "?type=ipsw");
    echo json_encode($device ?? ['error' => 'Device not found']);
} else {
    // List all devices with 6-hour caching
    $cacheFile = __DIR__ . '/../../data/devices_cache.json';
    $devices   = null;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
        $devices = json_decode(file_get_contents($cacheFile), true);
    }
    if (!is_array($devices) || empty($devices)) {
        $devices = fetchJson('https://api.ipsw.me/v4/devices');
        if (is_array($devices) && !empty($devices)) {
            if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0755, true);
            file_put_contents($cacheFile, json_encode($devices));
        }
    }
    echo json_encode($devices ?? []);
}


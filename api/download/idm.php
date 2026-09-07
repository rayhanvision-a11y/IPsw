<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['loggedIn'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$input = json_decode(file_get_contents('php://input'), true);
$settingsPath = __DIR__ . '/../../data/settings.json';
$historyPath  = __DIR__ . '/../../data/history.json';
$settings = file_exists($settingsPath) ? (json_decode(file_get_contents($settingsPath), true) ?? []) : [];

// Auto-detect IDMan.exe path on Windows
$defaultIdmPaths = [
    $settings['idmPath'] ?? '',
    'C:\\Program Files (x86)\\Internet Download Manager\\IDMan.exe',
    'C:\\Program Files\\Internet Download Manager\\IDMan.exe',
];
$idmPath = '';
foreach ($defaultIdmPaths as $p) {
    if (!empty($p) && file_exists($p)) {
        $idmPath = $p;
        break;
    }
}

$downloadDir = $settings['downloadDir'] ?? 'C:\\Users\\Public\\Downloads\\IPSWs';
if (!file_exists($downloadDir)) {
    @mkdir($downloadDir, 0777, true);
}

$items = $input['items'] ?? [];
if (empty($items)) {
    http_response_code(400);
    exit(json_encode(['error' => 'No items provided']));
}

$queued = [];
$history = file_exists($historyPath)
    ? (json_decode(file_get_contents($historyPath), true) ?? [])
    : [];

foreach ($items as $item) {
    $url      = $item['url']        ?? '';
    $filename = $item['filename']   ?? basename(parse_url($url, PHP_URL_PATH));
    $device   = $item['deviceName'] ?? 'Unknown Device';
    $version  = $item['version']    ?? '';
    $buildid  = $item['buildid']    ?? '';
    $filesize = $item['filesize']   ?? 0;

    if (!$url) continue;

    // Launch IDM CLI: IDMan.exe /d "URL" /p "savePath" /f "filename" /a
    if ($idmPath) {
        $cmd = sprintf(
            '"%s" /d "%s" /p "%s" /f "%s" /a',
            $idmPath,
            $url,
            $downloadDir,
            $filename
        );

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B \"\" $cmd", "r"));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }
    }

    $entry = [
        'id'            => 'idm_' . uniqid('', true),
        'filename'      => $filename,
        'deviceName'    => $device,
        'version'       => $version,
        'buildid'       => $buildid,
        'filesize'      => $filesize,
        'downloadedBytes' => 0,
        'status'        => 'IDM Queued',
        'filePath'      => $downloadDir . DIRECTORY_SEPARATOR . $filename,
        'url'           => $url,
        'startTime'     => date('c'),
        'endTime'       => date('c'),
    ];
    array_unshift($history, $entry);
    $queued[] = $entry;
}

if ($idmPath && !empty($queued)) {
    // Trigger IDM queue start/refresh
    $startCmd = sprintf('"%s" /s', $idmPath);
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen("start /B \"\" $startCmd", "r"));
    }
}

file_put_contents($historyPath, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode(['success' => true, 'queued' => count($queued), 'items' => $queued]);

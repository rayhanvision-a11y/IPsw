<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['loggedIn'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$settingsPath = __DIR__ . '/../../data/settings.json';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $settings = file_exists($settingsPath) ? (json_decode(file_get_contents($settingsPath), true) ?? []) : [];
    unset($settings['password']);
    foreach ($settings['gmailAccounts'] ?? [] as &$acc) {
        if (!empty($acc['appPassword'])) $acc['appPassword'] = '••••••••';
    }
    echo json_encode($settings);

} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid data']));
    }
    $settings = file_exists($settingsPath) ? (json_decode(file_get_contents($settingsPath), true) ?? []) : [];

    $allowed = ['downloadDir', 'idmPath', 'useIdmByDefault', 'autoCheckMinutes',
                'desktopNotifications', 'emailAlertsEnabled'];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $input)) $settings[$key] = $input[$key];
    }
    if (!empty($input['username'])) $settings['username'] = $input['username'];
    if (!empty($input['password'])) $settings['password'] = $input['password'];

    if (isset($input['gmailAccounts'])) {
        $existingMap = [];
        foreach ($settings['gmailAccounts'] ?? [] as $acc) {
            $existingMap[$acc['id']] = $acc;
        }
        $updated = [];
        foreach ($input['gmailAccounts'] as $acc) {
            $id = $acc['id'] ?? '';
            if (isset($acc['appPassword']) && $acc['appPassword'] === '••••••••' && isset($existingMap[$id])) {
                $acc['appPassword'] = $existingMap[$id]['appPassword'];
            }
            $updated[] = $acc;
        }
        $settings['gmailAccounts'] = $updated;
    }

    file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode(['success' => true]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

<?php
@error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('session.cookie_path', '/');
if (session_status() === PHP_SESSION_NONE) @session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    exit(json_encode(['error' => 'Username and password are required.']));
}

$settingsPath = __DIR__ . '/../../data/settings.json';
$settings = file_exists($settingsPath) ? (json_decode(file_get_contents($settingsPath), true) ?? []) : [];

$validUser = $settings['username'] ?? 'admin';
$validPass = $settings['password'] ?? 'vision2026';

if ($username === $validUser && $password === $validPass) {
    $_SESSION['loggedIn'] = true;
    $_SESSION['username'] = $username;
    echo json_encode(['success' => true]);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid username or password.']);
}

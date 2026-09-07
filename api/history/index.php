<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['loggedIn'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$historyPath = __DIR__ . '/../../data/history.json';
$method = $_SERVER['REQUEST_METHOD'];

function readHistory(string $path): array {
    if (!file_exists($path)) return [];
    return json_decode(file_get_contents($path), true) ?? [];
}

function writeHistory(string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if ($method === 'GET') {
    echo json_encode(readHistory($historyPath));

} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['id'])) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid data']));
    }
    $history = readHistory($historyPath);
    array_unshift($history, $input);
    writeHistory($historyPath, $history);
    echo json_encode(['success' => true, 'count' => count($history)]);

} elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        $history = readHistory($historyPath);
        $history = array_values(array_filter($history, fn($h) => $h['id'] !== $id));
        writeHistory($historyPath, $history);
        echo json_encode(['success' => true]);
    } else {
        writeHistory($historyPath, []);
        echo json_encode(['success' => true, 'cleared' => true]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

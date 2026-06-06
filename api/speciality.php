<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'id query param is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$safeId = preg_replace('/[^a-zA-Z0-9_\-\x{0400}-\x{04FF}]/u', '', $id);
if ($safeId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filePath = __DIR__ . '/../results/' . $safeId . '.json';
if (!is_file($filePath)) {
    http_response_code(404);
    echo json_encode(['error' => 'speciality file not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = @file_get_contents($filePath);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'failed to read speciality file'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo $raw;

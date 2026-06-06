<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$resultsDir = realpath(__DIR__ . '/../results');
if ($resultsDir === false || !is_dir($resultsDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'results directory not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = glob($resultsDir . '/unified_*.json');
if ($files === false) {
    http_response_code(500);
    echo json_encode(['error' => 'failed to read results files'], JSON_UNESCAPED_UNICODE);
    exit;
}

$specialities = [];
foreach ($files as $filePath) {
    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        continue;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        continue;
    }

    $fileName = basename($filePath);
    $id = preg_replace('/\.json$/', '', $fileName);
    $metadata = is_array($json['metadata'] ?? null) ? $json['metadata'] : [];
    $stats = is_array($json['statistics'] ?? null) ? $json['statistics'] : [];

    $specialities[] = [
        'id' => $id,
        'title' => $metadata['search_query'] ?? $id,
        'fileName' => $fileName,
        'totalVacancies' => (int) ($stats['total'] ?? 0),
        'generatedAt' => $metadata['generated_at'] ?? null,
    ];
}

usort($specialities, static function (array $a, array $b): int {
    return strcmp((string) $a['title'], (string) $b['title']);
});

echo json_encode($specialities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
$CONFIG = [
    // Список специальностей для парсинга (ДОБАВЬТЕ СВОИ)
    'professions' => [
        'Программист',
        'Медицинская сестра'
    ],
    'log_file' => __DIR__ . '/auto_parser.log',
    'results_dir' => __DIR__ . '/results',
    'temp_dir' => __DIR__ . '/temp_results',
    'delay_between_professions' => 300,  // Секунд между специальностями
];

// Создаём нужные директории
if (!file_exists($CONFIG['results_dir'])) mkdir($CONFIG['results_dir'], 0777, true);
if (!file_exists($CONFIG['temp_dir'])) mkdir($CONFIG['temp_dir'], 0777, true);

function log_message($message, $level = 'INFO') {
    global $CONFIG;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
    file_put_contents($CONFIG['log_file'], $logEntry, FILE_APPEND | LOCK_EX);
    echo $logEntry;
}

function run_parser_for_profession($profession) {
    global $CONFIG;
    
    log_message(">>> ЗАПУСК ПАРСИНГА ДЛЯ: {$profession}");
    
    // Передаём поисковый запрос через echo | php
    $command = "echo " . escapeshellarg($profession) . " | php " . escapeshellarg(__DIR__ . '/run_parser.php') . " 2>&1";
    
    log_message("Выполняется команда: " . $command);
    
    $output = shell_exec($command);
    echo $output;
    
    // Проверяем результат
    $safe_profession = preg_replace('/[^a-zA-Zа-яА-Я0-9_-]/u', '_', $profession);
    $possible_files = glob(__DIR__ . "/results/unified_{$safe_profession}_*.json");
    
    if (!empty($possible_files)) {
        $latest_file = $possible_files[0];
        foreach ($possible_files as $f) {
            if (filemtime($f) > filemtime($latest_file)) $latest_file = $f;
        }
        log_message("✓ УСПЕШНО: {$profession} -> " . basename($latest_file));
        return true;
    }
    
    log_message("✗ НЕ УДАЛОСЬ: {$profession} - файл не создан", 'WARNING');
    return false;
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "АВТОМАТИЧЕСКИЙ ПАРСИНГ\n";
echo "Дата запуска: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 70) . "\n";

$all_results = [];
$total_start = microtime(true);

foreach ($CONFIG['professions'] as $index => $profession) {
    echo "\n" . str_repeat("-", 70) . "\n";
    echo "Обработка " . ($index + 1) . "/" . count($CONFIG['professions']) . ": {$profession}\n";
    echo str_repeat("-", 70) . "\n";
    
    $start_time = microtime(true);
    
    // Запускаем парсинг для текущей специальности
    $success = run_parser_for_profession($profession);
    $all_results[$profession] = $success;
    
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);
    echo "Время выполнения: {$execution_time} сек.\n";
    
    // Пауза между специальностями (если не последняя)
    if ($index < count($CONFIG['professions']) - 1 && $CONFIG['delay_between_professions'] > 0) {
        echo "Пауза {$CONFIG['delay_between_professions']} секунд перед следующей специальностью...\n";
        sleep($CONFIG['delay_between_professions']);
    }
}

$total_end = microtime(true);
$total_time = round($total_end - $total_start, 2);

// Итоги
echo "\n" . str_repeat("=", 70) . "\n";
echo "ИТОГИ ВЫПОЛНЕНИЯ:\n";
echo str_repeat("=", 70) . "\n";

foreach ($all_results as $profession => $success) {
    echo "{$profession}: " . ($success ? "✅ УСПЕШНО" : "❌ ОШИБКА") . "\n";
}

echo "\nОбщее время: {$total_time} сек.\n";
echo str_repeat("=", 70) . "\n";

log_message("Автоматический парсинг полностью завершён");
?>
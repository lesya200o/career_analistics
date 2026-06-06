<?php

$CONFIG = [
    'parser_1' => __DIR__ . '/parser_trudvsem.php',
    'parser_2' => __DIR__ . '/parser_hh.php',
    'log_file' => __DIR__ . '/parsers_run.log',
    'results_dir' => __DIR__ . '/results',
    'temp_dir' => __DIR__ . '/temp_results',
    // AI настройки
    'python_path' => "C:\\Users\\User\\AppData\\Local\\Programs\\Python\\Python313\\python.exe",
    'python_script' => __DIR__ . "/ai_analiz.py",
    'timeout' => 300
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

function execute_command($command, $timeout) {
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    
    $process = proc_open($command, $descriptorspec, $pipes);
    
    if (!is_resource($process)) {
        return ['stdout' => '', 'stderr' => 'Failed to start process', 'exit_code' => -1];
    }
    
    fclose($pipes[0]);
    
    $stdout = '';
    $stderr = '';
    $startTime = time();
    
    while (true) {
        $status = proc_get_status($process);
        
        if (!$status['running']) break;
        
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        
        if (time() - $startTime > $timeout) {
            proc_terminate($process, 9);
            break;
        }
        
        usleep(100000);
    }
    
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exitCode = proc_close($process);
    
    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
}

// Запуск парсера и получение данных из его JSON-файла
function run_parser_and_get_data($parser_path, $parser_name, $search_query, $file_prefix) {
    global $CONFIG;
    
    log_message("=== ЗАПУСК ПАРСЕРА: {$parser_name} ===");
    
    if (!file_exists($parser_path)) {
        log_message("Файл парсера не найден: {$parser_path}", 'ERROR');
        return [];
    }
    
    // Удаляем старые временные файлы этого парсера
    $old_files = glob($CONFIG['temp_dir'] . "/{$file_prefix}_*.json");
    foreach ($old_files as $f) {
        if (file_exists($f)) unlink($f);
    }
    
    // Запускаем парсер
    $command = "php " . escapeshellarg($parser_path);
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    
    $process = proc_open($command, $descriptorspec, $pipes);
    if (!is_resource($process)) {
        log_message("Не удалось запустить процесс", 'ERROR');
        return [];
    }
    
    fwrite($pipes[0], $search_query . "\n");
    fclose($pipes[0]);
    
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    
    echo $output;
    
    if ($errors) log_message("STDERR: " . $errors, 'WARNING');
    if ($exitCode !== 0) {
        log_message("Парсер {$parser_name} завершился с ошибкой (код: {$exitCode})", 'ERROR');
        return [];
    }
    
    // Ищем свежий файл, созданный парсером
    $possible_files = glob(__DIR__ . "/{$file_prefix}_*.json");
    if (empty($possible_files)) {
        log_message("Не найден выходной JSON-файл для парсера {$parser_name}", 'ERROR');
        return [];
    }
    
    // Берём самый новый файл
    $latest_file = $possible_files[0];
    foreach ($possible_files as $f) {
        if (filemtime($f) > filemtime($latest_file)) $latest_file = $f;
    }
    
    // Читаем данные
    $data = json_decode(file_get_contents($latest_file), true);
    if (!$data || !isset($data['vacancies'])) {
        log_message("Ошибка чтения JSON или нет вакансий", 'ERROR');
        return [];
    }
    
    // Перемещаем файл в temp_dir
    $target = $CONFIG['temp_dir'] . '/' . basename($latest_file);
    rename($latest_file, $target);
    
    log_message("Парсер {$parser_name} вернул " . count($data['vacancies']) . " вакансий");
    return $data['vacancies'];
}

function run_ai_analysis($unified_file, $search_query) {
    global $CONFIG;
    
    log_message("Запуск AI анализа объединённого файла...");
    
    // Читаем объединённый файл
    $data = json_decode(file_get_contents($unified_file), true);
    if (!$data || !isset($data['vacancies'])) {
        log_message("Ошибка чтения объединённого файла", 'ERROR');
        return "ОШИБКА: Не удалось прочитать данные";
    }
    
    // Собираем описания вакансий для AI анализа
    $descriptions = [];
    foreach ($data['vacancies'] as $vacancy) {
        if (!empty($vacancy['Описание'])) {
            $descriptions[] = $vacancy['Описание'];
        }
    }
    
    if (empty($descriptions)) {
        log_message("Нет описаний для AI анализа", 'WARNING');
        return "ОШИБКА: Нет описаний вакансий для анализа";
    }
    
    log_message("Подготовлено " . count($descriptions) . " описаний для AI анализа");
    
    // Создаём временный файл для AI
    $tempFile = $CONFIG['temp_dir'] . '/ai_input_' . date('Ymd_His') . '.json';
    $ai_data = [
        'vacancies_texts' => $descriptions,
        'search_query' => $search_query,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    file_put_contents($tempFile, json_encode($ai_data, JSON_UNESCAPED_UNICODE));
    
    // Запускаем Python скрипт
    $command = sprintf('%s %s %s 2>&1',
        escapeshellarg($CONFIG['python_path']),
        escapeshellarg($CONFIG['python_script']),
        escapeshellarg($tempFile)
    );
    
    log_message("Выполняется AI анализ...");
    $result = execute_command($command, $CONFIG['timeout']);
    
    // Удаляем временный файл
    unlink($tempFile);
    
    if ($result['exit_code'] !== 0 || empty(trim($result['stdout']))) {
        log_message("AI анализ не удался: " . substr($result['stderr'], 0, 200), 'ERROR');
        return "ОШИБКА: Не удалось выполнить AI анализ";
    }
    
    $output = $result['stdout'];
    if (!mb_check_encoding($output, 'UTF-8')) {
        $output = mb_convert_encoding($output, 'UTF-8', 'Windows-1251');
    }
    
    log_message("AI анализ успешно завершён");
    return trim($output);
}

// Расчёт средней зарплаты и опыта по массиву вакансий
function calculate_combined_statistics($vacancies) {
    $salaries = [];
    $experience_years = [];
    
    foreach ($vacancies as $v) {
        // Зарплата
        if (!empty($v['Зарплата']) && $v['Зарплата'] !== "Не указана") {
            preg_match_all('/(\d+(?:\s*\d+)*)/u', $v['Зарплата'], $matches);
            if (!empty($matches[1])) {
                $clean = intval(preg_replace('/\s+/', '', $matches[1][0]));
                if ($clean > 0) $salaries[] = $clean;
            }
        }
        
        // Опыт работы
        $exp_text = strtolower(trim($v['Опыт работы'] ?? ''));
        if ($exp_text && $exp_text !== 'не указан') {
            if (preg_match('/от\s*(\d+(?:\.\d+)?)/u', $exp_text, $m)) {
                $experience_years[] = floatval($m[1]);
            } elseif (preg_match('/(\d+(?:\.\d+)?)\s*[-–]\s*(\d+(?:\.\d+)?)/u', $exp_text, $m)) {
                $experience_years[] = (floatval($m[1]) + floatval($m[2])) / 2;
            } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:год|года|лет)/u', $exp_text, $m)) {
                $experience_years[] = floatval($m[1]);
            } elseif (preg_match('/(\d+(?:\.\d+)?)/u', $exp_text, $m) && strlen($exp_text) < 15) {
                $experience_years[] = floatval($m[1]);
            } elseif (strpos($exp_text, 'без опыта') !== false || strpos($exp_text, 'нет опыта') !== false) {
                $experience_years[] = 0;
            }
        }
    }
    
    return [
        'total' => count($vacancies),
        'avg_salary' => !empty($salaries) ? round(array_sum($salaries) / count($salaries)) : 0,
        'min_salary' => !empty($salaries) ? min($salaries) : 0,
        'max_salary' => !empty($salaries) ? max($salaries) : 0,
        'avg_experience' => !empty($experience_years) ? round(array_sum($experience_years) / count($experience_years), 1) : 0
    ];
}

// Сохранение объединённого результата с AI анализом
function save_unified_result($vacancies, $statistics, $ai_analysis, $search_query) {
    global $CONFIG;
    
    $timestamp = date('Ymd_His');
    $safe_query = preg_replace('/[^a-zA-Zа-яА-Я0-9_-]/u', '_', $search_query);
    $filename = $CONFIG['results_dir'] . "/unified_{$safe_query}_{$timestamp}.json";
    
    // Очищаем AI анализ от лишних сообщений
    $cleaned_ai = '';
    if (!empty($ai_analysis) && strpos($ai_analysis, 'ОШИБКА') === false) {
        $lines = explode("\n", $ai_analysis);
        $found = false;
        foreach ($lines as $line) {
            if (strpos($line, 'Профессиональные навыки') !== false) {
                $found = true;
            }
            if ($found) {
                // Убираем символы \r
                $cleaned_ai .= str_replace("\r", "", $line) . "\n";
            }
        }
        $cleaned_ai = trim($cleaned_ai);
    } else {
        $cleaned_ai = $ai_analysis;
    }
    
    $data = [
        "metadata" => [
            "search_query" => $search_query,
            "region" => "Ростовская область",
            "generated_at" => date('Y-m-d H:i:s'),
            "total_vacancies" => count($vacancies),
            "sources" => ["trudvsem", "hh"],
            "parser_version" => "unified"
        ],
        "statistics" => $statistics,
        "ai_analysis" => [
            "raw_output" => $cleaned_ai
        ],
        "vacancies" => $vacancies
    ];
    
    file_put_contents($filename, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    log_message("Сохранён объединённый файл: {$filename}");
    return $filename;
}


echo "ОБЪЕДИНЁННЫЙ ЗАПУСК ПАРСЕРОВ С AI АНАЛИЗОМ\n";

echo "Введите поисковый запрос: ";
$search_query = trim(fgets(STDIN));
if (empty($search_query)) {
    echo "Поисковый запрос не может быть пустым!\n";
    exit(1);
}

echo "\nПоисковый запрос: '{$search_query}'\n";
echo "Будут запущены: Работа России → HH.ru → Объединение → AI анализ\n\n";

$start_time = microtime(true);

// Запускаем парсеры
$vacancies1 = run_parser_and_get_data($CONFIG['parser_1'], "Работа России (Trudvsem)", $search_query, 'trudvsem');
echo "\n" . str_repeat("-", 70) . "\n\n";
$vacancies2 = run_parser_and_get_data($CONFIG['parser_2'], "HH.ru", $search_query, 'hh');

// Объединяем вакансии
$all_vacancies = [];
foreach ($vacancies1 as $v) {
    $v['source'] = 'trudvsem';
    $all_vacancies[] = $v;
}
foreach ($vacancies2 as $v) {
    $v['source'] = 'hh';
    $all_vacancies[] = $v;
}

if (empty($all_vacancies)) {
    echo "\nНет вакансий для объединения. Завершение.\n";
    exit(1);
}

echo "\nВсего найдено вакансий: " . count($all_vacancies) . "\n";
echo "   - Работа России: " . count($vacancies1) . "\n";
echo "   - HH.ru: " . count($vacancies2) . "\n";

// Сохраняем временный объединённый файл для AI анализа
$temp_unified = $CONFIG['temp_dir'] . '/temp_unified_' . date('Ymd_His') . '.json';
file_put_contents($temp_unified, json_encode(['vacancies' => $all_vacancies], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// Запускаем AI анализ
echo "ЗАПУСК AI АНАЛИЗА\n";
echo "Это может занять 1-3 минуты\n";

$ai_result = run_ai_analysis($temp_unified, $search_query);

// Удаляем временный файл
unlink($temp_unified);

// Расчёт статистики
$statistics = calculate_combined_statistics($all_vacancies);

// Сохраняем финальный результат с AI анализом
$final_file = save_unified_result($all_vacancies, $statistics, $ai_result, $search_query);

$end_time = microtime(true);
$execution_time = round($end_time - $start_time, 2);

// Вывод итогов
echo "ИТОГИ ВЫПОЛНЕНИЯ:\n";
echo "Поисковый запрос: '{$search_query}'\n";
echo "Всего вакансий: {$statistics['total']}\n";
echo "  - Работа России: " . count($vacancies1) . "\n";
echo "  - HH.ru: " . count($vacancies2) . "\n";
echo "Средняя зарплата: " . number_format($statistics['avg_salary'], 0, '', ' ') . " ₽\n";
echo "Средний опыт: {$statistics['avg_experience']} лет\n";

if (!empty($ai_result) && strpos($ai_result, 'ОШИБКА') === false) {
    echo "\nРЕЗУЛЬТАТЫ AI АНАЛИЗА:\n";
    echo str_repeat("-", 70) . "\n";
    echo $ai_result . "\n";
    echo str_repeat("-", 70) . "\n";
} else {
    echo "\nAI анализ не выполнен или вернул ошибку\n";
}

echo "Результат сохранён: {$final_file}\n";
echo "Общее время: {$execution_time} сек.\n";
echo str_repeat("=", 70) . "\n";

log_message("Объединение завершено. Всего вакансий: {$statistics['total']}");
?>
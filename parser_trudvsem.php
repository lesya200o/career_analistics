<?php
// ============================================
// КОНФИГУРАЦИЯ
// ============================================
$CONFIG = [
    'search_query' => "",
    'region_code' => "6100000000000",
    'region_name' => "Ростовская область",
    'log_file' => __DIR__ . '/parser.log',
    'debug' => true
];

// ============================================
// КЛАСС ЛОГГЕРА
// ============================================
class Logger {
    private string $logFile;
    private bool $debug;
    
    public function __construct(string $logFile, bool $debug = false) {
        $this->logFile = $logFile;
        $this->debug = $debug;
    }
    
    public function info(string $message): void {
        $this->log('INFO', $message);
    }
    
    public function error(string $message): void {
        $this->log('ERROR', $message);
    }
    
    public function debug(string $message): void {
        if ($this->debug) {
            $this->log('DEBUG', $message);
        }
    }
    
    private function log(string $level, string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        if ($level === 'ERROR') {
            fwrite(STDERR, $logMessage);
        }
    }
}

// ============================================
// КЛАСС ДЛЯ РАБОТЫ С CURL
// ============================================
class HttpClient {
    private ?\CurlHandle $handle = null;
    
    public function __construct() {
        $this->handle = curl_init();
    }
    
    public function __destruct() {
        $this->handle = null;
    }
    
    public function setOptions(array $options): void {
        curl_setopt_array($this->handle, $options);
    }
    
    public function execute(): array {
        $response = curl_exec($this->handle);
        $httpCode = curl_getinfo($this->handle, CURLINFO_HTTP_CODE);
        
        $result = [
            'body' => $response,
            'http_code' => $httpCode,
            'error' => '',
            'info' => curl_getinfo($this->handle)
        ];
        
        if ($response === false) {
            $result['error'] = curl_error($this->handle);
        }
        
        return $result;
    }
}

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================

function clean_html(?string $text): string {
    if (empty($text)) return "";
    $text = strip_tags($text);
    $text = str_replace('&nbsp;', ' ', $text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    return trim($text);
}

function get_random_user_agent(): string {
    $agents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 Edg/119.0.0.0'
    ];
    return $agents[array_rand($agents)];
}

function format_salary_amount($amount): string {
    if ($amount === null || $amount === "") return "";
    return number_format((float)$amount, 0, '', ' ');
}

// ============================================
// ФУНКЦИИ РАБОТЫ С ВАКАНСИЯМИ
// ============================================

function get_vacancy_details(array $vacancy, Logger $logger): ?array {
    try {
        $vacancy_data = isset($vacancy['vacancy']) && is_array($vacancy['vacancy']) ? $vacancy['vacancy'] : $vacancy;
        
        $name = clean_html($vacancy_data['job-name'] ?? $vacancy_data['name'] ?? $vacancy['name'] ?? "Без названия");
        
        // Обработка зарплаты
        $salary_info = "Не указана";
        $salary_raw = null;
        if (isset($vacancy_data['salary']) && is_array($vacancy_data['salary'])) {
            $salary_raw = $vacancy_data['salary'];
        }
        
        if ($salary_raw && is_array($salary_raw)) {
            $salary_from = $salary_raw['from'] ?? null;
            $salary_to = $salary_raw['to'] ?? null;
            $salary_currency = $salary_raw['currency'] ?? "₽";
            
            $salary_parts = [];
            if ($salary_from !== null && $salary_from !== "") {
                $salary_parts[] = "от " . format_salary_amount($salary_from);
            }
            if ($salary_to !== null && $salary_to !== "") {
                $salary_parts[] = "до " . format_salary_amount($salary_to);
            }
            
            if (!empty($salary_parts)) {
                $salary_info = implode(" ", $salary_parts) . " {$salary_currency}";
            }
        } elseif (isset($vacancy_data['salary']) && !is_array($vacancy_data['salary'])) {
            $salary_info = $vacancy_data['salary'] . " ₽";
        }
        
        $description = clean_html($vacancy_data['duty'] ?? $vacancy_data['description'] ?? "");
        
        $experience = "Не указан";
        if (isset($vacancy_data['requirement']['experience'])) {
            $experience = $vacancy_data['requirement']['experience'];
            if (empty($experience) || $experience === "") {
                $experience = "Не указан";
            }
        } elseif (!empty($vacancy_data['experience'])) {
            $experience = $vacancy_data['experience'];
        }
        
        $skills_list = [];
        
        // 1. Из поля skills (если есть)
        if (!empty($vacancy_data['skills']) && is_array($vacancy_data['skills'])) {
            foreach ($vacancy_data['skills'] as $skill) {
                if (!empty($skill) && !in_array(strtolower($skill), ["", "не указано"])) {
                    $skills_list[] = clean_html($skill);
                }
            }
        }
        
        // 2. ИЗ ОПИСАНИЯ ВАКАНСИИ 
        if (!empty($description)) {
            $skill_patterns = [
                '/знание\s+([^,.!;]+)/iu',
                '/владение\s+([^,.!;]+)/iu',
                '/опыт\s+работы\s+с\s+([^,.!;]+)/iu',
                '/умение\s+([^,.!;]+)/iu',
                '/навыки?\s+([^,.!;]+)/iu',
                '/уверенный\s+пользователь\s+([^,.!;]+)/iu',
                '/работа\s+в\s+([^,.!;]+)/iu',
                '/знакомство\s+с\s+([^,.!;]+)/iu',
                '/\b(1С|Excel|Word|Outlook|Photoshop|AutoCAD|Python|Java|PHP|SQL|Oracle|SAP)\b/iu',
                '/\b(бухгалтерия|налоговый учет|кадровый учет|складской учет)\b/iu',
                '/\b(английский|немецкий|французский)\s+язык\b/iu',
                '/\b(коммуникабельность|ответственность|стрессоустойчивость|обучаемость|внимательность)\b/iu',
                '/\b(работа\s+в\s+команде|самоорганизация|пунктуальность|инициативность)\b/iu',
                '/\b(аналитическое\s+мышление|системное\s+мышление|креативность)\b/iu'
            ];
            
            foreach ($skill_patterns as $pattern) {
                if (preg_match_all($pattern, $description, $matches)) {
                    foreach ($matches[1] ?? $matches[0] as $match) {
                        $skill = trim($match);
                        if (strlen($skill) > 2 && strlen($skill) < 100) {
                            $skills_list[] = strtolower($skill);
                        }
                    }
                }
            }
        }
        
        // 3. ИЗ ТРЕБОВАНИЙ
        $requirement_fields = ['qualification', 'requirements', 'requirement'];
        foreach ($requirement_fields as $field) {
            if (!empty($vacancy_data[$field])) {
                $text = is_array($vacancy_data[$field]) 
                    ? json_encode($vacancy_data[$field]) 
                    : clean_html($vacancy_data[$field]);
                
                if (!empty($text)) {
                    $keywords = [
                        '1С', 'Excel', 'Word', 'бухгалтерия', 'налоговый', 'отчетность',
                        'коммуникабельность', 'ответственность', 'стрессоустойчивость', 
                        'команда', 'обучаемость', 'внимательность'
                    ];
                    
                    foreach ($keywords as $keyword) {
                        if (stripos($text, $keyword) !== false) {
                            $skills_list[] = mb_strtolower($keyword);
                        }
                    }
                }
            }
        }
        
        // Очистка и нормализация навыков
        $skills_list = array_map(function($skill) {
            $skill = trim(preg_replace('/\s+/', ' ', $skill));
            $skill = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $skill);
            return $skill;
        }, $skills_list);
        
        // Удаляем пустые и слишком короткие
        $skills_list = array_filter($skills_list, function($skill) {
            return strlen($skill) > 2 && !in_array(mb_strtolower($skill), ['', 'не указано', 'нет', 'отсутствуют']);
        });
        
        // Удаляем дубликаты
        $skills_list = array_unique($skills_list);
        
        $skills_text = !empty($skills_list) ? implode(", ", array_slice($skills_list, 0, 15)) : "Не указаны";
        
        return [
            "Вакансия" => $name,
            "Зарплата" => $salary_info,
            "Опыт работы" => $experience,
            "Навыки" => $skills_text,
            "Описание" => mb_substr($description, 0, 1000)
        ];
    } catch (Exception $e) {
        $logger->error("Ошибка парсинга вакансии: " . $e->getMessage());
        return null;
    }
}

function calculate_average_experience(array $vacancies): float {
    $experience_years = [];
    
    foreach ($vacancies as $vacancy) {
        $exp_text = strtolower(trim($vacancy["Опыт работы"] ?? ""));
        if (empty($exp_text) || $exp_text === "не указан") continue;
        
        if (preg_match('/от\s*(\d+(?:\.\d+)?)/u', $exp_text, $matches)) {
            $experience_years[] = floatval($matches[1]);
        } elseif (preg_match('/до\s*(\d+(?:\.\d+)?)/u', $exp_text, $matches)) {
            $experience_years[] = round(floatval($matches[1]) * 0.7, 1);
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*[-–]\s*(\d+(?:\.\d+)?)/u', $exp_text, $matches)) {
            $experience_years[] = round((floatval($matches[1]) + floatval($matches[2])) / 2, 1);
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:год|года|лет)/u', $exp_text, $matches)) {
            $experience_years[] = floatval($matches[1]);
        } elseif (strpos($exp_text, "не требуется") !== false || strpos($exp_text, "без опыта") !== false) {
            $experience_years[] = 0;
        } elseif (preg_match('/(\d+(?:\.\d+)?)/u', $exp_text, $matches) && strlen($exp_text) < 10) {
            $experience_years[] = floatval($matches[1]);
        }
    }
    
    return !empty($experience_years) ? round(array_sum($experience_years) / count($experience_years), 1) : 0;
}

function calculate_average_salary(array $vacancies): int {
    $salaries = [];
    
    foreach ($vacancies as $vacancy) {
        $salary_text = $vacancy["Зарплата"] ?? "";
        if ($salary_text === "Не указана") continue;
        
        preg_match_all('/(\d+(?:[\s\d]*)?)/u', $salary_text, $numbers);
        
        $salary_value = null;
        foreach ($numbers[1] as $num_str) {
            $num_str = trim($num_str);
            if (!empty($num_str)) {
                $clean_num = preg_replace('/\s+/', '', $num_str);
                if (is_numeric($clean_num)) {
                    $salary_value = intval($clean_num);
                    break;
                }
            }
        }
        
        if ($salary_value !== null && $salary_value > 0) {
            $salaries[] = $salary_value;
        }
    }
    
    return !empty($salaries) ? intval(round(array_sum($salaries) / count($salaries))) : 0;
}

// ============================================
// ФУНКЦИЯ СОХРАНЕНИЯ В JSON
// ============================================
function save_to_json(array $results, array $statistics, string $search_query, string $filename): string {
    $data = [
        "metadata" => [
            "search_query" => $search_query,
            "region" => "Ростовская область",
            "region_code" => "6100000000000",
            "generated_at" => date('Y-m-d H:i:s'),
            "total_vacancies" => count($results),
            "parser_version" => "3.0-php85"
        ],
        "statistics" => $statistics,
        "vacancies" => $results
    ];
    
    file_put_contents($filename, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $filename;
}

// ============================================
// ОСНОВНАЯ ФУНКЦИЯ
// ============================================

function get_vacancies(array $config, Logger $logger): void {
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "ПАРСЕР ВАКАНСИЙ TRUDVSEM.RU (v3.0 PHP 8.5+)\n";
    echo str_repeat("=", 70) . "\n\n";
    
    echo "Введите название вакансии для поиска: ";
    $search_query = trim(fgets(STDIN));
    $search_query = trim($search_query, "\"' ");
    
    if (empty($search_query)) {
        echo "Название вакансии не может быть пустым!\n";
        $logger->error("Пустой поисковый запрос");
        return;
    }
    
    $logger->info("Начало парсинга: '{$search_query}'");
    
    $url = "http://opendata.trudvsem.ru/api/v1/vacancies/region/{$config['region_code']}";
    $params = http_build_query([
        "text" => $search_query,
        "limit" => 25,
        "offset" => 0
    ]);
    
    echo "\nПоиск вакансий...\n";
    echo "Регион: {$config['region_name']}\n";
    echo "Запрос: '{$search_query}'\n\n";
    
    $http = new HttpClient();
    $http->setOptions([
        CURLOPT_URL => $url . "?" . $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => get_random_user_agent(),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = $http->execute();
    
    if (!empty($response['error'])) {
        echo "Ошибка CURL: {$response['error']}\n";
        $logger->error("CURL ошибка: {$response['error']}");
        return;
    }
    
    if ($response['http_code'] != 200) {
        echo "Ошибка HTTP: {$response['http_code']}\n";
        $logger->error("HTTP ошибка: {$response['http_code']}");
        return;
    }
    
    $logger->info("HTTP ответ: {$response['http_code']}");
    
    $data = json_decode($response['body'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Ошибка парсинга JSON: " . json_last_error_msg() . "\n";
        $logger->error("JSON ошибка: " . json_last_error_msg());
        return;
    }
    
    $vacancies = $data['results']['vacancies'] ?? [];
    
    echo "Получено вакансий из API: " . count($vacancies) . "\n";
    $logger->info("Получено вакансий: " . count($vacancies));
    
    $results = [];
    $search_lower = mb_strtolower(trim($search_query));
    
    echo "\nФильтрация и обработка...\n";
    echo str_repeat("-", 70) . "\n";
    
    foreach ($vacancies as $item) {
        $vacancy_data = $item['vacancy'] ?? $item;
        $job_name = mb_strtolower(trim(clean_html($vacancy_data['job-name'] ?? $vacancy_data['name'] ?? "")));
        
        if (mb_strpos($job_name, $search_lower) !== false) {
            $result = get_vacancy_details($item, $logger);
            
            if ($result) {
                $results[] = $result;
                echo "✓ [" . count($results) . "] " . mb_substr($result['Вакансия'], 0, 50) . 
                     " - ЗП: " . mb_substr($result['Зарплата'], 0, 30) . "\n";
            }
        }
    }
    
    if (empty($results)) {
        echo "\nНе найдено вакансий по запросу '{$search_query}'\n";
        $logger->error("Вакансии не найдены");
        return;
    }
    
    echo "\nНайдено и обработано: " . count($results) . " вакансий\n";
    $logger->info("Обработано вакансий: " . count($results));
    
    $avg_exp = calculate_average_experience($results);
    $avg_sal = calculate_average_salary($results);
    
    $salaries = [];
    foreach ($results as $v) {
        if ($v['Зарплата'] !== "Не указана") {
            preg_match_all('/(\d+(?:\s*\d+)*)/u', $v['Зарплата'], $m);
            if (!empty($m[1])) {
                $salaries[] = intval(preg_replace('/\s+/', '', $m[1][0]));
            }
        }
    }
    
    $statistics = [
        'total' => count($results),
        'avg_experience' => $avg_exp,
        'avg_salary' => $avg_sal,
        'min_salary' => !empty($salaries) ? min($salaries) : 0,
        'max_salary' => !empty($salaries) ? max($salaries) : 0
    ];
    
    echo "\nСТАТИСТИКА:\n";
    echo str_repeat("-", 70) . "\n";
    echo sprintf("%-25s: %d\n", "Всего вакансий", $statistics['total']);
    echo sprintf("%-25s: %.1f лет\n", "Средний опыт", $statistics['avg_experience']);
    echo sprintf("%-25s: %s ₽\n", "Средняя зарплата", number_format($statistics['avg_salary'], 0, '', ' '));
    echo sprintf("%-25s: %s ₽\n", "Минимальная зарплата", number_format($statistics['min_salary'], 0, '', ' '));
    echo sprintf("%-25s: %s ₽\n", "Максимальная зарплата", number_format($statistics['max_salary'], 0, '', ' '));
    
    $timestamp = date('Ymd_His');
    $safe_query = preg_replace('/[^a-zA-Zа-яА-Я0-9_-]/u', '_', $search_query);
    $base = "trudvsem_{$safe_query}_{$timestamp}";
    
    $json_file = save_to_json($results, $statistics, $search_query, $base . ".json");
    
    echo "\nРЕЗУЛЬТАТЫ СОХРАНЕНЫ:\n";
    echo str_repeat("-", 70) . "\n";
    echo "JSON данные: {$json_file}\n";
    
    $logger->info("Результаты сохранены: {$json_file}");
}

try {
    $logger = new Logger($CONFIG['log_file'], $CONFIG['debug']);
    
    $logger->info("Запуск парсера (PHP " . PHP_VERSION . ")");
    
    get_vacancies($CONFIG, $logger);
    
    $logger->info("Парсер успешно завершил работу");
    
} catch (Exception $e) {
    echo "\nКРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    if (isset($logger)) {
        $logger->error("Критическая ошибка: " . $e->getMessage());
    }
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "🏁 РАБОТА ЗАВЕРШЕНА\n";
echo str_repeat("=", 70) . "\n";
?>
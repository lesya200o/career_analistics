<?php
$CONFIG = [
    'search_query' => "",
    'region_id' => 76,
    'region_name' => "Ростовская область",
    'per_page' => 25,
    'log_file' => __DIR__ . '/hh_parser.log',
    'debug' => true,
    'client_id' => " ",
    'client_secret' => " "
];

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

class HttpClient {
    private $handle = null;
    
    public function __construct() {
        $this->handle = curl_init();
    }
    
    public function __destruct() {
        if ($this->handle) {
            curl_close($this->handle);
        }
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
            'error' => curl_error($this->handle)
        ];
        
        return $result;
    }
    
    public function reset(): void {
        curl_reset($this->handle);
    }
}

class HHAuth {
    private string $clientId;
    private string $clientSecret;
    private ?string $accessToken = null;
    private ?int $tokenExpiry = null;
    private Logger $logger;
    private HttpClient $http;
    
    public function __construct(string $clientId, string $clientSecret, Logger $logger) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->logger = $logger;
        $this->http = new HttpClient();
    }
    
    public function getAccessToken(): ?string {
        $url = "https://api.hh.ru/token";
        
        $data = http_build_query([
            "grant_type" => "client_credentials",
            "client_id" => $this->clientId,
            "client_secret" => $this->clientSecret
        ]);
        
        $headers = [
            "Content-Type: application/x-www-form-urlencoded",
            "User-Agent: MyResearchApp/1.0 (exemple@gmail.com)"
        ];
        
        $this->http->reset();
        $this->http->setOptions([
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = $this->http->execute();
        
        if ($response['http_code'] == 200) {
            $tokenData = json_decode($response['body'], true);
            $this->accessToken = $tokenData['access_token'] ?? null;
            $expiresIn = $tokenData['expires_in'] ?? 7200;
            $this->tokenExpiry = time() + $expiresIn;
            $this->logger->info("Токен успешно получен");
            return $this->accessToken;
        } else {
            $this->logger->error("Ошибка получения токена: {$response['http_code']}");
            return null;
        }
    }
    
    public function ensureValidToken(): bool {
        if (!$this->accessToken || !$this->tokenExpiry) {
            return $this->getAccessToken() !== null;
        }
        
        if (time() >= $this->tokenExpiry) {
            $this->logger->info("Токен истек, обновляем...");
            return $this->getAccessToken() !== null;
        }
        
        return true;
    }
    
    public function getAuthHeaders(): array {
        $headers = [
            "User-Agent: MyResearchApp/1.0 (exemple@gmail.com)",
            "Accept: application/json"
        ];
        
        if ($this->accessToken) {
            $headers[] = "Authorization: Bearer " . $this->accessToken;
        }
        
        return $headers;
    }
}

function clean_html(?string $text): string {
    if (empty($text)) return "";
    $text = strip_tags($text);
    $text = str_replace('&nbsp;', ' ', $text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    return trim($text);
}

function format_salary_amount($amount): string {
    if ($amount === null || $amount === "") return "";
    return number_format((float)$amount, 0, '', ' ');
}

function calculate_experience_years(?string $experienceText): float {
    if (!$experienceText || $experienceText == "Не указан") {
        return 0;
    }
    
    $expLower = strtolower($experienceText);
    
    if (strpos($expLower, 'нет опыта') !== false || strpos($expLower, 'без опыта') !== false) {
        return 0;
    }
    
    if (preg_match('/от\s*(\d+(?:\.\d+)?)/u', $expLower, $matches)) {
        return (float)$matches[1];
    }
    
    if (preg_match('/(\d+(?:\.\d+)?)\s*[-–]\s*(\d+(?:\.\d+)?)/u', $expLower, $matches)) {
        $minYears = (float)$matches[1];
        $maxYears = (float)$matches[2];
        return ($minYears + $maxYears) / 2;
    }
    
    if (preg_match('/(?:более|больше)\s*(\d+(?:\.\d+)?)/u', $expLower, $matches)) {
        return (float)$matches[1] + 1;
    }
    
    if (preg_match('/(\d+(?:\.\d+)?)/u', $expLower, $matches)) {
        return (float)$matches[1];
    }
    
    return 0;
}

function get_vacancy_details(array $vacancy, HHAuth $auth, Logger $logger): ?array {
    try {
        $url = "https://api.hh.ru/vacancies/" . $vacancy['id'];
        
        $http = new HttpClient();
        $http->setOptions([
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $auth->getAuthHeaders(),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        // Задержка для соблюдения rate limits
        sleep(1);
        
        $response = $http->execute();
        
        if ($response['http_code'] == 200) {
            $data = json_decode($response['body'], true);
            
            $areaInfo = $data['area'] ?? [];
            $regionId = (string)($areaInfo['id'] ?? '');
            
            $rostovIds = ["76", "67", "1566", "66", "65", "1685", "64", "1257",
                          "1615", "1552", "1606", "1566"];
            
            if (!in_array($regionId, $rostovIds)) {
                return null;
            }
            
            // Очистка описания и проверка на пустоту
            $description = $data['description'] ?? "";
            $cleanDescription = clean_html($description);
            
            if (empty($cleanDescription)) {
                return null;
            }
            
            // Обработка зарплаты
            $salaryData = $data['salary'] ?? null;
            $salaryInfo = "Не указана";
            if ($salaryData && is_array($salaryData)) {
                $salaryParts = [];
                if (!empty($salaryData['from'])) {
                    $salaryParts[] = "от " . format_salary_amount($salaryData['from']);
                }
                if (!empty($salaryData['to'])) {
                    $salaryParts[] = "до " . format_salary_amount($salaryData['to']);
                }
                if (!empty($salaryParts)) {
                    $salaryInfo = implode(" ", $salaryParts);
                    if (!empty($salaryData['currency'])) {
                        $salaryInfo .= " " . $salaryData['currency'];
                    }
                }
            }
            
            // Опыт работы
            $experienceText = $data['experience']['name'] ?? "Не указан";
            $experienceText = calculate_experience_years($experienceText);
            
            // Навыки
            $keySkills = $data['key_skills'] ?? [];
            $skills = [];
            foreach ($keySkills as $skill) {
                if (!empty($skill['name'])) {
                    $skills[] = $skill['name'];
                }
            }
            $skillsText = !empty($skills) ? implode(", ", $skills) : "Нет навыков";
            
            return [
                "Вакансия" => $data['name'] ?? "Без названия",
                "Зарплата" => $salaryInfo,
                "Опыт работы" => $experienceText,
                "Навыки" => $skillsText,
                "Описание" => $cleanDescription
            ];
        } elseif ($response['http_code'] == 429) {
            $logger->debug("Слишком много запросов, ждем...");
            sleep(5);
            return null;
        } else {
            $logger->debug("HTTP {$response['http_code']} для вакансии {$vacancy['id']}");
            return null;
        }
    } catch (Exception $e) {
        $logger->error("Ошибка парсинга вакансии: " . $e->getMessage());
        return null;
    }
}

function calculate_average_experience(array $vacancies): float {
    $experience_years = [];
    
    foreach ($vacancies as $vacancy) {
        $exp_years = $vacancy['Опыт работы'] ?? 0;
        if ($exp_years > 0) {
            $experience_years[] = $exp_years;
        }
    }
    
    return !empty($experience_years) ? round(array_sum($experience_years) / count($experience_years), 1) : 0;
}

function calculate_average_salary(array $vacancies): int {
    $salaries = [];
    
    foreach ($vacancies as $vacancy) {
        $salary_str = $vacancy['Зарплата'] ?? "";
        if ($salary_str !== "Не указана") {
            preg_match_all('/(\d+(?:[\s\d]*)?)/u', $salary_str, $numbers);
            foreach ($numbers[1] as $num_str) {
                $clean_num = trim(preg_replace('/\s+/', '', $num_str));
                if (is_numeric($clean_num)) {
                    $salaries[] = intval($clean_num);
                    break;
                }
            }
        }
    }
    
    return !empty($salaries) ? intval(round(array_sum($salaries) / count($salaries))) : 0;
}

function save_to_json(array $results, array $statistics, string $search_query, int $region_id, string $region_name, string $filename): string {
    $data = [
        "metadata" => [
            "search_query" => $search_query,
            "region" => $region_name,
            "region_id" => $region_id,
            "generated_at" => date('Y-m-d H:i:s'),
            "total_vacancies" => count($results),
            "parser_version" => "hh-php"
        ],
        "statistics" => $statistics,
        "vacancies" => $results
    ];
    
    file_put_contents($filename, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $filename;
}

function get_vacancies(array $config, Logger $logger): void {
    echo "ПАРСЕР ВАКАНСИЙ HH.RU\n";
    
    echo "Введите название вакансии для поиска: ";
    $search_query = trim(fgets(STDIN));
    $search_query = trim($search_query, "\"' ");
    
    if (empty($search_query)) {
        echo "Название вакансии не может быть пустым!\n";
        $logger->error("Пустой поисковый запрос");
        return;
    }
    
    $logger->info("Начало парсинга: '{$search_query}'");
    
    // Авторизация
    $auth = new HHAuth($config['client_id'], $config['client_secret'], $logger);
    
    if (!$auth->ensureValidToken()) {
        echo "Не удалось получить токен доступа. Проверьте client_id и client_secret\n";
        $logger->error("Не удалось получить токен");
        return;
    }
    
    $url = "https://api.hh.ru/vacancies";
    $params = http_build_query([
        "text" => '"' . $search_query . '"',
        "area" => $config['region_id'],
        "per_page" => $config['per_page'],
        "only_with_salary" => "false"
    ]);
    
    echo "\nПоиск вакансий...\n";
    echo "Регион: {$config['region_name']}\n";
    echo "Запрос: '{$search_query}'\n\n";
    
    $http = new HttpClient();
    $http->setOptions([
        CURLOPT_URL => $url . "?" . $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $auth->getAuthHeaders(),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
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
    
    $vacancies = $data['items'] ?? [];
    $total_found = $data['found'] ?? 0;
    
    echo "Найдено вакансий по запросу: $total_found\n";
    echo "Загружено на этой странице: " . count($vacancies) . "\n";
    $logger->info("Получено вакансий: " . count($vacancies));
    
    if (empty($vacancies)) {
        echo "\nВакансии не найдены\n";
        $logger->error("Вакансии не найдены");
        return;
    }
    
    $results = [];
    
    echo "\nОбработка вакансий...\n";
    
    foreach ($vacancies as $item) {
        $result = get_vacancy_details($item, $auth, $logger);
        if ($result) {
            $results[] = $result;
            echo "✓ [" . count($results) . "] " . mb_substr($result['Вакансия'], 0, 50) . 
                 " - ЗП: " . mb_substr($result['Зарплата'], 0, 30) . "\n";
        }
    }
    
    if (empty($results)) {
        echo "\nНе найдено вакансий\n";
        $logger->error("Вакансии не найдены");
        return;
    }
    
    echo "\nНайдено и обработано: " . count($results) . " вакансий\n";
    $logger->info("Обработано вакансий: " . count($results));
    
    // Расчет статистики
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
    echo sprintf("%-25s: %d\n", "Всего вакансий", $statistics['total']);
    echo sprintf("%-25s: %.1f лет\n", "Средний опыт", $statistics['avg_experience']);
    echo sprintf("%-25s: %s ₽\n", "Средняя зарплата", number_format($statistics['avg_salary'], 0, '', ' '));
    echo sprintf("%-25s: %s ₽\n", "Минимальная зарплата", number_format($statistics['min_salary'], 0, '', ' '));
    echo sprintf("%-25s: %s ₽\n", "Максимальная зарплата", number_format($statistics['max_salary'], 0, '', ' '));
    
    // Сохранение в JSON
    $timestamp = date('Ymd_His');
    $safe_query = preg_replace('/[^a-zA-Zа-яА-Я0-9_-]/u', '_', $search_query);
    $base = "hh_{$safe_query}_{$timestamp}";
    
    $json_file = save_to_json($results, $statistics, $search_query, $config['region_id'], $config['region_name'], $base . ".json");
    
    echo "\nРЕЗУЛЬТАТЫ СОХРАНЕНЫ:\n";
    echo "JSON данные: {$json_file}\n";
    
    $logger->info("Результаты сохранены: {$json_file}");
}

try {
    $logger = new Logger($CONFIG['log_file'], $CONFIG['debug']);
    
    $logger->info("Запуск парсера HH.ru (PHP " . PHP_VERSION . ")");
    
    get_vacancies($CONFIG, $logger);
    
    $logger->info("Парсер успешно завершил работу");
    
} catch (Exception $e) {
    echo "\nКРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    if (isset($logger)) {
        $logger->error("Критическая ошибка: " . $e->getMessage());
    }
}

echo "РАБОТА ЗАВЕРШЕНА\n";
?>

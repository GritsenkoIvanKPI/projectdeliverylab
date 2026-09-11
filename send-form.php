<?php
/**
 * Endpoint форми «Надіслати заявку» — приймає заявку зі сторінки
 * projectdeliverylab.com і пересилає її в Telegram.
 *
 * Токен живе в config.php: у git не потрапляє, браузеру не віддається.
 * Сторінка спілкується тільки з цим скриптом.
 *
 *   браузер  ->  send-form.php  ->  api.telegram.org
 *                      ^
 *                 config.php (токен, ніколи не комітиться)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Фатальна помилка (немає розширення, зламаний конфіг) інакше віддала б порожній
// 500. Перетворюємо її на JSON + рядок у лог.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log('send-form fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
});

/** Віддати JSON і зупинитись. */
function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- самодіагностика ---------- */

// GET /send-form.php?selftest=1 показує, чи здатен сервер обробити форму.
// Токен не друкується — тільки чи він є і яка в нього довжина.
if (isset($_GET['selftest'])) {
    $cfgPath = __DIR__ . '/config.php';
    $cfg     = is_file($cfgPath) ? @require $cfgPath : null;

    respond(200, [
        'ok'              => true,
        'php'             => PHP_VERSION,
        'php_ok'          => version_compare(PHP_VERSION, '7.4', '>='),
        'config_present'  => is_file($cfgPath),
        'config_is_array' => is_array($cfg),
        'token_present'   => is_array($cfg) && !empty($cfg['bot_token']) && strpos((string)$cfg['bot_token'], 'PASTE') !== 0,
        'token_length'    => is_array($cfg) ? strlen((string)($cfg['bot_token'] ?? '')) : 0,
        'chat_id'         => is_array($cfg) ? (string)($cfg['chat_id'] ?? '') : '',
        'ext_curl'        => function_exists('curl_init'),
        'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
        'can_send'        => function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
        'ext_mbstring'    => function_exists('mb_substr'),
        'ext_json'        => function_exists('json_encode'),
        'can_write_tmp'   => is_writable(sys_get_temp_dir()),
    ]);
}

/* ---------- метод ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

/* ---------- конфіг ---------- */

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    error_log('send-form: config.php is missing');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

$config = require $configPath;
if (!is_array($config)) {
    error_log('send-form: config.php did not return an array');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

$token  = trim((string)($config['bot_token'] ?? ''));
$chatId = trim((string)($config['chat_id'] ?? ''));

if ($token === '' || $chatId === '' || strpos($token, 'PASTE') === 0 || strpos($chatId, 'PASTE') === 0) {
    error_log('send-form: bot_token or chat_id not filled in');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

/* ---------- вхідні дані ---------- */

$raw   = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

/** Обрізати пробіли, схлопнути повтори, обмежити довжину. */
function field(array $src, string $key, int $max): string
{
    $value = (string)($src[$key] ?? '');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

$name   = field($input, 'name', 120);
$phone  = field($input, 'phone', 80);
$source = field($input, 'source', 20);
$page   = field($input, 'page', 200);
$trap   = field($input, 'website', 200); // пастка: людина її не бачить
$agree  = !empty($input['agree']);

/* ---------- пастка для ботів ---------- */

// Бот, який заповнює всі поля, отримує «успіх», але нічого не надсилається —
// тож у нього немає сигналу, за яким повторювати спробу.
if ($trap !== '') {
    respond(200, ['ok' => true]);
}

/* ---------- валідація ---------- */

$errors = [];

$nameLen = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
if ($nameLen < 2) {
    $errors['name'] = 'required';
}

// Поле підписане «Телефон або Telegram», тож приймаємо обидва:
// номер (від девʼяти цифр — щоб не відсікати запис без нуля чи з кодом країни)
// або нік/посилання Telegram.
$digits   = preg_replace('/\D+/', '', $phone) ?? '';
$isPhone  = strlen($digits) >= 9;
$isHandle = (bool)preg_match('~^@?[A-Za-z][A-Za-z0-9_]{4,31}$~', $phone)
         || (bool)preg_match('~(?:t\.me|telegram\.me)/[A-Za-z0-9_]{5,32}~i', $phone);

if (!$isPhone && !$isHandle) {
    $errors['phone'] = 'invalid';
}

// Захист від інʼєкції заголовків — ці значення потрапляють і в лист-копію.
if (preg_match('/[\r\n]/', $name . $phone)) {
    $errors['name'] = 'invalid';
}

// Згоду на обробку даних форма вимагає й на клієнті; перевіряємо ще раз,
// бо запит може прийти повз сторінку.
if (!$agree) {
    $errors['agree'] = 'required';
}

if ($errors) {
    respond(422, ['ok' => false, 'error' => 'validation_failed', 'fields' => $errors]);
}

/* ---------- ліміт частоти ---------- */

$limit = (int)($config['rate_limit_per_hour'] ?? 5);
if ($limit > 0) {
    $ip     = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = sys_get_temp_dir() . '/pdlab-form-' . hash('sha256', $ip) . '.txt';
    $now    = time();
    $hits   = [];

    if (is_file($bucket)) {
        $stored = json_decode((string)file_get_contents($bucket), true);
        if (is_array($stored)) {
            // лишаємо тільки звернення за останню годину
            $hits = array_values(array_filter(
                $stored,
                static fn($t) => is_int($t) && $t > $now - 3600
            ));
        }
    }

    if (count($hits) >= $limit) {
        respond(429, ['ok' => false, 'error' => 'rate_limited']);
    }

    $hits[] = $now;
    @file_put_contents($bucket, json_encode($hits), LOCK_EX);
}

/* ---------- складання повідомлення ---------- */

// Назву блока беремо зі словника, а не з того, що надіслав браузер, — інакше
// сюди можна було б підставити довільний текст.
$SOURCES = [
    'nav'          => 'Кнопка в шапці',
    'hero'         => 'Головний екран',
    'result'       => 'Що ви отримаєте',
    'program'      => 'Програма курсу',
    'prices'       => 'Тарифи',
    'author'       => 'Про автора',
    'testimonials' => 'Кейси учасників',
    'faq'          => 'FAQ',
    'zayavka'      => 'Сама форма',
    'modal'        => 'Вікно відео',
    'menu'         => 'Мобільне меню',
];
$sourceLabel = $SOURCES[$source] ?? '—';

/** Екранування для parse_mode=HTML у Telegram. */
function tg(string $text): string
{
    return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$lines   = [];
$lines[] = '<b>🎓 Нова заявка — Project Delivery Lab</b>';
$lines[] = '';
$lines[] = '<b>Імʼя:</b> ' . tg($name);
// <code> у Telegram копіюється одним дотиком — зручно набирати номер
$lines[] = '<b>Контакт:</b> <code>' . tg($phone) . '</code>';
$lines[] = '';
$lines[] = '<i>Звідки: ' . tg($sourceLabel) . '</i>';

if ($page !== '') {
    $lines[] = '<i>Сторінка: ' . tg($page) . '</i>';
}

$lines[] = '<i>' . tg(date('d.m.Y H:i')) . '</i>';

$text = implode("\n", $lines);

/* ---------- відправка ---------- */

$payload = [
    'chat_id'                  => $chatId,
    'text'                     => $text,
    'parse_mode'               => 'HTML',
    'disable_web_page_preview' => true,
];

/**
 * POST form-encoded. Через cURL, а якщо розширення немає — через потік:
 * на частині тарифів ext-curl не встановлений.
 *
 * @return array{body: ?string, status: int, error: string}
 */
function httpPost(string $url, array $fields): array
{
    $body = http_build_query($fields);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        return [
            'body'   => $response === false ? null : (string)$response,
            'status' => $status,
            'error'  => $error,
        ];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['body' => null, 'status' => 0, 'error' => 'no curl and allow_url_fopen is off'];
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                             . 'Content-Length: ' . strlen($body) . "\r\n",
            'content'       => $body,
            'timeout'       => 15,
            // читаємо тіло навіть на 4xx, щоб залогувати причину
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $status   = 0;

    // $http_response_header створює сам stream wrapper
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }

    return [
        'body'   => $response === false ? null : (string)$response,
        'status' => $status,
        'error'  => $response === false ? 'stream request failed' : '',
    ];
}

$apiBase = rtrim((string)($config['api_base'] ?? 'https://api.telegram.org'), '/');
$sent = httpPost($apiBase . '/bot' . $token . '/sendMessage', $payload);

if ($sent['body'] === null || $sent['status'] !== 200) {
    // Причину пишемо в лог, але не віддаємо назовні: текст помилки API може
    // цитувати запит, а токен не має потрапити у відповідь браузеру.
    error_log('send-form: telegram failed, http ' . $sent['status'] . ' ' . $sent['error']
        . ' ' . substr((string)$sent['body'], 0, 300));
    respond(502, ['ok' => false, 'error' => 'delivery_failed']);
}

$result = json_decode((string)$sent['body'], true);
if (!is_array($result) || empty($result['ok'])) {
    error_log('send-form: telegram rejected the message: ' . substr((string)$sent['body'], 0, 300));
    respond(502, ['ok' => false, 'error' => 'delivery_failed']);
}

/* ---------- необовʼязкова копія на пошту ---------- */

$notify = trim((string)($config['notify_email'] ?? ''));
if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
    $body = "Імʼя: $name\nКонтакт: $phone\nЗвідки: $sourceLabel\nСторінка: $page\n";
    @mail(
        $notify,
        'Нова заявка — Project Delivery Lab',
        $body,
        "Content-Type: text/plain; charset=UTF-8\r\nFrom: web@" . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    );
}

respond(200, ['ok' => true]);

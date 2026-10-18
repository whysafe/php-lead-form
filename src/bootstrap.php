<?php
declare(strict_types=1);

/*
 * Общая инициализация для token.php и send.php:
 * скрытие ошибок, логирование, загрузка конфига, сессия и JSON-ответы.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);

const LEAD_FORM_ROOT = __DIR__ . '/..';

$GLOBALS['lead_log_file'] = LEAD_FORM_ROOT . '/data/error.log';

function log_error(string $message): void
{
    $line = sprintf("[%s] %s\n", date('c'), str_replace(["\r", "\n"], ' ', $message));
    @file_put_contents($GLOBALS['lead_log_file'], $line, FILE_APPEND | LOCK_EX);
}

function json_response(int $status, array $payload, array $headers = []): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    log_error(sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    json_response(500, ['ok' => false, 'error' => 'server_error', 'message' => 'Не удалось отправить заявку. Попробуйте позже.']);
});

function load_config(): array
{
    $path = getenv('LEAD_FORM_CONFIG') ?: LEAD_FORM_ROOT . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Config not found: ' . $path . ' (copy config.example.php to config.php)');
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Config must return an array');
    }
    $defaults = [
        'salt' => '',
        'timezone' => 'Europe/Moscow',
        'data_dir' => LEAD_FORM_ROOT . '/data',
        'min_fill_seconds' => 3,
        'rate_limit' => ['max' => 5, 'window' => 600],
        'trust_proxy' => false,
        'telegram' => ['enabled' => false, 'bot_token' => '', 'chat_id' => ''],
        'mail' => ['enabled' => false, 'to' => '', 'from' => '', 'subject' => 'Новая заявка с сайта'],
    ];
    $config = array_replace_recursive($defaults, $config);
    date_default_timezone_set((string)$config['timezone']);
    if (strlen((string)$config['salt']) < 16 || $config['salt'] === 'change-me-to-a-long-random-string') {
        throw new RuntimeException('Set a unique salt (16+ chars) in config');
    }
    $dir = rtrim((string)$config['data_dir'], '/');
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException('Cannot create data dir');
    }
    $config['data_dir'] = $dir;
    $GLOBALS['lead_log_file'] = $dir . '/error.log';
    return $config;
}

function start_session(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('leadform_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

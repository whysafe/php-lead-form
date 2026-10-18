<?php
declare(strict_types=1);

/* Валидация, ограничение частоты, хранение и доставка заявок. */

const NAME_MAX = 80;
const CONTACT_MAX = 100;
const MESSAGE_MAX = 2000;

/** Убирает управляющие символы, обрезает пробелы и длину. */
function clean_text(mixed $value, int $max, bool $multiline = false): string
{
    if (!is_string($value)) {
        return '';
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $value = str_replace("\r\n", "\n", $value);
    $pattern = $multiline ? '/[^\P{C}\n]+/u' : '/\p{C}+/u';
    $value = preg_replace($pattern, ' ', $value) ?? '';
    if (!$multiline) {
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    } else {
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';
    }
    return mb_substr(trim($value), 0, $max);
}

function is_valid_contact(string $contact): bool
{
    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        return true;
    }
    if (preg_match('/^@[A-Za-z0-9_]{5,32}$/', $contact)) {
        return true;
    }
    if (preg_match('/^\+?[\d\s()\-]{10,20}$/', $contact)) {
        $digits = preg_replace('/\D/', '', $contact) ?? '';
        return strlen($digits) >= 10 && strlen($digits) <= 15;
    }
    return false;
}

/** @return array{0: array, 1: array<string,string>} очищенные данные и ошибки по полям */
function validate_lead(array $input): array
{
    $data = [
        'name' => clean_text($input['name'] ?? '', NAME_MAX),
        'contact' => clean_text($input['contact'] ?? '', CONTACT_MAX),
        'message' => clean_text($input['message'] ?? '', MESSAGE_MAX, true),
    ];
    $errors = [];
    if (mb_strlen($data['name']) < 2) {
        $errors['name'] = 'Укажите имя (от 2 символов).';
    }
    if ($data['contact'] === '') {
        $errors['contact'] = 'Укажите телефон, email или Telegram.';
    } elseif (!is_valid_contact($data['contact'])) {
        $errors['contact'] = 'Похоже на опечатку: нужен телефон, email или @username.';
    }
    $consent = $input['consent'] ?? '';
    if (!in_array($consent, ['1', 'on', 'yes', 'true'], true)) {
        $errors['consent'] = 'Без согласия на обработку персональных данных отправить заявку нельзя.';
    }
    return [$data, $errors];
}

function client_ip(array $config): string
{
    if ($config['trust_proxy'] && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Хэш IP с солью: по нему можно считать заявки, но нельзя восстановить адрес. */
function ip_hash(string $ip, string $salt): string
{
    return hash_hmac('sha256', $ip, $salt);
}

/**
 * Атомарно проверяет лимит и, если он не исчерпан, засчитывает попытку.
 * @return int 0, если можно отправлять, иначе секунды до освобождения слота.
 */
function rate_limit_hit(string $file, string $key, int $max, int $window): int
{
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Cannot open rate limit file');
    }
    try {
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $all = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
        if (!is_array($all)) {
            $all = [];
        }
        $now = time();
        foreach ($all as $k => $times) {
            $all[$k] = array_values(array_filter((array)$times, static fn($t) => is_int($t) && $t > $now - $window));
            if (!$all[$k]) {
                unset($all[$k]);
            }
        }
        $mine = $all[$key] ?? [];
        if (count($mine) >= $max) {
            $retry = max(1, min($mine) + $window - $now);
        } else {
            $mine[] = $now;
            $all[$key] = $mine;
            $retry = 0;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($all));
        fflush($fp);
        return $retry;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function save_lead(string $file, array $lead): void
{
    $line = json_encode($lead, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Cannot write leads file');
    }
}

function format_lead_text(array $lead): string
{
    $lines = [
        'Новая заявка',
        'Имя: ' . $lead['name'],
        'Контакт: ' . $lead['contact'],
    ];
    if ($lead['message'] !== '') {
        $lines[] = 'Сообщение: ' . $lead['message'];
    }
    $lines[] = 'Время: ' . $lead['created_at'];
    return implode("\n", $lines);
}

function send_telegram(array $tg, array $lead): bool
{
    $ch = curl_init('https://api.telegram.org/bot' . $tg['bot_token'] . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $tg['chat_id'],
            'text' => format_lead_text($lead),
            'disable_web_page_preview' => 'true',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($code !== 200 || empty($json['ok'])) {
        log_error('Telegram send failed: HTTP ' . $code . ' ' . $err . ' ' . (is_string($body) ? mb_substr($body, 0, 200) : ''));
        return false;
    }
    return true;
}

function send_mail(array $mail, array $lead): bool
{
    $strip = static fn(string $s): string => str_replace(["\r", "\n"], '', $s);
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($mail['from'] !== '') {
        $headers[] = 'From: ' . $strip($mail['from']);
    }
    if (filter_var($lead['contact'], FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $strip($lead['contact']);
    }
    $subject = '=?UTF-8?B?' . base64_encode($strip((string)$mail['subject'])) . '?=';
    $ok = mail($strip($mail['to']), $subject, format_lead_text($lead), implode("\r\n", $headers));
    if (!$ok) {
        log_error('mail() returned false');
    }
    return $ok;
}

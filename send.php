<?php
declare(strict_types=1);

/*
 * Приём заявки. Порядок проверок:
 * метод -> CSRF -> honeypot -> время заполнения -> валидация -> лимит по IP -> запись -> доставка.
 */

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lead.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'error' => 'method_not_allowed'], ['Allow' => 'POST']);
}

$config = load_config();
start_session();

$sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$expected = $_SESSION['csrf_token'] ?? '';
if (!is_string($sent) || $expected === '' || !hash_equals($expected, $sent)) {
    json_response(403, ['ok' => false, 'error' => 'csrf', 'message' => 'Сессия устарела. Обновите страницу и попробуйте снова.']);
}

// Ловушка для ботов: отвечаем как при успехе, но ничего не сохраняем и не отправляем.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    json_response(200, ['ok' => true, 'message' => 'Спасибо! Заявка отправлена.']);
}

$startedAt = (float)($_SESSION['form_started_at'] ?? 0);
if ($startedAt <= 0 || microtime(true) - $startedAt < (float)$config['min_fill_seconds']) {
    json_response(422, ['ok' => false, 'error' => 'too_fast', 'message' => 'Форма отправлена слишком быстро. Подождите пару секунд и попробуйте ещё раз.']);
}

[$data, $errors] = validate_lead($_POST);
if ($errors) {
    json_response(422, ['ok' => false, 'error' => 'validation', 'errors' => $errors]);
}

$hash = ip_hash(client_ip($config), (string)$config['salt']);
$retry = rate_limit_hit(
    $config['data_dir'] . '/ratelimit.json',
    $hash,
    (int)$config['rate_limit']['max'],
    (int)$config['rate_limit']['window']
);
if ($retry > 0) {
    json_response(429, ['ok' => false, 'error' => 'rate_limit', 'message' => 'Слишком много заявок. Попробуйте через несколько минут.'], ['Retry-After' => (string)$retry]);
}

$lead = [
    'id' => bin2hex(random_bytes(8)),
    'created_at' => date('c'),
    'name' => $data['name'],
    'contact' => $data['contact'],
    'message' => $data['message'],
    'consent' => true,
    'ip_hash' => substr($hash, 0, 16),
];
save_lead($config['data_dir'] . '/leads.jsonl', $lead);

// Заявка уже сохранена, поэтому сбой доставки только логируется.
$channels = [
    'telegram' => static fn() => send_telegram($config['telegram'], $lead),
    'mail' => static fn() => send_mail($config['mail'], $lead),
];
foreach ($channels as $name => $send) {
    if (empty($config[$name]['enabled'])) {
        continue;
    }
    try {
        $send();
    } catch (Throwable $e) {
        log_error($name . ' delivery error: ' . $e->getMessage());
    }
}

json_response(200, ['ok' => true, 'message' => 'Спасибо! Заявка отправлена, скоро свяжемся.']);

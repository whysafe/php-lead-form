<?php
declare(strict_types=1);

/* Выдаёт CSRF-токен и запоминает момент открытия формы. */

require __DIR__ . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['ok' => false, 'error' => 'method_not_allowed'], ['Allow' => 'GET']);
}

load_config();
start_session();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$_SESSION['form_started_at'] = microtime(true);

json_response(200, ['ok' => true, 'token' => $_SESSION['csrf_token']]);

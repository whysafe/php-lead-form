<?php
// Скопируйте в config.php и заполните. config.php не попадает в git.
return [
    // Случайная строка от 16 символов: соль для хэша IP. Сгенерировать: php -r "echo bin2hex(random_bytes(24));"
    'salt' => 'change-me-to-a-long-random-string',

    // Часовой пояс для времени заявок
    'timezone' => 'Europe/Moscow',

    // Папка для заявок, лимитов и лога ошибок. Должна быть закрыта от веба.
    'data_dir' => __DIR__ . '/data',

    // Защита от ботов и флуда
    'min_fill_seconds' => 3,
    'rate_limit' => ['max' => 5, 'window' => 600], // 5 заявок за 10 минут с одного IP
    'trust_proxy' => false, // true, если сайт за Cloudflare или nginx и IP приходит в X-Forwarded-For

    // Уведомления в Telegram: токен от @BotFather, chat_id чата или канала
    'telegram' => [
        'enabled' => false,
        'bot_token' => '',
        'chat_id' => '',
    ],

    // Уведомления на почту через mail()
    'mail' => [
        'enabled' => false,
        'to' => 'owner@example.com',
        'from' => 'no-reply@example.com',
        'subject' => 'Новая заявка с сайта',
    ],
];

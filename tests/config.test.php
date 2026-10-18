<?php
// Конфиг для tests/run.sh: доставка выключена, данные во временной папке.
return [
    'salt' => 'test-salt-for-local-runs-only',
    'data_dir' => getenv('LEAD_FORM_DATA_DIR') ?: sys_get_temp_dir() . '/lead-form-test',
    'min_fill_seconds' => 3,
    'rate_limit' => ['max' => 5, 'window' => 600],
    'telegram' => ['enabled' => false],
    'mail' => ['enabled' => false],
];

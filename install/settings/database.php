<?php
/* settings/database.php */

return [
    'mysql' => [
        'dbdriver' => 'mysql',
        'username' => 'root',
        'password' => '',
        'dbname' => 'admintheme',
        'prefix' => 'app'
    ],
    'tables' => [
        'category' => 'category',
        'language' => 'language',
        'login_attempt' => 'login_attempt',
        'logs' => 'logs',
        'user' => 'user',
        'user_meta' => 'user_meta'
    ]
];

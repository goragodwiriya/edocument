<?php
/* settings/database.php */

return array(
    'mysql' => array(
        'dbdriver' => 'mysql',
        'username' => 'root',
        'password' => '',
        'dbname' => 'edocument',
        'prefix' => 'app'
    ),
    'tables' => array(
        'category' => 'category',
        'edocument' => 'edocument',
        'edocument_download' => 'edocument_download',
        'language' => 'language',
        'logs' => 'logs',
        'user' => 'user',
        'user_meta' => 'user_meta'
    )
);

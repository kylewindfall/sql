<?php

return [
    'mysql' => [
        'host' => env('HERD_MYSQL_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => (int) env('HERD_MYSQL_PORT', env('DB_PORT', 3306)),
        'username' => env('HERD_MYSQL_USERNAME', env('DB_USERNAME', 'root')),
        'password' => env('HERD_MYSQL_PASSWORD', env('DB_PASSWORD', '')),
        'socket' => env('HERD_MYSQL_SOCKET', env('DB_SOCKET', '')),
        'binary' => env('HERD_MYSQL_BINARY', '/Users/kylemcgowan/Library/Application Support/Herd/bin/mysql'),
        'dump_binary' => env('HERD_MYSQLDUMP_BINARY', '/Users/kylemcgowan/Library/Application Support/Herd/bin/mysqldump'),
        'page_size' => (int) env('HERD_MYSQL_PAGE_SIZE', 25),
    ],
];

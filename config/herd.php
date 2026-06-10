<?php

$homeDirectory = env('HOME');

$expandUserPath = static function (?string $path) use ($homeDirectory): ?string {
    if ($path === null || $path === '') {
        return $path;
    }

    if ($homeDirectory !== null && str_starts_with($path, '~/')) {
        return $homeDirectory.substr($path, 1);
    }

    return $path;
};

$defaultHerdBinDirectory = $homeDirectory !== null
    ? $homeDirectory.'/Library/Application Support/Herd/bin'
    : null;

return [
    'mysql' => [
        'host' => env('HERD_MYSQL_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => (int) env('HERD_MYSQL_PORT', env('DB_PORT', 3306)),
        'username' => env('HERD_MYSQL_USERNAME', env('DB_USERNAME', 'root')),
        'password' => env('HERD_MYSQL_PASSWORD', env('DB_PASSWORD', '')),
        'socket' => $expandUserPath(env('HERD_MYSQL_SOCKET', env('DB_SOCKET', ''))),
        'binary' => $expandUserPath(env('HERD_MYSQL_BINARY'))
            ?? ($defaultHerdBinDirectory !== null ? $defaultHerdBinDirectory.'/mysql' : 'mysql'),
        'dump_binary' => $expandUserPath(env('HERD_MYSQLDUMP_BINARY'))
            ?? ($defaultHerdBinDirectory !== null ? $defaultHerdBinDirectory.'/mysqldump' : 'mysqldump'),
        'page_size' => (int) env('HERD_MYSQL_PAGE_SIZE', 25),
    ],
];

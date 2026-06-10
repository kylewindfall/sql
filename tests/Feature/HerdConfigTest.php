<?php

test('herd config derives binary paths from the current user home directory', function () {
    $originalHome = getenv('HOME');
    $originalServerHome = $_SERVER['HOME'] ?? null;
    $originalBinary = getenv('HERD_MYSQL_BINARY');
    $originalDumpBinary = getenv('HERD_MYSQLDUMP_BINARY');

    putenv('HOME=/tmp/herd-config-home');
    putenv('HERD_MYSQL_BINARY');
    putenv('HERD_MYSQLDUMP_BINARY');

    $_ENV['HOME'] = '/tmp/herd-config-home';
    $_SERVER['HOME'] = '/tmp/herd-config-home';
    unset($_ENV['HERD_MYSQL_BINARY'], $_ENV['HERD_MYSQLDUMP_BINARY']);
    unset($_SERVER['HERD_MYSQL_BINARY'], $_SERVER['HERD_MYSQLDUMP_BINARY']);

    $config = require config_path('herd.php');

    expect($config['mysql']['binary'])->toBe('/tmp/herd-config-home/Library/Application Support/Herd/bin/mysql');
    expect($config['mysql']['dump_binary'])->toBe('/tmp/herd-config-home/Library/Application Support/Herd/bin/mysqldump');

    if ($originalHome === false) {
        putenv('HOME');
        unset($_ENV['HOME']);
        unset($_SERVER['HOME']);
    } else {
        putenv("HOME={$originalHome}");
        $_ENV['HOME'] = $originalHome;
        $_SERVER['HOME'] = $originalServerHome ?? $originalHome;
    }

    if ($originalBinary === false) {
        putenv('HERD_MYSQL_BINARY');
        unset($_ENV['HERD_MYSQL_BINARY']);
        unset($_SERVER['HERD_MYSQL_BINARY']);
    } else {
        putenv("HERD_MYSQL_BINARY={$originalBinary}");
        $_ENV['HERD_MYSQL_BINARY'] = $originalBinary;
        $_SERVER['HERD_MYSQL_BINARY'] = $originalBinary;
    }

    if ($originalDumpBinary === false) {
        putenv('HERD_MYSQLDUMP_BINARY');
        unset($_ENV['HERD_MYSQLDUMP_BINARY']);
        unset($_SERVER['HERD_MYSQLDUMP_BINARY']);
    } else {
        putenv("HERD_MYSQLDUMP_BINARY={$originalDumpBinary}");
        $_ENV['HERD_MYSQLDUMP_BINARY'] = $originalDumpBinary;
        $_SERVER['HERD_MYSQLDUMP_BINARY'] = $originalDumpBinary;
    }
});

test('herd config expands home-relative binary overrides', function () {
    $originalHome = getenv('HOME');
    $originalServerHome = $_SERVER['HOME'] ?? null;
    $originalBinary = getenv('HERD_MYSQL_BINARY');
    $originalDumpBinary = getenv('HERD_MYSQLDUMP_BINARY');

    putenv('HOME=/tmp/herd-config-home');
    putenv('HERD_MYSQL_BINARY=~/bin/mysql');
    putenv('HERD_MYSQLDUMP_BINARY=~/bin/mysqldump');

    $_ENV['HOME'] = '/tmp/herd-config-home';
    $_SERVER['HOME'] = '/tmp/herd-config-home';
    $_ENV['HERD_MYSQL_BINARY'] = '~/bin/mysql';
    $_ENV['HERD_MYSQLDUMP_BINARY'] = '~/bin/mysqldump';
    $_SERVER['HERD_MYSQL_BINARY'] = '~/bin/mysql';
    $_SERVER['HERD_MYSQLDUMP_BINARY'] = '~/bin/mysqldump';

    $config = require config_path('herd.php');

    expect($config['mysql']['binary'])->toBe('/tmp/herd-config-home/bin/mysql');
    expect($config['mysql']['dump_binary'])->toBe('/tmp/herd-config-home/bin/mysqldump');

    if ($originalHome === false) {
        putenv('HOME');
        unset($_ENV['HOME']);
        unset($_SERVER['HOME']);
    } else {
        putenv("HOME={$originalHome}");
        $_ENV['HOME'] = $originalHome;
        $_SERVER['HOME'] = $originalServerHome ?? $originalHome;
    }

    if ($originalBinary === false) {
        putenv('HERD_MYSQL_BINARY');
        unset($_ENV['HERD_MYSQL_BINARY']);
        unset($_SERVER['HERD_MYSQL_BINARY']);
    } else {
        putenv("HERD_MYSQL_BINARY={$originalBinary}");
        $_ENV['HERD_MYSQL_BINARY'] = $originalBinary;
        $_SERVER['HERD_MYSQL_BINARY'] = $originalBinary;
    }

    if ($originalDumpBinary === false) {
        putenv('HERD_MYSQLDUMP_BINARY');
        unset($_ENV['HERD_MYSQLDUMP_BINARY']);
        unset($_SERVER['HERD_MYSQLDUMP_BINARY']);
    } else {
        putenv("HERD_MYSQLDUMP_BINARY={$originalDumpBinary}");
        $_ENV['HERD_MYSQLDUMP_BINARY'] = $originalDumpBinary;
        $_SERVER['HERD_MYSQLDUMP_BINARY'] = $originalDumpBinary;
    }
});

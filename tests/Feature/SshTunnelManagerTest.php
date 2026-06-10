<?php

use App\Models\DatabaseConnection;
use App\Services\Herd\SshTunnelManager;

test('ssh tunnel manager derives a stable local port and control socket path', function () {
    $connection = DatabaseConnection::factory()->make(['id' => 12]);
    $manager = app(SshTunnelManager::class);

    expect($manager->localPort($connection))->toBe(43012);
    expect($manager->controlSocketPath($connection))
        ->toEndWith('/database-connection-12.sock');
});

test('ssh tunnel manager rejects missing rsa key paths', function () {
    $connection = DatabaseConnection::factory()->make([
        'id' => 2,
        'private_key_path' => '/tmp/definitely-missing-rsa-key',
    ]);

    expect(fn () => app(SshTunnelManager::class)->ensure($connection))
        ->toThrow(RuntimeException::class, 'RSA private key not found');
});

test('ssh tunnel manager expands home-relative rsa key paths before validation', function () {
    $originalHome = getenv('HOME');
    $originalServerHome = $_SERVER['HOME'] ?? null;

    putenv('HOME=/tmp/herd-ssh-home');
    $_ENV['HOME'] = '/tmp/herd-ssh-home';
    $_SERVER['HOME'] = '/tmp/herd-ssh-home';

    $connection = DatabaseConnection::factory()->make([
        'id' => 3,
        'private_key_path' => '~/.ssh/id_rsa',
    ]);

    expect(fn () => app(SshTunnelManager::class)->ensure($connection))
        ->toThrow(RuntimeException::class, 'RSA private key not found at [/tmp/herd-ssh-home/.ssh/id_rsa].');

    if ($originalHome === false) {
        putenv('HOME');
        unset($_ENV['HOME']);
        unset($_SERVER['HOME']);
    } else {
        putenv("HOME={$originalHome}");
        $_ENV['HOME'] = $originalHome;
        $_SERVER['HOME'] = $originalServerHome ?? $originalHome;
    }
});

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

<?php

use App\Models\DatabaseConnection;
use App\Services\Herd\MySqlManager;

test('database connection passwords are encrypted at rest', function () {
    $connection = DatabaseConnection::query()->create([
        'name' => 'Forge Production',
        'driver' => 'ssh_mysql',
        'host' => 'app.example.com',
        'port' => 22,
        'ssh_username' => 'forge',
        'private_key_path' => '/Users/kyle/.ssh/id_rsa',
        'database_host' => '127.0.0.1',
        'database_port' => 3306,
        'database_username' => 'forge',
        'database_password' => 'secret-value',
    ]);

    expect($connection->database_password)->toBe('secret-value');

    $storedPassword = DatabaseConnection::query()
        ->toBase()
        ->where('id', $connection->id)
        ->value('database_password');

    expect($storedPassword)
        ->not->toBe('secret-value')
        ->toBeString();
});

test('database export can target a saved ssh connection', function () {
    $connection = DatabaseConnection::factory()->create([
        'name' => 'Forge Production',
    ]);

    $manager = Mockery::mock(MySqlManager::class);
    $path = storage_path('app/private/test-remote-export.sql');
    file_put_contents($path, '-- sql');
    $manager->shouldReceive('exportDatabase')->once()->with('sandbox', Mockery::on(function ($resolvedConnection) use ($connection): bool {
        return $resolvedConnection instanceof DatabaseConnection
            && $resolvedConnection->is($connection);
    }))->andReturn($path);
    app()->instance(MySqlManager::class, $manager);

    $response = $this->get('/databases/sandbox/export?source=connection:'.$connection->id);

    $response->assertOk();
    $response->assertDownload('test-remote-export.sql');
});

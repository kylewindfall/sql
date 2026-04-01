<?php

use App\Models\DatabaseConnection;
use App\Services\Herd\MigrationGenerator;
use App\Services\Herd\MySqlManager;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt as LivewireVolt;

afterEach(function () {
    Mockery::close();
});

test('guests can render the database manager', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 1, 'size_bytes' => 1024, 'system' => false],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'users', null)->andReturn([
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 1, 'name' => 'Taylor']],
        'total' => 1,
    ]);
    app()->instance(MySqlManager::class, $manager);

    $response = $this->get('/dashboard');

    $response->assertStatus(200);
    $response->assertSee('Herd Studio');
    $response->assertSee('sandbox.users');
});

test('guests can create a database from the dashboard', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $manager->shouldReceive('listDatabases')->times(2)->andReturn(
        [['name' => 'sandbox', 'tables' => 0, 'size_bytes' => 0, 'system' => false]],
        [['name' => 'sandbox', 'tables' => 0, 'size_bytes' => 0, 'system' => false], ['name' => 'new_app', 'tables' => 0, 'size_bytes' => 0, 'system' => false]],
    );
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([]);
    $manager->shouldReceive('listTables')->once()->with('new_app', null)->andReturn([]);
    $manager->shouldReceive('createDatabase')->once()->with('new_app', null);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->set('newDatabaseName', 'new_app')
        ->call('createDatabase')
        ->assertSet('selectedDatabase', 'new_app');
});

test('guests can import a sql dump from the dashboard', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $manager->shouldReceive('listDatabases')->times(2)->andReturn(
        [['name' => 'sandbox', 'tables' => 0, 'size_bytes' => 0, 'system' => false]],
        [['name' => 'sandbox', 'tables' => 1, 'size_bytes' => 0, 'system' => false]],
    );
    $manager->shouldReceive('listTables')->times(2)->with('sandbox', null)->andReturn([], [['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => '']]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'users', null)->andReturn([
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 1]],
        'total' => 1,
    ]);
    $manager->shouldReceive('importDatabase')->once()->withArgs(function (string $database, string $path, $connection): bool {
        return $database === 'sandbox' && is_file($path) && $connection === null;
    });
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->set('importDatabaseName', 'sandbox')
        ->set('importFile', UploadedFile::fake()->createWithContent('dump.sql', 'CREATE TABLE users (id BIGINT PRIMARY KEY);'))
        ->call('importDatabase')
        ->assertSet('selectedDatabase', 'sandbox');
});

test('guests can update a keyed row from the dashboard', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $columns = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];
    $rows = ['rows' => [['id' => 1, 'name' => 'Taylor']], 'total' => 1];
    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 1, 'size_bytes' => 1024, 'system' => false],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->times(2)->with('sandbox', 'users', null)->andReturn($columns);
    $manager->shouldReceive('getTableRows')->times(2)->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn($rows);
    $manager->shouldReceive('updateRow')->once()->with('sandbox', 'users', ['id' => 1], ['id' => '1', 'name' => 'Abigail'], null);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->call('startEditingRow', 0)
        ->set('editingRowValues.name', 'Abigail')
        ->call('saveRow');
});

test('guests can sort and search rows from the data tab', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $columns = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];
    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 1, 'size_bytes' => 1024, 'system' => false],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->times(3)->with('sandbox', 'users', null)->andReturn($columns);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 1, 'name' => 'Taylor']],
        'total' => 1,
    ]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'users', 1, Mockery::type('int'), '', 'name', 'asc', null)->andReturn([
        'rows' => [['id' => 1, 'name' => 'Taylor']],
        'total' => 1,
    ]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'users', 1, Mockery::type('int'), 'tay', 'name', 'asc', null)->andReturn([
        'rows' => [['id' => 1, 'name' => 'Taylor']],
        'total' => 1,
    ]);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->call('sortBy', 'name')
        ->set('rowSearch', 'tay');
});

test('guests can add a column from the schema tab', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $columnsBefore = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
    ];
    $columnsAfter = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'status', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => true, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];
    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 1, 'size_bytes' => 1024, 'system' => false],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->times(2)->with('sandbox', 'users', null)->andReturn($columnsBefore, $columnsAfter);
    $manager->shouldReceive('getTableRows')->times(2)->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 1]],
        'total' => 1,
    ]);
    $manager->shouldReceive('addColumn')->once()->with(
        'sandbox',
        'users',
        [
            'name' => 'status',
            'type' => 'varchar',
            'length' => '255',
            'nullable' => true,
            'default' => null,
        ],
        null,
    );
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->set('newColumnName', 'status')
        ->call('addColumn')
        ->assertSet('activeTab', 'schema');
});

test('guests can generate a laravel migration from the schema tab', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $generator = Mockery::mock(MigrationGenerator::class);
    $columns = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'title', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
        ['name' => 'created_at', 'type' => 'timestamp', 'full_type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
        ['name' => 'updated_at', 'type' => 'timestamp', 'full_type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];

    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 1, 'size_bytes' => 1024, 'system' => false],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'posts', 'engine' => 'InnoDB', 'rows' => 2, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'posts', null)->andReturn($columns);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'posts', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 1, 'title' => 'Hello']],
        'total' => 1,
    ]);

    $generator->shouldReceive('generateCreateTableMigration')
        ->never();

    $generator->shouldReceive('renderCreateTableMigration')
        ->once()
        ->with('posts', $columns)
        ->andReturn('<?php echo "migration";');

    app()->instance(MySqlManager::class, $manager);
    app()->instance(MigrationGenerator::class, $generator);

    LivewireVolt::test('dashboard')
        ->call('switchTab', 'schema')
        ->call('generateMigration')
        ->assertSet('generatedMigrationCode', '<?php echo "migration";');
});

test('guests can export a database dump', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $path = storage_path('app/private/test-export.sql');
    file_put_contents($path, '-- sql');
    $manager->shouldReceive('exportDatabase')->once()->with('sandbox', null)->andReturn($path);
    app()->instance(MySqlManager::class, $manager);

    $response = $this->get('/databases/sandbox/export');

    $response->assertOk();
    $response->assertDownload('test-export.sql');
});

test('guests can save a forge ssh connection from the dashboard', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $manager->shouldReceive('listDatabases')->once()->with(null)->andReturn([]);
    $manager->shouldReceive('listDatabases')->once()->with(Mockery::on(function ($connection): bool {
        return $connection instanceof DatabaseConnection
            && $connection->name === 'Forge Production'
            && $connection->ssh_username === 'forge';
    }))->andReturn([]);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->set('connectionName', 'Forge Production')
        ->set('connectionHost', 'app.example.com')
        ->set('connectionPort', 22)
        ->set('connectionSshUsername', 'forge')
        ->set('connectionPrivateKeyPath', '/Users/kyle/.ssh/id_rsa')
        ->set('connectionDatabaseHost', '127.0.0.1')
        ->set('connectionDatabasePort', 3306)
        ->set('connectionDatabaseUsername', 'forge')
        ->set('connectionDatabasePassword', 'secret')
        ->call('saveConnection')
        ->assertSet('selectedSource', 'connection:1');

    $this->assertDatabaseHas('database_connections', [
        'id' => 1,
        'name' => 'Forge Production',
        'host' => 'app.example.com',
        'ssh_username' => 'forge',
        'database_username' => 'forge',
    ]);
});

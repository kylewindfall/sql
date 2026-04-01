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
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'users', null)->andReturn([
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ]);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'users', null)->andReturn([]);
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
    $manager->shouldReceive('listTableIndex')->times(2)->with(null)->andReturn([], []);
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
    $manager->shouldReceive('listTableIndex')->times(2)->with(null)->andReturn([], [['database' => 'sandbox', 'table' => 'users', 'rows' => 1]]);
    $manager->shouldReceive('listTables')->times(2)->with('sandbox', null)->andReturn([], [['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => '']]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'users', null)->andReturn([
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
    ]);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'users', null)->andReturn([]);
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

test('guests can import a csv file into the selected table', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 1, 'size_bytes' => 0, 'system' => false],
    ]);
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->times(2)->with('sandbox', 'users', null)->andReturn(
        [
            ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
            ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
        ],
        [
            ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
            ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
        ],
    );
    $manager->shouldReceive('getForeignKeys')->times(2)->with('sandbox', 'users', null)->andReturn([], []);
    $manager->shouldReceive('getTableRows')->times(2)->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn(
        ['rows' => [['id' => 1, 'name' => 'Taylor']], 'total' => 1],
        ['rows' => [['id' => 1, 'name' => 'Taylor'], ['id' => 2, 'name' => 'Abigail']], 'total' => 2],
    );
    $manager->shouldReceive('importTableCsv')->once()->withArgs(function (string $database, string $table, string $path, $connection): bool {
        return $database === 'sandbox' && $table === 'users' && is_file($path) && $connection === null;
    })->andReturn(1);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->set('tableImportFile', UploadedFile::fake()->createWithContent('users.csv', "name\nAbigail\n"))
        ->call('importTableCsv');
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
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->times(2)->with('sandbox', 'users', null)->andReturn($columns);
    $manager->shouldReceive('getForeignKeys')->times(2)->with('sandbox', 'users', null)->andReturn([]);
    $manager->shouldReceive('getTableRows')->times(2)->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn($rows);
    $manager->shouldReceive('updateRow')->once()->with('sandbox', 'users', ['id' => 1], ['id' => '1', 'name' => 'Abigail'], null);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->call('startEditingRow', 0)
        ->set('editingRowValues.name', 'Abigail')
        ->call('saveRow');
});

test('guests can update a single cell inline from the data grid', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $columns = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];
    $rows = ['rows' => [['id' => 1, 'name' => 'Taylor']], 'total' => 1];

    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 1, 'size_bytes' => 1024, 'system' => false],
    ]);
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'users', null)->andReturn($columns);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'users', null)->andReturn([]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn($rows);
    $manager->shouldReceive('updateRow')->once()->with('sandbox', 'users', ['id' => 1], ['id' => '1', 'name' => 'Abigail'], null);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->call('updateCell', 0, 'name', 'Abigail');
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
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->times(3)->with('sandbox', 'users', null)->andReturn($columns);
    $manager->shouldReceive('getForeignKeys')->times(3)->with('sandbox', 'users', null)->andReturn([]);
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

test('guests can persist column layout changes from the data grid', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $columns = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
        ['name' => 'email', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];
    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 1, 'size_bytes' => 1024, 'system' => false],
    ]);
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'users', null)->andReturn($columns);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'users', null)->andReturn([]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 1, 'name' => 'Taylor', 'email' => 'taylor@example.com']],
        'total' => 1,
    ]);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->call('moveColumnRight', 'name')
        ->call('setColumnWidth', 'email', 320)
        ->assertSet('columnOrder', ['id', 'email', 'name'])
        ->assertSet('columnWidths.email', 320);
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
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->times(2)->with('sandbox', 'users', null)->andReturn($columnsBefore, $columnsAfter);
    $manager->shouldReceive('getForeignKeys')->times(2)->with('sandbox', 'users', null)->andReturn([]);
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
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'posts', 'rows' => 2],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'posts', 'engine' => 'InnoDB', 'rows' => 2, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'posts', null)->andReturn($columns);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'posts', null)->andReturn([]);
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

test('guests can browse a related table from a foreign key value', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $commentColumns = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'user_id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];
    $userColumns = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];

    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 2, 'size_bytes' => 2048, 'system' => false],
    ]);
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'comments', 'rows' => 1],
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'comments', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'comments', null)->andReturn($commentColumns);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'comments', null)->andReturn([
        ['column' => 'user_id', 'referenced_table' => 'users', 'referenced_column' => 'id'],
    ]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'comments', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 1, 'user_id' => 7]],
        'total' => 1,
    ]);
    $manager->shouldReceive('getRelatedRecordPreview')->once()->with('sandbox', 'users', 'id', 7, null)->andReturn([
        'summary' => 'Taylor Otwell',
        'fields' => [
            ['label' => 'id', 'value' => '7'],
            ['label' => 'email', 'value' => 'taylor@example.com'],
        ],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'users', null)->andReturn($userColumns);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'users', null)->andReturn([]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'users', 1, Mockery::type('int'), '7', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 7, 'name' => 'Taylor']],
        'total' => 1,
    ]);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->call('browseRelationship', 'users', 'id', '7')
        ->assertSet('selectedTable', 'users')
        ->assertSet('rowSearch', '7');
});

test('guests can open a related table from a foreign key column header', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $commentColumns = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'user_id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];
    $userColumns = [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'name', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ];

    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 2, 'size_bytes' => 2048, 'system' => false],
    ]);
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'comments', 'rows' => 1],
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'comments', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'comments', null)->andReturn($commentColumns);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'comments', null)->andReturn([
        ['column' => 'user_id', 'referenced_table' => 'users', 'referenced_column' => 'id'],
    ]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'comments', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 1, 'user_id' => 7]],
        'total' => 1,
    ]);
    $manager->shouldReceive('getRelatedRecordPreview')->once()->with('sandbox', 'users', 'id', 7, null)->andReturn([
        'summary' => 'Taylor Otwell',
        'fields' => [
            ['label' => 'id', 'value' => '7'],
            ['label' => 'email', 'value' => 'taylor@example.com'],
        ],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'users', null)->andReturn($userColumns);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'users', null)->andReturn([]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'users', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 7, 'name' => 'Taylor']],
        'total' => 1,
    ]);
    app()->instance(MySqlManager::class, $manager);

    LivewireVolt::test('dashboard')
        ->call('openRelatedTable', 'users')
        ->assertSet('selectedTable', 'users')
        ->assertSet('rowSearch', '');
});

test('guests can see a related record preview in the data grid', function () {
    $manager = Mockery::mock(MySqlManager::class);

    $manager->shouldReceive('listDatabases')->once()->andReturn([
        ['name' => 'sandbox', 'tables' => 2, 'size_bytes' => 2048, 'system' => false],
    ]);
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([
        ['database' => 'sandbox', 'table' => 'comments', 'rows' => 1],
        ['database' => 'sandbox', 'table' => 'users', 'rows' => 1],
    ]);
    $manager->shouldReceive('listTables')->once()->with('sandbox', null)->andReturn([
        ['name' => 'comments', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
        ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 1, 'size_bytes' => 1024, 'comment' => ''],
    ]);
    $manager->shouldReceive('getTableColumns')->once()->with('sandbox', 'comments', null)->andReturn([
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'user_id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ]);
    $manager->shouldReceive('getForeignKeys')->once()->with('sandbox', 'comments', null)->andReturn([
        ['column' => 'user_id', 'referenced_table' => 'users', 'referenced_column' => 'id'],
    ]);
    $manager->shouldReceive('getTableRows')->once()->with('sandbox', 'comments', 1, Mockery::type('int'), '', 'id', 'asc', null)->andReturn([
        'rows' => [['id' => 1, 'user_id' => 7]],
        'total' => 1,
    ]);
    $manager->shouldReceive('getRelatedRecordPreview')->once()->with('sandbox', 'users', 'id', 7, null)->andReturn([
        'summary' => 'Taylor Otwell',
        'fields' => [
            ['label' => 'id', 'value' => '7'],
            ['label' => 'email', 'value' => 'taylor@example.com'],
        ],
    ]);
    app()->instance(MySqlManager::class, $manager);

    $response = $this->get('/dashboard');

    $response->assertOk();
    $response->assertSee('Taylor Otwell');
    $response->assertSee('taylor@example.com');
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

test('guests can export a table as csv', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $path = storage_path('app/private/test-export.csv');
    file_put_contents($path, "id,name\n1,Taylor\n");
    $manager->shouldReceive('exportTableCsv')
        ->once()
        ->with('sandbox', 'users', 'tay', 'name', 'desc', null)
        ->andReturn($path);
    app()->instance(MySqlManager::class, $manager);

    $response = $this->get('/databases/sandbox/tables/users/export-csv?search=tay&sort_column=name&sort_direction=desc');

    $response->assertOk();
    $response->assertDownload('test-export.csv');
});

test('guests can save a forge ssh connection from the dashboard', function () {
    $manager = Mockery::mock(MySqlManager::class);
    $manager->shouldReceive('listDatabases')->once()->with(null)->andReturn([]);
    $manager->shouldReceive('listTableIndex')->once()->with(null)->andReturn([]);
    $manager->shouldReceive('listDatabases')->once()->with(Mockery::on(function ($connection): bool {
        return $connection instanceof DatabaseConnection
            && $connection->name === 'Forge Production'
            && $connection->ssh_username === 'forge';
    }))->andReturn([]);
    $manager->shouldReceive('listTableIndex')->once()->with(Mockery::type(DatabaseConnection::class))->andReturn([]);
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

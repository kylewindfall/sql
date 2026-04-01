<?php

use App\Models\DatabaseConnection;
use App\Services\Herd\MySqlManager;
use App\Services\Herd\SshTunnelManager;

test('it infers laravel style foreign keys when schema constraints are missing', function () {
    $manager = new class extends MySqlManager
    {
        public function __construct()
        {
            parent::__construct(new SshTunnelManager);
        }

        public function listTables(string $database, ?DatabaseConnection $connection = null): array
        {
            return [
                ['name' => 'cities', 'engine' => 'InnoDB', 'rows' => 0, 'size_bytes' => 0, 'comment' => ''],
                ['name' => 'listings', 'engine' => 'InnoDB', 'rows' => 0, 'size_bytes' => 0, 'comment' => ''],
            ];
        }

        public function getTableColumns(string $database, string $table, ?DatabaseConnection $connection = null): array
        {
            return [
                ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
                ['name' => 'LISTING_ID', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
                ['name' => 'city_id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
                ['name' => 'external_id', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
            ];
        }

        /**
         * @param  array<int, array{column: string, referenced_table: string, referenced_column: string}>  $foreignKeys
         * @return array<int, array{column: string, referenced_table: string, referenced_column: string}>
         */
        public function infer(array $foreignKeys = []): array
        {
            return $this->inferConventionForeignKeys('sandbox', 'properties', $foreignKeys);
        }
    };

    expect($manager->infer())->toBe([
        ['column' => 'LISTING_ID', 'referenced_table' => 'listings', 'referenced_column' => 'id'],
        ['column' => 'city_id', 'referenced_table' => 'cities', 'referenced_column' => 'id'],
    ]);
});

test('it does not override real foreign keys when inferring relationships', function () {
    $manager = new class extends MySqlManager
    {
        public function __construct()
        {
            parent::__construct(new SshTunnelManager);
        }

        public function listTables(string $database, ?DatabaseConnection $connection = null): array
        {
            return [
                ['name' => 'users', 'engine' => 'InnoDB', 'rows' => 0, 'size_bytes' => 0, 'comment' => ''],
            ];
        }

        public function getTableColumns(string $database, string $table, ?DatabaseConnection $connection = null): array
        {
            return [
                ['name' => 'user_id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
            ];
        }

        /**
         * @param  array<int, array{column: string, referenced_table: string, referenced_column: string}>  $foreignKeys
         * @return array<int, array{column: string, referenced_table: string, referenced_column: string}>
         */
        public function infer(array $foreignKeys = []): array
        {
            return $this->inferConventionForeignKeys('sandbox', 'comments', $foreignKeys);
        }
    };

    expect($manager->infer([
        ['column' => 'user_id', 'referenced_table' => 'accounts', 'referenced_column' => 'uuid'],
    ]))->toBe([]);
});

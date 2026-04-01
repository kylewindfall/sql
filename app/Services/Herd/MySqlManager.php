<?php

namespace App\Services\Herd;

use App\Models\DatabaseConnection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class MySqlManager
{
    private const SYSTEM_DATABASES = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
    ];

    public function __construct(
        private readonly SshTunnelManager $sshTunnelManager,
    ) {}

    /**
     * @return array<int, array{name: string, tables: int, size_bytes: int, system: bool}>
     */
    public function listDatabases(?DatabaseConnection $connection = null): array
    {
        $statement = $this->connect(connection: $connection)->prepare(<<<'SQL'
            SELECT
                schemata.SCHEMA_NAME AS name,
                COUNT(tables.TABLE_NAME) AS tables,
                COALESCE(SUM(tables.DATA_LENGTH + tables.INDEX_LENGTH), 0) AS size_bytes
            FROM information_schema.SCHEMATA AS schemata
            LEFT JOIN information_schema.TABLES AS tables
                ON tables.TABLE_SCHEMA = schemata.SCHEMA_NAME
                AND tables.TABLE_TYPE = 'BASE TABLE'
            GROUP BY schemata.SCHEMA_NAME
            ORDER BY schemata.SCHEMA_NAME ASC
        SQL);

        $statement->execute();

        return collect($statement->fetchAll())->map(function (array $database): array {
            $databaseName = (string) $database['name'];

            return [
                'name' => $databaseName,
                'tables' => (int) $database['tables'],
                'size_bytes' => (int) $database['size_bytes'],
                'system' => in_array($databaseName, self::SYSTEM_DATABASES, true),
            ];
        })->sortBy([
            fn (array $database): int => $database['system'] ? 1 : 0,
            fn (array $database): string => $database['name'],
        ])->values()->all();
    }

    /**
     * @return array<int, array{name: string, engine: string, rows: int, size_bytes: int, comment: string}>
     */
    public function listTables(string $database, ?DatabaseConnection $connection = null): array
    {
        $this->guardIdentifier($database);

        $statement = $this->connect(connection: $connection)->prepare(<<<'SQL'
            SELECT
                TABLE_NAME AS name,
                ENGINE AS engine,
                TABLE_ROWS AS row_count,
                COALESCE(DATA_LENGTH + INDEX_LENGTH, 0) AS size_bytes,
                TABLE_COMMENT AS comment
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
                AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME ASC
        SQL);

        $statement->execute([$database]);

        return collect($statement->fetchAll())->map(fn (array $table): array => [
            'name' => (string) $table['name'],
            'engine' => (string) ($table['engine'] ?? ''),
            'rows' => (int) ($table['row_count'] ?? 0),
            'size_bytes' => (int) ($table['size_bytes'] ?? 0),
            'comment' => (string) ($table['comment'] ?? ''),
        ])->all();
    }

    /**
     * @return array<int, array{name: string, type: string, full_type: string, nullable: bool, default: mixed, primary: bool, auto_increment: bool, generated: bool, comment: string}>
     */
    public function getTableColumns(string $database, string $table, ?DatabaseConnection $connection = null): array
    {
        $this->guardIdentifier($database);
        $this->guardIdentifier($table);

        $statement = $this->connect(connection: $connection)->prepare(<<<'SQL'
            SELECT
                COLUMN_NAME AS name,
                DATA_TYPE AS type,
                COLUMN_TYPE AS full_type,
                IS_NULLABLE AS is_nullable,
                COLUMN_DEFAULT AS column_default,
                COLUMN_KEY AS column_key,
                EXTRA AS extra,
                COLUMN_COMMENT AS column_comment
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION ASC
        SQL);

        $statement->execute([$database, $table]);

        return collect($statement->fetchAll())->map(fn (array $column): array => [
            'name' => (string) $column['name'],
            'type' => (string) $column['type'],
            'full_type' => (string) $column['full_type'],
            'nullable' => $column['is_nullable'] === 'YES',
            'default' => $column['column_default'],
            'primary' => $column['column_key'] === 'PRI',
            'auto_increment' => Str::contains((string) $column['extra'], 'auto_increment'),
            'generated' => Str::contains((string) $column['extra'], 'GENERATED'),
            'comment' => (string) ($column['column_comment'] ?? ''),
        ])->all();
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function getTableRows(
        string $database,
        string $table,
        int $page = 1,
        ?int $perPage = null,
        string $search = '',
        ?string $sortColumn = null,
        string $sortDirection = 'asc',
        ?DatabaseConnection $connection = null,
    ): array {
        $this->guardIdentifier($database);
        $this->guardIdentifier($table);

        $page = max($page, 1);
        $perPage = $perPage ? max($perPage, 1) : $this->pageSize();
        $offset = ($page - 1) * $perPage;
        $qualifiedTable = $this->qualifyTable($database, $table);
        [$whereClause, $wherePayload] = $this->buildSearchClause($database, $table, $search, $connection);
        $countSql = "SELECT COUNT(*) FROM {$qualifiedTable}";

        if ($whereClause !== '') {
            $countSql .= " WHERE {$whereClause}";
        }

        $countStatement = $this->connect(connection: $connection)->prepare($countSql);
        $countStatement->execute($wherePayload);
        $total = (int) $countStatement->fetchColumn();

        $primaryKeyColumns = $this->getPrimaryKeyColumns($database, $table, $connection);
        $columnNames = $this->getColumnNames($database, $table, $connection);
        $sortColumn = $sortColumn !== null && in_array($sortColumn, $columnNames, true)
            ? $sortColumn
            : Arr::first($primaryKeyColumns) ?? Arr::first($columnNames);
        $sortDirection = strtolower($sortDirection) === 'desc' ? 'DESC' : 'ASC';
        $orderColumns = $sortColumn !== null ? [$sortColumn] : ($primaryKeyColumns !== [] ? $primaryKeyColumns : [Arr::first($columnNames)]);
        $orderClause = collect($orderColumns)
            ->filter()
            ->map(fn (string $column): string => $this->quoteIdentifier($column).' '.$sortDirection)
            ->implode(', ');

        $query = "SELECT * FROM {$qualifiedTable}";

        if ($whereClause !== '') {
            $query .= " WHERE {$whereClause}";
        }

        if ($orderClause !== '') {
            $query .= " ORDER BY {$orderClause}";
        }

        $query .= sprintf(' LIMIT %d OFFSET %d', $perPage, $offset);

        $statement = $this->connect(connection: $connection)->prepare($query);
        $statement->execute($wherePayload);

        return [
            'rows' => $statement->fetchAll(),
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insertRow(string $database, string $table, array $values, ?DatabaseConnection $connection = null): void
    {
        $columns = collect($this->getEditableColumns($database, $table, $connection, true))
            ->map(function (array $column) use ($values): array {
                return [
                    'column' => $column,
                    'value' => $this->normalizeValue($column, $values[$column['name']] ?? null),
                ];
            })
            ->filter(fn (array $item): bool => ! ($item['column']['auto_increment'] && $item['value'] === null))
            ->values();

        if ($columns->isEmpty()) {
            throw new RuntimeException('The selected table does not expose any editable columns.');
        }

        $qualifiedTable = $this->qualifyTable($database, $table);
        $columnList = $columns->map(fn (array $item): string => $this->quoteIdentifier($item['column']['name']))->implode(', ');
        $placeholderList = implode(', ', array_fill(0, $columns->count(), '?'));
        $payload = $columns->pluck('value')->all();

        $statement = $this->connect(connection: $connection)->prepare("INSERT INTO {$qualifiedTable} ({$columnList}) VALUES ({$placeholderList})");
        $statement->execute($payload);
    }

    /**
     * @param  array<string, mixed>  $identifiers
     * @param  array<string, mixed>  $values
     */
    public function updateRow(string $database, string $table, array $identifiers, array $values, ?DatabaseConnection $connection = null): void
    {
        $primaryKeyColumns = $this->getPrimaryKeyColumns($database, $table, $connection);

        if ($primaryKeyColumns === []) {
            throw new RuntimeException('Only tables with a primary key can be edited.');
        }

        $editableColumns = collect($this->getEditableColumns($database, $table, $connection))
            ->reject(fn (array $column): bool => $column['primary'])
            ->values();

        if ($editableColumns->isEmpty()) {
            throw new RuntimeException('The selected table does not expose any updatable columns.');
        }

        $setClauses = [];
        $payload = [];

        foreach ($editableColumns as $column) {
            $setClauses[] = $this->quoteIdentifier($column['name']).' = ?';
            $payload[] = $this->normalizeValue($column, $values[$column['name']] ?? null);
        }

        [$whereClause, $wherePayload] = $this->buildWhereClause($database, $table, $identifiers, $connection);

        $statement = $this->connect(connection: $connection)->prepare(sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->qualifyTable($database, $table),
            implode(', ', $setClauses),
            $whereClause,
        ));

        $statement->execute([...$payload, ...$wherePayload]);
    }

    /**
     * @param  array<string, mixed>  $identifiers
     */
    public function deleteRow(string $database, string $table, array $identifiers, ?DatabaseConnection $connection = null): void
    {
        [$whereClause, $payload] = $this->buildWhereClause($database, $table, $identifiers, $connection);

        $statement = $this->connect(connection: $connection)->prepare(sprintf(
            'DELETE FROM %s WHERE %s',
            $this->qualifyTable($database, $table),
            $whereClause,
        ));

        $statement->execute($payload);
    }

    public function createDatabase(string $database, ?DatabaseConnection $connection = null): void
    {
        $this->guardIdentifier($database);

        $statement = $this->connect(connection: $connection)->prepare(sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->quoteIdentifier($database),
        ));

        $statement->execute();
    }

    public function importDatabase(string $database, string $sqlPath, ?DatabaseConnection $connection = null): void
    {
        $this->guardIdentifier($database);
        $this->ensureReadableFile($sqlPath);
        $this->createDatabase($database, $connection);

        $this->runCliCommand(
            $this->mysqlCommand($database, $connection),
            file_get_contents($sqlPath) ?: '',
        );
    }

    public function exportDatabase(string $database, ?DatabaseConnection $connection = null): string
    {
        $this->guardIdentifier($database);

        $temporaryPath = storage_path('app/private/exports');

        if (! is_dir($temporaryPath) && ! mkdir($temporaryPath, 0755, true) && ! is_dir($temporaryPath)) {
            throw new RuntimeException('Unable to prepare the export directory.');
        }

        $sourceSlug = $connection?->id ? 'connection-'.$connection->id : 'local';
        $exportPath = $temporaryPath.'/'.Str::slug($sourceSlug.'-'.$database).'-'.now()->format('YmdHis').'.sql';

        $this->runCliCommand([
            ...$this->mysqldumpCommand($database, $connection),
            '--result-file='.$exportPath,
        ]);

        return $exportPath;
    }

    /**
     * @param  array{name: string, type: string, length: string|null, nullable: bool, default: string|null}  $definition
     */
    public function addColumn(string $database, string $table, array $definition, ?DatabaseConnection $connection = null): void
    {
        $this->guardIdentifier($database);
        $this->guardIdentifier($table);
        $this->guardIdentifier($definition['name']);

        $type = strtolower($definition['type']);
        $allowedTypes = [
            'bigint',
            'boolean',
            'date',
            'datetime',
            'decimal',
            'int',
            'json',
            'text',
            'timestamp',
            'varchar',
        ];

        if (! in_array($type, $allowedTypes, true)) {
            throw new RuntimeException("Unsupported column type [{$definition['type']}].");
        }

        $length = trim((string) ($definition['length'] ?? ''));
        $typeSql = match ($type) {
            'varchar' => 'VARCHAR('.($length !== '' ? (int) $length : 255).')',
            'decimal' => 'DECIMAL('.($length !== '' ? $length : '10,2').')',
            'int' => 'INT',
            'bigint' => 'BIGINT',
            'boolean' => 'BOOLEAN',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'json' => 'JSON',
            'text' => 'TEXT',
        };

        $nullableSql = $definition['nullable'] ? 'NULL' : 'NOT NULL';
        $defaultSql = '';
        $pdo = $this->connect(connection: $connection);

        if ($definition['default'] !== null && $definition['default'] !== '') {
            $defaultSql = ' DEFAULT '.$pdo->quote($definition['default']);
        } elseif ($definition['nullable']) {
            $defaultSql = ' DEFAULT NULL';
        }

        $statement = $pdo->prepare(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s %s%s',
            $this->qualifyTable($database, $table),
            $this->quoteIdentifier($definition['name']),
            $typeSql,
            $nullableSql,
            $defaultSql,
        ));

        $statement->execute();
    }

    /**
     * @return array<int, string>
     */
    public function getPrimaryKeyColumns(string $database, string $table, ?DatabaseConnection $connection = null): array
    {
        return collect($this->getTableColumns($database, $table, $connection))
            ->filter(fn (array $column): bool => $column['primary'])
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: string, type: string, full_type: string, nullable: bool, default: mixed, primary: bool, auto_increment: bool, generated: bool, comment: string}>
     */
    private function getEditableColumns(string $database, string $table, ?DatabaseConnection $connection = null, bool $forInsert = false): array
    {
        return collect($this->getTableColumns($database, $table, $connection))
            ->reject(function (array $column) use ($forInsert): bool {
                if ($column['generated']) {
                    return true;
                }

                return $forInsert
                    ? false
                    : $column['auto_increment'];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function getColumnNames(string $database, string $table, ?DatabaseConnection $connection = null): array
    {
        return collect($this->getTableColumns($database, $table, $connection))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $identifiers
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhereClause(string $database, string $table, array $identifiers, ?DatabaseConnection $connection = null): array
    {
        $primaryKeyColumns = $this->getPrimaryKeyColumns($database, $table, $connection);

        if ($primaryKeyColumns === []) {
            throw new RuntimeException('Only tables with a primary key can be modified.');
        }

        $tableColumns = collect($this->getTableColumns($database, $table, $connection))->keyBy('name');
        $clauses = [];
        $payload = [];

        foreach ($primaryKeyColumns as $columnName) {
            if (! array_key_exists($columnName, $identifiers)) {
                throw new RuntimeException('The selected row is missing its primary key payload.');
            }

            $clauses[] = $this->quoteIdentifier($columnName).' = ?';
            $payload[] = $this->normalizeValue($tableColumns[$columnName], $identifiers[$columnName]);
        }

        return [implode(' AND ', $clauses), $payload];
    }

    /**
     * @param  array{name: string, type: string, full_type: string, nullable: bool, default: mixed, primary: bool, auto_increment: bool, generated: bool, comment: string}  $column
     */
    private function normalizeValue(array $column, mixed $value): mixed
    {
        if ($value === '__NULL__' && $column['nullable']) {
            return null;
        }

        if ($value === '' && $column['nullable'] && ! $this->isTextColumn($column['type'])) {
            return null;
        }

        return $value;
    }

    private function isTextColumn(string $type): bool
    {
        return in_array($type, [
            'binary',
            'blob',
            'char',
            'enum',
            'json',
            'longblob',
            'longtext',
            'mediumblob',
            'mediumtext',
            'set',
            'text',
            'tinyblob',
            'tinytext',
            'varbinary',
            'varchar',
        ], true);
    }

    private function pageSize(): int
    {
        return max((int) config('herd.mysql.page_size', 25), 1);
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function buildSearchClause(string $database, string $table, string $search, ?DatabaseConnection $connection = null): array
    {
        $search = trim($search);

        if ($search === '') {
            return ['', []];
        }

        $columns = $this->getColumnNames($database, $table, $connection);
        $payload = [];
        $clauses = collect($columns)
            ->map(function (string $column) use ($search, &$payload): string {
                $payload[] = '%'.$search.'%';

                return 'CAST('.$this->quoteIdentifier($column).' AS CHAR) LIKE ?';
            })
            ->all();

        return ['('.implode(' OR ', $clauses).')', $payload];
    }

    private function connect(?string $database = null, ?DatabaseConnection $connection = null): \PDO
    {
        $config = $this->config($connection);
        $dsn = $config['socket'] !== ''
            ? "mysql:unix_socket={$config['socket']};charset=utf8mb4"
            : "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";

        if ($database !== null) {
            $dsn .= ';dbname='.$database;
        }

        return new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, socket: string, binary: string, dump_binary: string}
     */
    private function config(?DatabaseConnection $connection = null): array
    {
        if ($connection === null) {
            return [
                'host' => (string) config('herd.mysql.host', '127.0.0.1'),
                'port' => (int) config('herd.mysql.port', 3306),
                'username' => (string) config('herd.mysql.username', 'root'),
                'password' => (string) config('herd.mysql.password', ''),
                'socket' => (string) config('herd.mysql.socket', ''),
                'binary' => (string) config('herd.mysql.binary', 'mysql'),
                'dump_binary' => (string) config('herd.mysql.dump_binary', 'mysqldump'),
            ];
        }

        return [
            'host' => '127.0.0.1',
            'port' => $this->sshTunnelManager->ensure($connection),
            'username' => (string) $connection->database_username,
            'password' => (string) ($connection->database_password ?? ''),
            'socket' => '',
            'binary' => (string) config('herd.mysql.binary', 'mysql'),
            'dump_binary' => (string) config('herd.mysql.dump_binary', 'mysqldump'),
        ];
    }

    private function qualifyTable(string $database, string $table): string
    {
        $this->guardIdentifier($database);
        $this->guardIdentifier($table);

        return $this->quoteIdentifier($database).'.'.$this->quoteIdentifier($table);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function guardIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $identifier)) {
            throw new RuntimeException("Unsupported identifier [{$identifier}].");
        }
    }

    private function ensureReadableFile(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The selected SQL file could not be read.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function mysqlCommand(string $database, ?DatabaseConnection $connection = null): array
    {
        $config = $this->config($connection);

        return [
            $config['binary'],
            ...$this->cliConnectionArguments($connection),
            $database,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function mysqldumpCommand(string $database, ?DatabaseConnection $connection = null): array
    {
        $config = $this->config($connection);

        return [
            $config['dump_binary'],
            ...$this->cliConnectionArguments($connection),
            '--single-transaction',
            '--skip-lock-tables',
            '--routines',
            '--triggers',
            $database,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function cliConnectionArguments(?DatabaseConnection $connection = null): array
    {
        $config = $this->config($connection);
        $arguments = [
            '--user='.$config['username'],
        ];

        if ($config['socket'] !== '') {
            $arguments[] = '--protocol=SOCKET';
            $arguments[] = '--socket='.$config['socket'];
        } else {
            $arguments[] = '--protocol=TCP';
            $arguments[] = '--host='.$config['host'];
            $arguments[] = '--port='.$config['port'];
        }

        if ($config['password'] !== '') {
            $arguments[] = '--password='.$config['password'];
        }

        return $arguments;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runCliCommand(array $command, ?string $input = null): void
    {
        $process = new Process($command);

        if ($input !== null) {
            $process->setInput($input);
        }

        try {
            $process->mustRun();
        } catch (Throwable $exception) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'MySQL command failed.', previous: $exception);
        }
    }
}

<?php

use App\Models\DatabaseConnection;
use App\Services\Herd\MigrationGenerator;
use App\Services\Herd\MySqlManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.studio')] class extends Component {
    use WithFileUploads;

    public array $databases = [];

    public array $tables = [];

    public array $columns = [];

    public array $rows = [];

    public array $foreignKeys = [];

    public array $relatedRecordPreviews = [];

    public array $savedConnections = [];

    public array $commandPaletteEntries = [];

    public array $pinnedTables = [];

    public array $recentTables = [];

    public array $selectedRowIndexes = [];

    public array $columnOrder = [];

    public array $columnWidths = [];

    public array $editingRowValues = [];

    public array $editingRowIdentifiers = [];

    public array $createRowValues = [];

    public array $primaryKeyColumns = [];

    public string $selectedDatabase = '';

    public string $selectedTable = '';

    public string $selectedSource = 'local';

    public string $activeTab = 'data';

    public string $tableSearch = '';

    public string $rowSearch = '';

    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    public string $newDatabaseName = '';

    public string $importDatabaseName = '';

    public string $connectionName = '';

    public string $connectionHost = '';

    public int|string $connectionPort = 22;

    public string $connectionSshUsername = 'forge';

    public string $connectionPrivateKeyPath = '';

    public string $connectionDatabaseHost = '127.0.0.1';

    public int|string $connectionDatabasePort = 3306;

    public string $connectionDatabaseUsername = '';

    public string $connectionDatabasePassword = '';

    public string $newColumnName = '';

    public string $newColumnType = 'varchar';

    public string $newColumnLength = '255';

    public string $newColumnDefault = '';

    public string $generatedMigrationCode = '';

    public bool $newColumnNullable = true;

    public bool $showSystemDatabases = false;

    public bool $showCreateRow = false;

    public ?int $editingRowIndex = null;

    public int $page = 1;

    public int $perPage = 25;

    public int $totalRows = 0;

    public mixed $importFile = null;

    public mixed $tableImportFile = null;

    public function mount(): void
    {
        $this->perPage = max((int) config('herd.mysql.page_size', 25), 1);
        $this->pinnedTables = session('dashboard.pinned_tables', []);
        $this->recentTables = session('dashboard.recent_tables', []);
        $this->refreshWorkspace();
    }

    public function updatedSelectedDatabase(): void
    {
        $this->page = 1;
        $this->tableSearch = '';
        $this->refreshTables();
    }

    public function updatedSelectedTable(): void
    {
        $this->page = 1;
        $this->rowSearch = '';
        $this->sortColumn = '';
        $this->sortDirection = 'asc';
        $this->activeTab = 'data';
        $this->refreshTableData();
    }

    public function updatedRowSearch(): void
    {
        $this->page = 1;
        $this->refreshTableData();
    }

    public function updatedNewColumnType(): void
    {
        if ($this->newColumnType === 'varchar' && $this->newColumnLength === '') {
            $this->newColumnLength = '255';
        }

        if ($this->newColumnType === 'decimal' && $this->newColumnLength === '') {
            $this->newColumnLength = '10,2';
        }

        if (! in_array($this->newColumnType, ['varchar', 'decimal'], true)) {
            $this->newColumnLength = '';
        }
    }

    public function updatedSelectedSource(): void
    {
        $this->page = 1;
        $this->tableSearch = '';
        $this->rowSearch = '';
        $this->sortColumn = '';
        $this->sortDirection = 'asc';
        $this->selectedDatabase = '';
        $this->selectedTable = '';
        $this->refreshWorkspace();
    }

    public function selectDatabase(string $database): void
    {
        $this->selectedDatabase = $database;
        $this->page = 1;
        $this->refreshTables();
    }

    public function selectTable(string $table): void
    {
        $this->selectedTable = $table;
        $this->page = 1;
        $this->activeTab = 'data';
        $this->refreshTableData();
    }

    public function openTable(string $database, string $table): void
    {
        $this->selectedDatabase = $database;
        $this->selectedTable = $table;
        $this->page = 1;
        $this->activeTab = 'data';
        $this->refreshTables();
    }

    public function switchTab(string $tab): void
    {
        if (! in_array($tab, ['data', 'schema'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->page = 1;
        $this->refreshTableData();
    }

    public function previousPage(): void
    {
        if ($this->page === 1) {
            return;
        }

        $this->page--;
        $this->refreshTableData();
    }

    public function nextPage(): void
    {
        if ($this->page >= $this->lastPage()) {
            return;
        }

        $this->page++;
        $this->refreshTableData();
    }

    public function createDatabase(): void
    {
        try {
            $validated = $this->validate([
                'newDatabaseName' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$-]+$/'],
            ]);

            app(MySqlManager::class)->createDatabase($validated['newDatabaseName'], $this->activeConnection());

            $this->newDatabaseName = '';
            $this->selectedDatabase = $validated['newDatabaseName'];
            $this->importDatabaseName = $validated['newDatabaseName'];

            $this->refreshWorkspace();

            $this->notifySuccess('Database created', "Created {$this->selectedDatabase}.");
        } catch (\Throwable $exception) {
            $this->notifyError('Database creation failed', $exception->getMessage());
        }
    }

    public function importDatabase(): void
    {
        try {
            $validated = $this->validate([
                'importDatabaseName' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$-]+$/'],
                'importFile' => ['required', 'file', 'max:51200', 'extensions:sql,txt'],
            ]);

            app(MySqlManager::class)->importDatabase($validated['importDatabaseName'], $this->importFile->getRealPath(), $this->activeConnection());

            $this->reset('importFile');
            $this->selectedDatabase = $validated['importDatabaseName'];
            $this->refreshWorkspace();

            $this->notifySuccess('SQL import complete', "Imported dump into {$this->selectedDatabase}.");
        } catch (\Throwable $exception) {
            $this->notifyError('SQL import failed', $exception->getMessage());
        }
    }

    public function importTableCsv(): void
    {
        if ($this->selectedTable === '') {
            return;
        }

        try {
            $validated = $this->validate([
                'tableImportFile' => ['required', 'file', 'max:20480', 'extensions:csv,txt'],
            ]);

            $importedRows = app(MySqlManager::class)->importTableCsv(
                $this->selectedDatabase,
                $this->selectedTable,
                $validated['tableImportFile']->getRealPath(),
                $this->activeConnection(),
            );

            $this->reset('tableImportFile');
            $this->refreshTableData();

            $this->notifySuccess('CSV imported', "Imported {$importedRows} rows into {$this->selectedTable}.");
        } catch (\Throwable $exception) {
            $this->notifyError('CSV import failed', $exception->getMessage());
        }
    }

    public function addColumn(): void
    {
        try {
            $validated = $this->validate([
                'newColumnName' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$-]+$/'],
                'newColumnType' => ['required', 'in:varchar,text,int,bigint,boolean,date,datetime,timestamp,json,decimal'],
                'newColumnLength' => ['nullable', 'string', 'max:16'],
                'newColumnDefault' => ['nullable', 'string', 'max:255'],
                'newColumnNullable' => ['boolean'],
            ]);

            app(MySqlManager::class)->addColumn(
                $this->selectedDatabase,
                $this->selectedTable,
                [
                    'name' => $validated['newColumnName'],
                    'type' => $validated['newColumnType'],
                    'length' => $validated['newColumnLength'] ?: null,
                    'nullable' => $validated['newColumnNullable'],
                    'default' => $validated['newColumnDefault'] !== '' ? $validated['newColumnDefault'] : null,
                ],
                $this->activeConnection(),
            );

            $this->resetSchemaForm();
            $this->refreshTableData();
            $this->activeTab = 'schema';

            $this->notifySuccess('Column added', "Added {$validated['newColumnName']}.");
        } catch (\Throwable $exception) {
            $this->notifyError('Column update failed', $exception->getMessage());
        }
    }

    public function generateMigration(): void
    {
        if ($this->selectedTable === '' || $this->columns === []) {
            return;
        }

        $this->generatedMigrationCode = app(MigrationGenerator::class)->renderCreateTableMigration(
            $this->selectedTable,
            $this->columns,
        );

        $this->dispatch('migration-generated');
    }

    public function updateCell(int $rowIndex, string $columnName, string $value): void
    {
        if (! $this->canMutateRows() || ! isset($this->rows[$rowIndex])) {
            return;
        }

        try {
            $row = $this->rows[$rowIndex];
            $payload = $this->formatRowForForm($row);
            $payload[$columnName] = $value;

            app(MySqlManager::class)->updateRow(
                $this->selectedDatabase,
                $this->selectedTable,
                $this->rowIdentifiers($row),
                $payload,
                $this->activeConnection(),
            );

            $this->rows[$rowIndex][$columnName] = $value === '__NULL__' ? null : $value;
            $this->notifySuccess('Cell updated', "{$columnName} saved.");
        } catch (\Throwable $exception) {
            $this->notifyError('Cell update failed', $exception->getMessage());
        }
    }

    public function browseRelationship(string $referencedTable, string $referencedColumn, string $value): void
    {
        $this->selectedTable = $referencedTable;
        $this->rowSearch = $value;
        $this->page = 1;
        $this->activeTab = 'data';
        $this->refreshTableData();
        $this->notifySuccess('Related rows loaded', "Browsing {$referencedTable} by {$referencedColumn}.");
    }

    public function openRelatedTable(string $referencedTable): void
    {
        $this->selectedTable = $referencedTable;
        $this->rowSearch = '';
        $this->page = 1;
        $this->activeTab = 'data';
        $this->refreshTableData();
        $this->notifySuccess('Related table opened', "Opened {$referencedTable}.");
    }

    public function toggleRowSelection(int $rowIndex): void
    {
        if (in_array($rowIndex, $this->selectedRowIndexes, true)) {
            $this->selectedRowIndexes = array_values(array_filter(
                $this->selectedRowIndexes,
                fn (int $selectedIndex): bool => $selectedIndex !== $rowIndex,
            ));

            return;
        }

        $this->selectedRowIndexes[] = $rowIndex;
        $this->selectedRowIndexes = array_values(array_unique($this->selectedRowIndexes));
    }

    public function clearSelectedRows(): void
    {
        $this->selectedRowIndexes = [];
    }

    public function bulkDeleteSelectedRows(): void
    {
        if (! $this->canMutateRows()) {
            return;
        }

        try {
            foreach ($this->selectedRowIndexes as $rowIndex) {
                if (! isset($this->rows[$rowIndex])) {
                    continue;
                }

                app(MySqlManager::class)->deleteRow(
                    $this->selectedDatabase,
                    $this->selectedTable,
                    $this->rowIdentifiers($this->rows[$rowIndex]),
                    $this->activeConnection(),
                );
            }

            $deletedCount = count($this->selectedRowIndexes);
            $this->selectedRowIndexes = [];
            $this->refreshTableData();
            $this->notifySuccess('Rows deleted', "Deleted {$deletedCount} rows.");
        } catch (\Throwable $exception) {
            $this->notifyError('Bulk delete failed', $exception->getMessage());
        }
    }

    public function duplicateRow(int $rowIndex): void
    {
        if (! isset($this->rows[$rowIndex])) {
            return;
        }

        try {
            app(MySqlManager::class)->insertRow(
                $this->selectedDatabase,
                $this->selectedTable,
                $this->formatRowForForm($this->rows[$rowIndex]),
                $this->activeConnection(),
            );

            $this->refreshTableData();
            $this->notifySuccess('Row duplicated', 'The selected row was copied.');
        } catch (\Throwable $exception) {
            $this->notifyError('Duplicate failed', $exception->getMessage());
        }
    }

    public function duplicateSelectedRows(): void
    {
        try {
            foreach ($this->selectedRowIndexes as $rowIndex) {
                if (! isset($this->rows[$rowIndex])) {
                    continue;
                }

                app(MySqlManager::class)->insertRow(
                    $this->selectedDatabase,
                    $this->selectedTable,
                    $this->formatRowForForm($this->rows[$rowIndex]),
                    $this->activeConnection(),
                );
            }

            $duplicatedCount = count($this->selectedRowIndexes);
            $this->selectedRowIndexes = [];
            $this->refreshTableData();
            $this->notifySuccess('Rows duplicated', "Duplicated {$duplicatedCount} rows.");
        } catch (\Throwable $exception) {
            $this->notifyError('Bulk duplicate failed', $exception->getMessage());
        }
    }

    public function togglePinnedTable(string $database, string $table): void
    {
        $key = $this->tableKey($database, $table);

        if (isset($this->pinnedTables[$key])) {
            unset($this->pinnedTables[$key]);
        } else {
            $this->pinnedTables[$key] = [
                'database' => $database,
                'table' => $table,
                'source' => $this->selectedSource,
            ];
        }

        session()->put('dashboard.pinned_tables', $this->pinnedTables);
    }

    public function setColumnWidth(string $column, int $width): void
    {
        if (! in_array($column, collect($this->columns)->pluck('name')->all(), true)) {
            return;
        }

        $this->columnWidths[$column] = max(min($width, 720), 140);
        $this->storeColumnLayout();
    }

    public function moveColumnLeft(string $column): void
    {
        $this->moveColumn($column, -1);
    }

    public function moveColumnRight(string $column): void
    {
        $this->moveColumn($column, 1);
    }

    public function resetColumnLayout(): void
    {
        $layoutKey = $this->layoutKey();
        $layouts = session('dashboard.column_layouts', []);
        unset($layouts[$layoutKey]);
        session()->put('dashboard.column_layouts', $layouts);

        $this->columnOrder = collect($this->columns)->pluck('name')->all();
        $this->columnWidths = [];
        $this->notifySuccess('Layout reset', 'Column order and widths were reset.');
    }

    public function startCreatingRow(): void
    {
        if ($this->selectedTable === '') {
            return;
        }

        $this->showCreateRow = true;
        $this->createRowValues = $this->defaultFormValues();
        $this->clearEditingRow();
    }

    public function cancelCreatingRow(): void
    {
        $this->showCreateRow = false;
        $this->createRowValues = [];
    }

    public function storeNewRow(): void
    {
        if ($this->selectedTable === '') {
            return;
        }

        try {
            app(MySqlManager::class)->insertRow(
                $this->selectedDatabase,
                $this->selectedTable,
                $this->createRowValues,
                $this->activeConnection(),
            );

            $this->refreshTableData();
            $this->notifySuccess('Row inserted', 'The new record is now in the grid.');
        } catch (\Throwable $exception) {
            $this->notifyError('Insert failed', $exception->getMessage());
        }
    }

    public function startEditingRow(int $rowIndex): void
    {
        if (! isset($this->rows[$rowIndex])) {
            return;
        }

        $row = $this->rows[$rowIndex];
        $this->editingRowIndex = $rowIndex;
        $this->editingRowIdentifiers = $this->rowIdentifiers($row);
        $this->editingRowValues = $this->formatRowForForm($row);
        $this->showCreateRow = false;
    }

    public function clearEditingRow(): void
    {
        $this->editingRowIndex = null;
        $this->editingRowIdentifiers = [];
        $this->editingRowValues = [];
    }

    public function saveRow(): void
    {
        if (! $this->canMutateRows() || $this->editingRowIdentifiers === []) {
            return;
        }

        try {
            app(MySqlManager::class)->updateRow(
                $this->selectedDatabase,
                $this->selectedTable,
                $this->editingRowIdentifiers,
                $this->editingRowValues,
                $this->activeConnection(),
            );

            $this->refreshTableData();
            $this->notifySuccess('Row updated', 'Changes saved.');
        } catch (\Throwable $exception) {
            $this->notifyError('Row update failed', $exception->getMessage());
        }
    }

    public function deleteRow(int $rowIndex): void
    {
        if (! $this->canMutateRows() || ! isset($this->rows[$rowIndex])) {
            return;
        }

        try {
            app(MySqlManager::class)->deleteRow(
                $this->selectedDatabase,
                $this->selectedTable,
                $this->rowIdentifiers($this->rows[$rowIndex]),
                $this->activeConnection(),
            );

            $this->refreshTableData();
            $this->notifySuccess('Row deleted', 'The record was removed.');
        } catch (\Throwable $exception) {
            $this->notifyError('Delete failed', $exception->getMessage());
        }
    }

    public function isEditingRow(int $rowIndex): bool
    {
        return $this->editingRowIndex === $rowIndex;
    }

    public function canMutateRows(): bool
    {
        return $this->selectedTable !== '' && $this->primaryKeyColumns !== [];
    }

    public function lastPage(): int
    {
        return max((int) ceil($this->totalRows / max($this->perPage, 1)), 1);
    }

    public function saveConnection(): void
    {
        try {
            $validated = $this->validate([
                'connectionName' => ['required', 'string', 'max:255'],
                'connectionHost' => ['required', 'string', 'max:255'],
                'connectionPort' => ['required', 'integer', 'min:1', 'max:65535'],
                'connectionSshUsername' => ['required', 'string', 'max:255'],
                'connectionPrivateKeyPath' => ['required', 'string', 'max:1024'],
                'connectionDatabaseHost' => ['required', 'string', 'max:255'],
                'connectionDatabasePort' => ['required', 'integer', 'min:1', 'max:65535'],
                'connectionDatabaseUsername' => ['required', 'string', 'max:255'],
                'connectionDatabasePassword' => ['nullable', 'string', 'max:65535'],
            ]);

            $connection = DatabaseConnection::query()->create([
                'name' => $validated['connectionName'],
                'driver' => 'ssh_mysql',
                'host' => $validated['connectionHost'],
                'port' => (int) $validated['connectionPort'],
                'ssh_username' => $validated['connectionSshUsername'],
                'private_key_path' => $validated['connectionPrivateKeyPath'],
                'database_host' => $validated['connectionDatabaseHost'],
                'database_port' => (int) $validated['connectionDatabasePort'],
                'database_username' => $validated['connectionDatabaseUsername'],
                'database_password' => $validated['connectionDatabasePassword'] !== '' ? $validated['connectionDatabasePassword'] : null,
            ]);

            $this->resetConnectionForm();
            $this->selectedSource = 'connection:'.$connection->id;
            $this->refreshWorkspace();
            $this->dispatch('connection-saved');

            $this->notifySuccess('Connection saved', "Saved {$connection->name}.");
        } catch (\Throwable $exception) {
            $this->notifyError('Connection save failed', $exception->getMessage());
        }
    }

    private function refreshWorkspace(): void
    {
        $this->savedConnections = DatabaseConnection::query()
            ->orderBy('name')
            ->get(['id', 'name', 'host', 'ssh_username'])
            ->map(fn (DatabaseConnection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'host' => $connection->host,
                'ssh_username' => $connection->ssh_username,
            ])
            ->all();

        $validSources = collect($this->savedConnections)
            ->pluck('id')
            ->map(fn (int $id): string => 'connection:'.$id)
            ->prepend('local');

        if (! $validSources->contains($this->selectedSource)) {
            $this->selectedSource = 'local';
        }

        $this->databases = app(MySqlManager::class)->listDatabases($this->activeConnection());
        $this->commandPaletteEntries = app(MySqlManager::class)->listTableIndex($this->activeConnection());

        if ($this->databases === []) {
            $this->selectedDatabase = '';
            $this->tables = [];
            $this->selectedTable = '';
            $this->refreshTableData();

            return;
        }

        $databaseNames = collect($this->databases)->pluck('name');

        if (! $databaseNames->contains($this->selectedDatabase)) {
            $this->selectedDatabase = $databaseNames
                ->first(fn (string $name): bool => ! in_array($name, ['information_schema', 'mysql', 'performance_schema', 'sys'], true))
                ?? $databaseNames->first()
                ?? '';
        }

        if ($this->importDatabaseName === '') {
            $this->importDatabaseName = $this->selectedDatabase;
        }

        $this->refreshTables();
    }

    private function refreshTables(): void
    {
        if ($this->selectedDatabase === '') {
            $this->tables = [];
            $this->selectedTable = '';
            $this->refreshTableData();

            return;
        }

        $this->tables = app(MySqlManager::class)->listTables($this->selectedDatabase, $this->activeConnection());
        $tableNames = collect($this->tables)->pluck('name');

        if (! $tableNames->contains($this->selectedTable)) {
            $this->selectedTable = $tableNames->first() ?? '';
        }

        $this->refreshTableData();
    }

    private function refreshTableData(): void
    {
        $this->clearEditingRow();
        $this->selectedRowIndexes = [];

        if ($this->selectedDatabase === '' || $this->selectedTable === '') {
            $this->columns = [];
            $this->rows = [];
            $this->createRowValues = [];
            $this->primaryKeyColumns = [];
            $this->foreignKeys = [];
            $this->relatedRecordPreviews = [];
            $this->columnOrder = [];
            $this->columnWidths = [];
            $this->totalRows = 0;
            $this->showCreateRow = false;

            return;
        }

        $manager = app(MySqlManager::class);
        $connection = $this->activeConnection();
        $this->columns = $manager->getTableColumns($this->selectedDatabase, $this->selectedTable, $connection);
        $this->foreignKeys = $manager->getForeignKeys($this->selectedDatabase, $this->selectedTable, $connection);
        $this->primaryKeyColumns = collect($this->columns)
            ->filter(fn (array $column): bool => $column['primary'])
            ->pluck('name')
            ->values()
            ->all();

        $validSortColumns = collect($this->columns)->pluck('name')->all();

        if (! in_array($this->sortColumn, $validSortColumns, true)) {
            $this->sortColumn = $this->primaryKeyColumns[0] ?? ($validSortColumns[0] ?? '');
            $this->sortDirection = 'asc';
        }

        $tableData = $manager->getTableRows(
            $this->selectedDatabase,
            $this->selectedTable,
            $this->page,
            $this->perPage,
            $this->rowSearch,
            $this->sortColumn !== '' ? $this->sortColumn : null,
            $this->sortDirection,
            $connection,
        );

        $this->rows = $tableData['rows'];
        $this->totalRows = $tableData['total'];
        $this->relatedRecordPreviews = $this->loadRelatedRecordPreviews($connection);
        $this->hydrateColumnLayout();

        $this->rememberTable($this->selectedDatabase, $this->selectedTable);

        if ($this->showCreateRow && $this->createRowValues === []) {
            $this->createRowValues = $this->defaultFormValues();
        }
    }

    /**
     * @return array<string, array{summary: string, fields: array<int, array{label: string, value: string}>}>
     */
    private function loadRelatedRecordPreviews(?DatabaseConnection $connection = null): array
    {
        $manager = app(MySqlManager::class);
        $foreignKeysByColumn = collect($this->foreignKeys)->keyBy('column');
        $previews = [];
        $resolvedPreviews = [];

        foreach ($this->rows as $rowIndex => $row) {
            foreach ($foreignKeysByColumn as $columnName => $relationship) {
                $value = $row[$columnName] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                $previewKey = implode(':', [
                    $relationship['referenced_table'],
                    $relationship['referenced_column'],
                    (string) $value,
                ]);

                if (! array_key_exists($previewKey, $resolvedPreviews)) {
                    $resolvedPreviews[$previewKey] = $manager->getRelatedRecordPreview(
                        $this->selectedDatabase,
                        $relationship['referenced_table'],
                        $relationship['referenced_column'],
                        $value,
                        $connection,
                    );
                }

                if ($resolvedPreviews[$previewKey] === null) {
                    continue;
                }

                $previews[$rowIndex.':'.$columnName] = $resolvedPreviews[$previewKey];
            }
        }

        return $previews;
    }

    /**
     * @return array<string, string>
     */
    private function defaultFormValues(): array
    {
        return collect($this->columns)
            ->reject(fn (array $column): bool => $column['generated'] || $column['auto_increment'])
            ->mapWithKeys(fn (array $column): array => [$column['name'] => $column['nullable'] ? '__NULL__' : ''])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowIdentifiers(array $row): array
    {
        return collect($this->primaryKeyColumns)
            ->mapWithKeys(fn (string $column): array => [$column => $row[$column]])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function formatRowForForm(array $row): array
    {
        return collect($this->columns)
            ->reject(fn (array $column): bool => $column['generated'])
            ->mapWithKeys(function (array $column) use ($row): array {
                $value = $row[$column['name']] ?? null;

                return [$column['name'] => $value === null ? '__NULL__' : (string) $value];
            })
            ->all();
    }

    private function resetSchemaForm(): void
    {
        $this->newColumnName = '';
        $this->newColumnType = 'varchar';
        $this->newColumnLength = '255';
        $this->newColumnDefault = '';
        $this->newColumnNullable = true;
    }

    private function resetConnectionForm(): void
    {
        $this->connectionName = '';
        $this->connectionHost = '';
        $this->connectionPort = 22;
        $this->connectionSshUsername = 'forge';
        $this->connectionPrivateKeyPath = '';
        $this->connectionDatabaseHost = '127.0.0.1';
        $this->connectionDatabasePort = 3306;
        $this->connectionDatabaseUsername = '';
        $this->connectionDatabasePassword = '';
    }

    private function activeConnection(): ?DatabaseConnection
    {
        if (! str_starts_with($this->selectedSource, 'connection:')) {
            return null;
        }

        $connectionId = (int) Str::after($this->selectedSource, 'connection:');

        if ($connectionId < 1) {
            return null;
        }

        return DatabaseConnection::query()->find($connectionId);
    }

    private function rememberTable(string $database, string $table): void
    {
        if ($database === '' || $table === '') {
            return;
        }

        $key = $this->tableKey($database, $table);
        unset($this->recentTables[$key]);

        $this->recentTables = [
            $key => [
                'database' => $database,
                'table' => $table,
                'source' => $this->selectedSource,
            ],
            ...$this->recentTables,
        ];

        $this->recentTables = array_slice($this->recentTables, 0, 8, true);
        session()->put('dashboard.recent_tables', $this->recentTables);
    }

    private function tableKey(string $database, string $table): string
    {
        return $this->selectedSource.'|'.$database.'|'.$table;
    }

    private function moveColumn(string $column, int $direction): void
    {
        $orderedColumns = $this->columnOrder !== [] ? $this->columnOrder : collect($this->columns)->pluck('name')->all();
        $currentIndex = array_search($column, $orderedColumns, true);

        if ($currentIndex === false) {
            return;
        }

        $targetIndex = max(0, min(count($orderedColumns) - 1, $currentIndex + $direction));

        if ($targetIndex === $currentIndex) {
            return;
        }

        $movedColumn = $orderedColumns[$currentIndex];
        array_splice($orderedColumns, $currentIndex, 1);
        array_splice($orderedColumns, $targetIndex, 0, [$movedColumn]);

        $this->columnOrder = array_values($orderedColumns);
        $this->storeColumnLayout();
    }

    private function hydrateColumnLayout(): void
    {
        $columnNames = collect($this->columns)->pluck('name')->all();
        $savedLayout = session('dashboard.column_layouts', [])[$this->layoutKey()] ?? [];
        $savedOrder = collect($savedLayout['order'] ?? [])
            ->filter(fn (string $column): bool => in_array($column, $columnNames, true))
            ->values()
            ->all();

        $this->columnOrder = [
            ...$savedOrder,
            ...array_values(array_diff($columnNames, $savedOrder)),
        ];

        $this->columnWidths = collect($savedLayout['widths'] ?? [])
            ->filter(fn (mixed $width, string $column): bool => in_array($column, $columnNames, true))
            ->map(fn (mixed $width): int => max(min((int) $width, 720), 140))
            ->all();
    }

    private function storeColumnLayout(): void
    {
        if ($this->selectedDatabase === '' || $this->selectedTable === '') {
            return;
        }

        $layouts = session('dashboard.column_layouts', []);
        $layouts[$this->layoutKey()] = [
            'order' => $this->columnOrder,
            'widths' => $this->columnWidths,
        ];

        session()->put('dashboard.column_layouts', $layouts);
    }

    private function layoutKey(): string
    {
        return $this->tableKey($this->selectedDatabase, $this->selectedTable);
    }

    private function notifySuccess(string $title, string $message): void
    {
        session()->flash('status', $message);
        $this->dispatch('toast', level: 'success', title: $title, message: $message);
    }

    private function notifyError(string $title, string $message): void
    {
        $this->dispatch('toast', level: 'error', title: $title, message: $message);
    }
}; ?>

@php
    /** @var Collection<int, array{name: string, tables: int, size_bytes: int, system: bool}> $visibleDatabases */
    $selectedConnectionMeta = collect($savedConnections)->firstWhere('id', (int) Str::after($selectedSource, 'connection:'));
    $sourceLabel = $selectedSource === 'local'
        ? 'Local Herd'
        : ($selectedConnectionMeta['name'] ?? 'Saved connection');
    $visibleDatabases = collect($databases)
        ->filter(fn (array $database): bool => $showSystemDatabases || ! $database['system'])
        ->values();
    $selectedDatabaseMeta = $visibleDatabases->firstWhere('name', $selectedDatabase)
        ?? collect($databases)->firstWhere('name', $selectedDatabase);
    $filteredTables = collect($tables)
        ->filter(fn (array $table): bool => $tableSearch === '' || Str::contains(Str::lower($table['name']), Str::lower($tableSearch)))
        ->values();
    $selectedTableMeta = collect($tables)->firstWhere('name', $selectedTable);
    $columnTypeOptions = ['varchar', 'text', 'int', 'bigint', 'boolean', 'date', 'datetime', 'timestamp', 'json', 'decimal'];
    $foreignKeyMap = collect($foreignKeys)->keyBy('column');
    $relatedPreviewMap = collect($relatedRecordPreviews);
    $orderedColumns = collect($columnOrder)
        ->map(fn (string $columnName): ?array => collect($columns)->firstWhere('name', $columnName))
        ->filter()
        ->values();
    if ($orderedColumns->isEmpty()) {
        $orderedColumns = collect($columns);
    }
    $pinnedTableItems = collect($pinnedTables)
        ->filter(fn (array $item): bool => ($item['source'] ?? 'local') === $selectedSource)
        ->values();
    $recentTableItems = collect($recentTables)
        ->filter(fn (array $item): bool => ($item['source'] ?? 'local') === $selectedSource)
        ->values();
@endphp

<div class="min-h-screen bg-[var(--color-linear-950)] text-[var(--color-linear-200)]">
    <section
        class="min-h-screen xl:pl-[15vw]"
        x-data="{
            dbOpen: false,
            actionsOpen: false,
            sourceOpen: false,
            recentOpen: false,
            connectionModalOpen: false,
            migrationModalOpen: false,
            commandPaletteOpen: false,
            commandSearch: '',
            jsonPreviewOpen: false,
            jsonPreviewTitle: '',
            jsonPreviewValue: '',
            shortcutPrefix: null,
            toasts: [],
            activeCell: { row: null, col: null },
            editingCell: { row: null, col: null },
            editDraft: '',
            cellSelector(row, col) {
                return `[data-grid-cell='${row}-${col}']`;
            },
            inputSelector(row, col) {
                return `[data-grid-input='${row}-${col}']`;
            },
            setActive(row, col) {
                this.activeCell = { row, col };
            },
            focusCell(row, col) {
                const target = this.$root.querySelector(this.cellSelector(row, col));
                if (target) {
                    target.focus();
                    this.setActive(row, col);
                }
            },
            moveCell(row, col, rowDelta, colDelta) {
                this.focusCell(row + rowDelta, col + colDelta);
            },
            beginEdit(row, col, value) {
                this.editingCell = { row, col };
                this.setActive(row, col);
                this.editDraft = value === null ? '__NULL__' : String(value);
                this.$nextTick(() => this.$root.querySelector(this.inputSelector(row, col))?.focus());
            },
            cancelEdit(row, col) {
                this.editingCell = { row: null, col: null };
                this.$nextTick(() => this.focusCell(row, col));
            },
            commitEdit(row, col, columnName) {
                this.editingCell = { row: null, col: null };
                $wire.updateCell(row, columnName, this.editDraft);
                this.$nextTick(() => this.focusCell(row, col));
            },
            commitAndMove(row, col, columnName, rowDelta, colDelta) {
                this.commitEdit(row, col, columnName);
                this.$nextTick(() => this.focusCell(row + rowDelta, col + colDelta));
            },
            openJsonPreview(title, value) {
                this.jsonPreviewTitle = title;
                try {
                    this.jsonPreviewValue = JSON.stringify(JSON.parse(value), null, 2);
                } catch (_error) {
                    this.jsonPreviewValue = value;
                }
                this.jsonPreviewOpen = true;
            },
            fuzzyMatch(query, value) {
                const needle = query.toLowerCase().trim();
                const haystack = value.toLowerCase();
                if (!needle) return true;
                let index = 0;
                for (const char of needle) {
                    index = haystack.indexOf(char, index);
                    if (index === -1) return false;
                    index += 1;
                }
                return true;
            },
            pushToast(detail) {
                const toast = {
                    id: Date.now() + Math.random(),
                    level: detail.level ?? 'success',
                    title: detail.title ?? 'Update',
                    message: detail.message ?? '',
                };
                this.toasts.push(toast);
                setTimeout(() => {
                    this.toasts = this.toasts.filter(item => item.id !== toast.id);
                }, toast.level === 'error' ? 5200 : 3200);
            },
            beginShortcut(prefix) {
                this.shortcutPrefix = prefix;
                clearTimeout(this.shortcutTimer);
                this.shortcutTimer = setTimeout(() => this.shortcutPrefix = null, 800);
            },
            applyColumnWidth(columnName, width) {
                $wire.setColumnWidth(columnName, Math.round(width));
            },
            startResize(event, columnName) {
                const th = event.target.closest('th');
                if (!th) return;
                const startX = event.clientX;
                const startWidth = th.getBoundingClientRect().width;
                const onMove = moveEvent => {
                    const nextWidth = Math.max(140, Math.min(720, startWidth + (moveEvent.clientX - startX)));
                    th.style.width = `${nextWidth}px`;
                    th.style.minWidth = `${nextWidth}px`;
                };
                const onUp = upEvent => {
                    const finalWidth = Math.max(140, Math.min(720, startWidth + (upEvent.clientX - startX)));
                    this.applyColumnWidth(columnName, finalWidth);
                    window.removeEventListener('mousemove', onMove);
                    window.removeEventListener('mouseup', onUp);
                };
                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onUp);
            },
        }"
        x-on:keydown.window.prevent.meta.k="commandPaletteOpen = true"
        x-on:keydown.window.prevent.ctrl.k="commandPaletteOpen = true"
        x-on:keydown.window.prevent.slash="$refs.rowSearchInput?.focus()"
        x-on:keydown.window.prevent.shift.n="$wire.startCreatingRow()"
        x-on:keydown.window.prevent.bracketleft="$wire.previousPage()"
        x-on:keydown.window.prevent.bracketright="$wire.nextPage()"
        x-on:keydown.window="if ($event.key === 'g' && !['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)) { beginShortcut('g') } else if (shortcutPrefix === 'g' && $event.key.toLowerCase() === 'd') { $wire.switchTab('data'); shortcutPrefix = null } else if (shortcutPrefix === 'g' && $event.key.toLowerCase() === 's') { $wire.switchTab('schema'); shortcutPrefix = null }"
        x-on:keydown.window="if ($event.key.toLowerCase() === 'e' && activeCell.row !== null && !['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)) { $wire.startEditingRow(activeCell.row) }"
        x-on:toast.window="pushToast($event.detail)"
    >
        <aside
            class="flex min-h-screen flex-col border-r border-[var(--color-linear-775)] bg-[var(--color-linear-950)] xl:fixed xl:inset-y-0 xl:left-0 xl:w-[15vw]"
        >
            <div class="border-b border-[var(--color-linear-775)] px-4 pb-4 pt-5">
                <div class="flex items-center gap-3 px-1">
                    <div class="flex size-7 items-center justify-center rounded-[8px] bg-[var(--color-linear-blue)] text-xs font-semibold text-white shadow-[0_1px_2px_rgba(0,0,0,0.35)]">
                        HS
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-[var(--color-linear-200)]">Herd Studio</p>
                        <p class="truncate text-xs text-[var(--color-linear-400)]">Local MySQL workspace</p>
                    </div>
                </div>

                <div class="relative mt-4">
                    <label class="mb-2 block px-1 text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Source</label>
                    <button
                        type="button"
                        x-on:click="sourceOpen = ! sourceOpen"
                        class="flex w-full items-center justify-between rounded-[10px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] px-3 py-2.5 text-left shadow-[0_1px_1px_rgba(0,0,0,0.15)] transition hover:border-[var(--color-linear-600)]"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-[var(--color-linear-200)]">{{ $sourceLabel }}</p>
                            <p class="truncate pt-1 text-xs text-[var(--color-linear-400)]">
                                @if ($selectedConnectionMeta)
                                    {{ $selectedConnectionMeta['ssh_username'] }}@{{ $selectedConnectionMeta['host'] }}
                                @else
                                    Local Herd MySQL
                                @endif
                            </p>
                        </div>
                        <span class="text-xs text-[var(--color-linear-400)]">{{ count($savedConnections) + 1 }}</span>
                    </button>

                    <div
                        x-cloak
                        x-show="sourceOpen"
                        x-transition.opacity.duration.120ms
                        x-on:click.away="sourceOpen = false"
                        class="absolute inset-x-0 top-[calc(100%+0.5rem)] z-30 rounded-[12px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] p-2 shadow-[0_12px_32px_rgba(0,0,0,0.38)]"
                    >
                        <div class="space-y-1">
                            <button
                                type="button"
                                x-on:click="$wire.set('selectedSource', 'local'); sourceOpen = false"
                                @class([
                                    'flex w-full min-w-0 items-center justify-between rounded-[8px] px-3 py-2 text-left transition',
                                    'bg-[var(--color-linear-blue)]/16 text-[var(--color-linear-200)]' => $selectedSource === 'local',
                                    'text-[var(--color-linear-300)] hover:bg-[var(--color-linear-800)]' => $selectedSource !== 'local',
                                ])
                            >
                                <span class="truncate text-sm font-medium">Local Herd</span>
                                <span class="text-xs text-[var(--color-linear-400)]">Default</span>
                            </button>

                            @foreach ($savedConnections as $connection)
                                <button
                                    type="button"
                                    x-on:click="$wire.set('selectedSource', 'connection:{{ $connection['id'] }}'); sourceOpen = false"
                                    @class([
                                        'flex w-full min-w-0 items-center justify-between rounded-[8px] px-3 py-2 text-left transition',
                                        'bg-[var(--color-linear-blue)]/16 text-[var(--color-linear-200)]' => $selectedSource === 'connection:'.$connection['id'],
                                        'text-[var(--color-linear-300)] hover:bg-[var(--color-linear-800)]' => $selectedSource !== 'connection:'.$connection['id'],
                                    ])
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-medium">{{ $connection['name'] }}</div>
                                        <div class="truncate pt-0.5 text-xs text-[var(--color-linear-400)]">{{ $connection['ssh_username'] }}@{{ $connection['host'] }}</div>
                                    </div>
                                </button>
                            @endforeach
                        </div>

                        <div class="mt-2 border-t border-[var(--color-linear-775)] pt-2">
                            <button
                                type="button"
                                x-on:click="sourceOpen = false; connectionModalOpen = true; $nextTick(() => document.getElementById('connection-name')?.focus())"
                                class="flex w-full items-center justify-center rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] font-medium text-[var(--color-linear-200)] transition hover:border-[var(--color-linear-blue)] hover:text-white"
                            >
                                New Connection
                            </button>
                        </div>
                    </div>

                    <p class="px-1 pt-2 text-xs text-[var(--color-linear-400)]">
                        @if ($selectedConnectionMeta)
                            {{ $selectedConnectionMeta['ssh_username'] }}@{{ $selectedConnectionMeta['host'] }}
                        @else
                            Browse your local Herd MySQL instance.
                        @endif
                    </p>
                </div>

                <div class="relative mt-4">
                    <button
                        type="button"
                        x-on:click="dbOpen = ! dbOpen"
                        class="flex w-full items-center justify-between rounded-[10px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] px-3 py-2.5 text-left shadow-[0_1px_1px_rgba(0,0,0,0.15)] transition hover:border-[var(--color-linear-600)]"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Database</p>
                            <p class="truncate pt-1 text-sm font-medium text-[var(--color-linear-200)]">{{ $selectedDatabase ?: 'Select database' }}</p>
                        </div>
                        <span class="text-xs text-[var(--color-linear-400)]">{{ $visibleDatabases->count() }}</span>
                    </button>

                    <div
                        x-cloak
                        x-show="dbOpen"
                        x-transition.opacity.duration.120ms
                        x-on:click.away="dbOpen = false"
                        class="absolute inset-x-0 top-[calc(100%+0.5rem)] z-30 max-h-80 overflow-y-auto rounded-[12px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] p-2 shadow-[0_12px_32px_rgba(0,0,0,0.38)]"
                    >
                        @foreach ($visibleDatabases as $database)
                            <button
                                type="button"
                                x-on:click="$wire.selectDatabase('{{ $database['name'] }}'); dbOpen = false"
                                @class([
                                    'flex w-full min-w-0 items-center justify-between rounded-[8px] px-3 py-2 text-left transition',
                                    'bg-[var(--color-linear-blue)]/16 text-[var(--color-linear-200)]' => $selectedDatabase === $database['name'],
                                    'text-[var(--color-linear-300)] hover:bg-[var(--color-linear-800)]' => $selectedDatabase !== $database['name'],
                                ])
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">{{ $database['name'] }}</div>
                                    <div class="pt-0.5 text-xs text-[var(--color-linear-400)]">{{ $database['tables'] }} tables</div>
                                </div>
                                <div class="pl-3 text-right text-xs text-[var(--color-linear-400)]">{{ Number::fileSize($database['size_bytes']) }}</div>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            @if ($pinnedTableItems->isNotEmpty() || $recentTableItems->isNotEmpty())
                <div class="border-b border-[var(--color-linear-775)] px-4 py-3">
                    @if ($pinnedTableItems->isNotEmpty())
                        <div>
                            <p class="mb-2 text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Pinned</p>
                            <div class="space-y-1">
                                @foreach ($pinnedTableItems as $item)
                                    <button
                                        type="button"
                                        wire:click="openTable('{{ $item['database'] }}', '{{ $item['table'] }}')"
                                        class="flex w-full items-center justify-between rounded-[8px] px-3 py-2 text-left text-[13px] text-[var(--color-linear-300)] transition hover:bg-[var(--color-linear-900)] hover:text-[var(--color-linear-200)]"
                                    >
                                        <span class="truncate">{{ $item['database'] }}.{{ $item['table'] }}</span>
                                        <span class="text-[11px] text-[var(--color-linear-blue)]">Pinned</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($recentTableItems->isNotEmpty())
                        <div class="@if($pinnedTableItems->isNotEmpty()) mt-3 @endif">
                            <button
                                type="button"
                                x-on:click="recentOpen = ! recentOpen"
                                class="mb-2 flex w-full items-center justify-between rounded-[8px] px-2 py-1 text-left transition hover:bg-[var(--color-linear-900)]"
                            >
                                <span class="text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Recent</span>
                                <span class="text-[11px] text-[var(--color-linear-500)]" x-text="recentOpen ? 'Hide' : 'Show'"></span>
                            </button>
                            <div x-cloak x-show="recentOpen" x-transition.opacity.duration.120ms class="space-y-1">
                                @foreach ($recentTableItems as $item)
                                    <button
                                        type="button"
                                        wire:click="openTable('{{ $item['database'] }}', '{{ $item['table'] }}')"
                                        class="flex w-full items-center justify-between rounded-[8px] px-3 py-2 text-left text-[13px] text-[var(--color-linear-300)] transition hover:bg-[var(--color-linear-900)] hover:text-[var(--color-linear-200)]"
                                    >
                                        <span class="truncate">{{ $item['database'] }}.{{ $item['table'] }}</span>
                                        <span class="text-[11px] text-[var(--color-linear-500)]">Recent</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <div class="border-b border-[var(--color-linear-775)] px-4 py-3">
                <label for="table-search" class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Tables</label>
                <input
                    id="table-search"
                    type="text"
                    wire:model.live.debounce.200ms="tableSearch"
                    placeholder="Search tables"
                    class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                />
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-3 py-3">
                <div class="space-y-1">
                    @forelse ($filteredTables as $table)
                        <div
                            @class([
                                'group flex w-full min-w-0 items-center gap-2 rounded-[8px] px-2 py-1 transition',
                                'bg-white/8 ring-1 ring-inset ring-white/4' => $selectedTable === $table['name'],
                                'hover:bg-[var(--color-linear-900)]' => $selectedTable !== $table['name'],
                            ])
                        >
                            <button
                                type="button"
                                wire:click="selectTable('{{ $table['name'] }}')"
                                class="flex min-w-0 flex-1 items-center justify-between rounded-[8px] px-1 py-1 text-left"
                            >
                                <span class="min-w-0 flex-1 truncate text-[13px] font-medium text-[var(--color-linear-300)] group-hover:text-[var(--color-linear-200)]">{{ $table['name'] }}</span>
                                <span class="rounded-full bg-[var(--color-linear-850)] px-2 py-0.5 text-[11px] text-[var(--color-linear-400)] group-hover:text-[var(--color-linear-300)]">
                                    {{ Number::format($table['rows']) }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="togglePinnedTable('{{ $selectedDatabase }}', '{{ $table['name'] }}')"
                                class="rounded-[8px] px-2 py-1 text-[11px] text-[var(--color-linear-400)] transition hover:bg-[var(--color-linear-850)] hover:text-[var(--color-linear-200)]"
                            >
                                {{ isset($pinnedTables[$selectedSource.'|'.$selectedDatabase.'|'.$table['name']]) ? 'Unpin' : 'Pin' }}
                            </button>
                        </div>
                    @empty
                        <div class="rounded-[10px] border border-dashed border-[var(--color-linear-600)] px-3 py-4 text-sm text-[var(--color-linear-400)]">
                            No tables match this filter.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="mt-auto border-t border-[var(--color-linear-775)] px-4 py-4">
                <button
                    type="button"
                    x-on:click="actionsOpen = ! actionsOpen"
                    class="flex w-full items-center justify-between rounded-[10px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] px-3 py-2.5 text-left shadow-[0_1px_1px_rgba(0,0,0,0.15)] transition hover:border-[var(--color-linear-600)]"
                >
                    <div>
                        <p class="text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Actions</p>
                        <p class="pt-1 text-sm font-medium text-[var(--color-linear-200)]">Import, export, create</p>
                    </div>
                    <span class="text-xs text-[var(--color-linear-400)]" x-text="actionsOpen ? 'Hide' : 'Show'"></span>
                </button>

                <div x-cloak x-show="actionsOpen" x-transition.opacity.duration.120ms class="mt-3 space-y-3">
                        <div class="space-y-2 rounded-[12px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] p-3">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-medium text-[var(--color-linear-300)]">Quick actions</p>
                                @if ($selectedDatabase !== '')
                                    <a
                                        href="{{ route('databases.export', ['database' => $selectedDatabase, 'source' => $selectedSource]) }}"
                                        class="text-xs font-medium text-[var(--color-linear-blue)]"
                                    >
                                        Export
                                </a>
                            @endif
                        </div>

                        <button
                            type="button"
                            wire:click="startCreatingRow"
                            class="flex w-full items-center justify-between rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-left text-[13px] text-[var(--color-linear-200)] transition hover:border-[var(--color-linear-blue)]"
                        >
                            <span>Add row</span>
                            <span class="text-[var(--color-linear-400)]">Data tab</span>
                        </button>
                    </div>

                    <form wire:submit="importTableCsv" class="space-y-2 rounded-[12px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-medium text-[var(--color-linear-300)]">Import CSV</p>
                            <span class="text-[11px] text-[var(--color-linear-500)]">{{ $selectedTable !== '' ? $selectedTable : 'Select table' }}</span>
                        </div>
                        <input
                            type="file"
                            wire:model="tableImportFile"
                            accept=".csv,.txt"
                            @disabled($selectedTable === '')
                            class="block w-full text-[12px] text-[var(--color-linear-400)] file:mr-3 file:rounded-[8px] file:border-0 file:bg-[var(--color-linear-800)] file:px-3 file:py-2 file:text-[12px] file:font-medium file:text-[var(--color-linear-200)] disabled:opacity-40"
                        />
                        <button
                            type="submit"
                            @disabled($selectedTable === '')
                            class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] font-medium text-[var(--color-linear-200)] transition hover:border-[var(--color-linear-blue)] disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Import rows
                        </button>
                    </form>

                    <form wire:submit="createDatabase" class="space-y-2 rounded-[12px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] p-3">
                        <p class="text-xs font-medium text-[var(--color-linear-300)]">Create database</p>
                        <input
                            type="text"
                            wire:model="newDatabaseName"
                            placeholder="new_workspace"
                            class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                        />
                        <button type="submit" class="w-full rounded-[8px] bg-[var(--color-linear-blue)] px-3 py-2 text-[13px] font-medium text-white transition hover:brightness-110">
                            Create
                        </button>
                    </form>

                    <form wire:submit="importDatabase" class="space-y-2 rounded-[12px] border border-[var(--color-linear-750)] bg-[var(--color-linear-900)] p-3">
                        <p class="text-xs font-medium text-[var(--color-linear-300)]">Import SQL</p>
                        <input
                            type="text"
                            wire:model="importDatabaseName"
                            placeholder="target_database"
                            class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                        />
                        <input
                            type="file"
                            wire:model="importFile"
                            accept=".sql,.txt"
                            class="block w-full text-[12px] text-[var(--color-linear-400)] file:mr-3 file:rounded-[8px] file:border-0 file:bg-[var(--color-linear-800)] file:px-3 file:py-2 file:text-[12px] file:font-medium file:text-[var(--color-linear-200)]"
                        />
                        <button type="submit" class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] font-medium text-[var(--color-linear-200)] transition hover:border-[var(--color-linear-blue)]">
                            Import
                        </button>
                    </form>

                </div>
            </div>
        </aside>

        <main class="flex min-w-0 flex-col bg-[var(--color-linear-950)] xl:min-h-screen xl:w-[85vw]">
            <div class="border-b border-[var(--color-linear-775)] px-6 py-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-[11px] font-medium uppercase tracking-[0.18em] text-[var(--color-linear-400)]">Workspace</p>
                        <h1 class="pt-1 text-[18px] font-semibold text-[var(--color-linear-200)]">
                            {{ $selectedTable !== '' ? "{$selectedDatabase}.{$selectedTable}" : ($selectedDatabase ?: 'Select a database') }}
                        </h1>
                        <p class="pt-1 text-sm text-[var(--color-linear-400)]">
                            @if ($selectedTableMeta)
                                {{ $sourceLabel }} · {{ Number::format($totalRows) }} rows · {{ Number::fileSize($selectedTableMeta['size_bytes']) }} · {{ $selectedTableMeta['engine'] }}
                            @elseif ($selectedDatabaseMeta)
                                {{ $sourceLabel }} · {{ $selectedDatabaseMeta['tables'] }} tables · {{ Number::fileSize($selectedDatabaseMeta['size_bytes']) }}
                            @else
                                {{ $sourceLabel }} · searchable, sortable MySQL editing.
                            @endif
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            x-on:click="commandPaletteOpen = true"
                            class="rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-3 py-2 text-[13px] font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-blue)] hover:text-[var(--color-linear-200)]"
                        >
                            Command Palette
                        </button>
                        @if ($selectedTable !== '')
                            <button
                                type="button"
                                wire:click="resetColumnLayout"
                                class="rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-3 py-2 text-[13px] font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-blue)] hover:text-[var(--color-linear-200)]"
                            >
                                Reset Layout
                            </button>
                        @endif
                        <button
                            type="button"
                            wire:click="switchTab('data')"
                            @class([
                                'rounded-[8px] px-3 py-2 text-[13px] font-medium transition',
                                'bg-[var(--color-linear-800)] text-[var(--color-linear-200)] ring-1 ring-inset ring-white/4' => $activeTab === 'data',
                                'text-[var(--color-linear-400)] hover:bg-[var(--color-linear-900)] hover:text-[var(--color-linear-200)]' => $activeTab !== 'data',
                            ])
                        >
                            Data
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('schema')"
                            @class([
                                'rounded-[8px] px-3 py-2 text-[13px] font-medium transition',
                                'bg-[var(--color-linear-800)] text-[var(--color-linear-200)] ring-1 ring-inset ring-white/4' => $activeTab === 'schema',
                                'text-[var(--color-linear-400)] hover:bg-[var(--color-linear-900)] hover:text-[var(--color-linear-200)]' => $activeTab !== 'schema',
                            ])
                        >
                            Schema
                        </button>
                    </div>
                </div>
            </div>

            <div
                wire:loading.flex
                wire:target="createDatabase,importDatabase,importTableCsv,addColumn,updateCell,bulkDeleteSelectedRows,duplicateRow,duplicateSelectedRows,storeNewRow,saveRow,deleteRow,saveConnection,setColumnWidth,moveColumnLeft,moveColumnRight,resetColumnLayout"
                class="fixed inset-x-0 top-0 z-[70] h-1 bg-transparent"
            >
                <div class="h-full w-full animate-pulse bg-[var(--color-linear-blue)]/80"></div>
            </div>

            <div class="fixed right-6 top-6 z-[60] flex w-full max-w-sm flex-col gap-3">
                @if (session('status'))
                    <div class="rounded-[14px] border border-[var(--color-linear-blue)]/28 bg-[var(--color-linear-925)] px-4 py-3 shadow-[0_24px_80px_rgba(0,0,0,0.45)]">
                        <p class="text-[11px] font-medium uppercase tracking-[0.14em] text-[var(--color-linear-400)]">Success</p>
                        <p class="pt-1 text-sm text-[var(--color-linear-200)]">{{ session('status') }}</p>
                    </div>
                @endif

                <template x-for="toast in toasts" :key="toast.id">
                    <div
                        x-transition.opacity.duration.180ms
                        class="rounded-[14px] border px-4 py-3 shadow-[0_24px_80px_rgba(0,0,0,0.45)]"
                        :class="toast.level === 'error'
                            ? 'border-red-400/25 bg-[rgba(58,16,19,0.94)]'
                            : 'border-[var(--color-linear-blue)]/28 bg-[var(--color-linear-925)]'"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-[0.14em]" :class="toast.level === 'error' ? 'text-red-200/80' : 'text-[var(--color-linear-400)]'" x-text="toast.title"></p>
                                <p class="pt-1 text-sm" :class="toast.level === 'error' ? 'text-red-50' : 'text-[var(--color-linear-200)]'" x-text="toast.message"></p>
                            </div>
                            <button type="button" class="text-xs text-[var(--color-linear-400)]" x-on:click="toasts = toasts.filter(item => item.id !== toast.id)">Close</button>
                        </div>
                    </div>
                </template>
            </div>

            @if ($activeTab === 'data')
                <section class="flex min-h-0 flex-1 flex-col">
                    <div class="sticky top-0 z-20 border-b border-[var(--color-linear-775)] bg-[var(--color-linear-950)]/95 px-6 py-4 backdrop-blur-md">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <input
                                    type="text"
                                    wire:model.live.debounce.250ms="rowSearch"
                                    x-ref="rowSearchInput"
                                    placeholder="Search rows"
                                    class="w-full max-w-md rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                                />
                                <div class="rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-3 py-2 text-xs text-[var(--color-linear-400)]">
                                    {{ Number::format($totalRows) }} rows
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($selectedDatabase !== '' && $selectedTable !== '')
                                    <a
                                        href="{{ route('tables.export-csv', ['database' => $selectedDatabase, 'table' => $selectedTable, 'source' => $selectedSource, 'search' => $rowSearch, 'sort_column' => $sortColumn, 'sort_direction' => $sortDirection]) }}"
                                        class="rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-3 py-2 text-[12px] font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-blue)] hover:text-[var(--color-linear-200)]"
                                    >
                                        Export CSV
                                    </a>
                                @endif
                                <button
                                    type="button"
                                    wire:click="previousPage"
                                    @disabled($page === 1)
                                    class="rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-3 py-2 text-[12px] font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-blue)] disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    Prev
                                </button>
                                <div class="rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-3 py-2 text-[12px] text-[var(--color-linear-400)]">
                                    Page {{ $page }} / {{ $this->lastPage() }}
                                </div>
                                <button
                                    type="button"
                                    wire:click="nextPage"
                                    @disabled($page >= $this->lastPage())
                                    class="rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-3 py-2 text-[12px] font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-blue)] disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    Next
                                </button>
                            </div>
                        </div>

                        @if ($selectedRowIndexes !== [])
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-3 py-2.5">
                                <div class="text-[13px] text-[var(--color-linear-300)]">
                                    {{ count($selectedRowIndexes) }} rows selected
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="duplicateSelectedRows" class="rounded-[8px] border border-[var(--color-linear-600)] px-3 py-2 text-[12px] font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-blue)]">Duplicate</button>
                                    <button type="button" wire:click="bulkDeleteSelectedRows" class="rounded-[8px] border border-transparent bg-red-500/12 px-3 py-2 text-[12px] font-medium text-red-200 transition hover:bg-red-500/18">Delete</button>
                                    <button type="button" wire:click="clearSelectedRows" class="rounded-[8px] border border-[var(--color-linear-600)] px-3 py-2 text-[12px] font-medium text-[var(--color-linear-300)]">Clear</button>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="min-h-0 flex-1 overflow-auto px-6 py-5" wire:loading.class="opacity-60">
                        <div class="overflow-hidden rounded-[12px] border border-[var(--color-linear-775)] bg-[var(--color-linear-900)] shadow-[0_1px_2px_rgba(0,0,0,0.25)]">
                            @if ($selectedTable === '')
                                <div class="flex min-h-[28rem] items-center justify-center px-6 text-sm text-[var(--color-linear-400)]">
                                    Select a database and table from the sidebar to begin.
                                </div>
                            @else
                                <div class="overflow-auto">
                                    <table class="min-w-full border-separate border-spacing-0">
                                        <thead class="sticky top-0 z-10">
                                            <tr>
                                                <th class="sticky left-0 z-20 border-b border-r border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-3 py-3 text-left text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">
                                                    Select
                                                </th>
                                                @foreach ($orderedColumns as $column)
                                                    @php
                                                        $columnRelationship = $foreignKeyMap[$column['name']] ?? null;
                                                        $columnWidth = $columnWidths[$column['name']] ?? 220;
                                                    @endphp
                                                    <th
                                                        wire:click="sortBy('{{ $column['name'] }}')"
                                                        class="group relative cursor-pointer whitespace-nowrap border-b border-r border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-4 py-3 text-left text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]"
                                                        style="width: {{ $columnWidth }}px; min-width: {{ $columnWidth }}px;"
                                                    >
                                                        <div class="flex items-start justify-between gap-3">
                                                            <div class="min-w-0">
                                                                <div class="flex items-center gap-2">
                                                                    <span>{{ $column['name'] }}</span>
                                                                    @if ($sortColumn === $column['name'])
                                                                        <span class="text-[var(--color-linear-blue)]">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                                                    @elseif ($column['primary'])
                                                                        <span class="rounded-[4px] bg-[var(--color-linear-blue)]/18 px-1.5 py-0.5 text-[10px] font-semibold tracking-normal text-[var(--color-linear-200)]">PK</span>
                                                                    @endif
                                                                </div>

                                                                @if ($columnRelationship)
                                                                    <button
                                                                        type="button"
                                                                        wire:click.stop="openRelatedTable('{{ $columnRelationship['referenced_table'] }}')"
                                                                        class="mt-1 text-[10px] font-medium normal-case tracking-normal text-[var(--color-linear-blue)] transition hover:brightness-110"
                                                                    >
                                                                        {{ $columnRelationship['referenced_table'] }}.{{ $columnRelationship['referenced_column'] }}
                                                                    </button>
                                                                @endif
                                                            </div>
                                                            <div class="flex shrink-0 items-center gap-1 opacity-0 transition group-hover:opacity-100">
                                                                <button type="button" wire:click.stop="moveColumnLeft('{{ $column['name'] }}')" class="rounded-[6px] bg-white/5 px-1.5 py-1 text-[10px] font-medium normal-case tracking-normal text-[var(--color-linear-300)]">←</button>
                                                                <button type="button" wire:click.stop="moveColumnRight('{{ $column['name'] }}')" class="rounded-[6px] bg-white/5 px-1.5 py-1 text-[10px] font-medium normal-case tracking-normal text-[var(--color-linear-300)]">→</button>
                                                            </div>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            x-on:mousedown.prevent="startResize($event, '{{ $column['name'] }}')"
                                                            class="absolute inset-y-0 right-0 w-2 cursor-col-resize bg-transparent"
                                                            aria-label="Resize {{ $column['name'] }} column"
                                                        ></button>
                                                    </th>
                                                @endforeach
                                                <th class="sticky right-0 z-20 border-b border-l border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-4 py-3 text-right text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)] shadow-[-12px_0_24px_rgba(3,4,7,0.16)]">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @if ($showCreateRow)
                                                <tr class="bg-[var(--color-linear-blue)]/6">
                                                    <td class="sticky left-0 z-10 border-b border-r border-[var(--color-linear-775)] bg-[var(--color-linear-blue)]/6 px-3 py-2"></td>
                                                    @foreach ($orderedColumns as $column)
                                                        <td class="border-b border-r border-[var(--color-linear-775)] px-3 py-2 align-top" style="width: {{ $columnWidths[$column['name']] ?? 220 }}px; min-width: {{ $columnWidths[$column['name']] ?? 220 }}px;">
                                                            @if (! $column['generated'] && ! $column['auto_increment'])
                                                                @if (in_array($column['type'], ['json', 'text', 'longtext', 'mediumtext'], true))
                                                                    <textarea
                                                                        wire:model.live="createRowValues.{{ $column['name'] }}"
                                                                        rows="2"
                                                                        class="min-h-[2.5rem] w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-2.5 py-2 text-[12px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                                                                        placeholder="{{ $column['nullable'] ? '__NULL__' : '' }}"
                                                                    ></textarea>
                                                                @else
                                                                    <input
                                                                        type="text"
                                                                        wire:model.live="createRowValues.{{ $column['name'] }}"
                                                                        class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-2.5 py-2 text-[12px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                                                                        placeholder="{{ $column['nullable'] ? '__NULL__' : '' }}"
                                                                    />
                                                                @endif
                                                            @else
                                                                <div class="py-2 text-[12px] text-[var(--color-linear-500)]">Auto</div>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                    <td class="sticky right-0 z-10 border-b border-l border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-4 py-2 shadow-[-12px_0_24px_rgba(3,4,7,0.16)]">
                                                        <div class="flex justify-end gap-2">
                                                            <button type="button" wire:click="cancelCreatingRow" class="rounded-[8px] border border-[var(--color-linear-600)] px-3 py-2 text-[12px] font-medium text-[var(--color-linear-300)]">Cancel</button>
                                                            <button type="button" wire:click="storeNewRow" class="rounded-[8px] bg-[var(--color-linear-blue)] px-3 py-2 text-[12px] font-medium text-white">Insert</button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif

                                            @forelse ($rows as $rowIndex => $row)
                                                <tr @class([
                                                    'odd:bg-[var(--color-linear-900)] even:bg-[var(--color-linear-950)] hover:bg-white/2',
                                                    'bg-[var(--color-linear-blue)]/6' => $this->isEditingRow($rowIndex),
                                                ])>
                                                    <td @class([
                                                        'sticky left-0 z-10 border-b border-r border-[var(--color-linear-775)] px-3 py-2',
                                                        'bg-[var(--color-linear-blue)]/6' => $this->isEditingRow($rowIndex),
                                                        'bg-[var(--color-linear-900)]' => $rowIndex % 2 === 0 && ! $this->isEditingRow($rowIndex),
                                                        'bg-[var(--color-linear-950)]' => $rowIndex % 2 !== 0 && ! $this->isEditingRow($rowIndex),
                                                    ])>
                                                        <input
                                                            type="checkbox"
                                                            wire:click="toggleRowSelection({{ $rowIndex }})"
                                                            @checked(in_array($rowIndex, $selectedRowIndexes, true))
                                                            class="size-4 rounded border-[var(--color-linear-500)] bg-[var(--color-linear-900)] text-[var(--color-linear-blue)]"
                                                        />
                                                    </td>
                                                    @foreach ($orderedColumns as $column)
                                                        @php
                                                            $relationship = $foreignKeyMap[$column['name']] ?? null;
                                                            $relationshipPreview = $relatedPreviewMap->get($rowIndex.':'.$column['name']);
                                                        @endphp
                                                        <td class="max-w-[260px] border-b border-r border-[var(--color-linear-775)] px-3 py-2 align-top" style="width: {{ $columnWidths[$column['name']] ?? 220 }}px; min-width: {{ $columnWidths[$column['name']] ?? 220 }}px;">
                                                            @if ($editingRowIndex === null && ! $column['generated'] && ! $column['auto_increment'])
                                                                <div x-show="editingCell.row === {{ $rowIndex }} && editingCell.col === {{ $loop->index }}" x-cloak>
                                                                    @if (in_array($column['type'], ['json', 'text', 'longtext', 'mediumtext'], true))
                                                                        <textarea
                                                                            x-bind:data-grid-input="'{{ $rowIndex }}-{{ $loop->index }}'"
                                                                            x-model="editDraft"
                                                                            x-on:keydown.enter.prevent="commitEdit({{ $rowIndex }}, {{ $loop->index }}, '{{ $column['name'] }}')"
                                                                            x-on:keydown.tab.prevent="commitAndMove({{ $rowIndex }}, {{ $loop->index }}, '{{ $column['name'] }}', 0, 1)"
                                                                            x-on:keydown.escape.prevent="cancelEdit({{ $rowIndex }}, {{ $loop->index }})"
                                                                            rows="2"
                                                                            class="min-h-[2.5rem] w-full rounded-[8px] border border-[var(--color-linear-blue)] bg-[var(--color-linear-800)] px-2.5 py-2 text-[12px] text-[var(--color-linear-200)] outline-none"
                                                                        ></textarea>
                                                                    @else
                                                                        <input
                                                                            type="text"
                                                                            x-bind:data-grid-input="'{{ $rowIndex }}-{{ $loop->index }}'"
                                                                            x-model="editDraft"
                                                                            x-on:keydown.enter.prevent="commitEdit({{ $rowIndex }}, {{ $loop->index }}, '{{ $column['name'] }}')"
                                                                            x-on:keydown.tab.prevent="commitAndMove({{ $rowIndex }}, {{ $loop->index }}, '{{ $column['name'] }}', 0, 1)"
                                                                            x-on:keydown.escape.prevent="cancelEdit({{ $rowIndex }}, {{ $loop->index }})"
                                                                            class="w-full rounded-[8px] border border-[var(--color-linear-blue)] bg-[var(--color-linear-800)] px-2.5 py-2 text-[12px] text-[var(--color-linear-200)] outline-none"
                                                                        />
                                                                    @endif
                                                                </div>

                                                                <div
                                                                    x-data="{ tooltipOpen: false }"
                                                                    class="relative"
                                                                >
                                                                    <button
                                                                        type="button"
                                                                        x-show="editingCell.row !== {{ $rowIndex }} || editingCell.col !== {{ $loop->index }}"
                                                                        x-bind:data-grid-cell="'{{ $rowIndex }}-{{ $loop->index }}'"
                                                                        x-on:focus="setActive({{ $rowIndex }}, {{ $loop->index }}); tooltipOpen = true"
                                                                        x-on:blur="tooltipOpen = false"
                                                                        x-on:mouseenter="tooltipOpen = true"
                                                                        x-on:mouseleave="tooltipOpen = false"
                                                                        x-on:click="setActive({{ $rowIndex }}, {{ $loop->index }})"
                                                                        x-on:dblclick="beginEdit({{ $rowIndex }}, {{ $loop->index }}, @js(($row[$column['name']] ?? null) === null ? '__NULL__' : (string) $row[$column['name']]))"
                                                                        x-on:keydown.enter.prevent="beginEdit({{ $rowIndex }}, {{ $loop->index }}, @js(($row[$column['name']] ?? null) === null ? '__NULL__' : (string) $row[$column['name']]))"
                                                                        x-on:keydown.escape.prevent="editingCell = { row: null, col: null }; tooltipOpen = false"
                                                                        x-on:keydown.arrow-right.prevent="moveCell({{ $rowIndex }}, {{ $loop->index }}, 0, 1)"
                                                                        x-on:keydown.arrow-left.prevent="moveCell({{ $rowIndex }}, {{ $loop->index }}, 0, -1)"
                                                                        x-on:keydown.arrow-down.prevent="moveCell({{ $rowIndex }}, {{ $loop->index }}, 1, 0)"
                                                                        x-on:keydown.arrow-up.prevent="moveCell({{ $rowIndex }}, {{ $loop->index }}, -1, 0)"
                                                                        class="w-full rounded-[8px] px-2 py-1.5 text-left transition focus:outline-none"
                                                                        x-bind:class="activeCell.row === {{ $rowIndex }} && activeCell.col === {{ $loop->index }} ? 'ring-1 ring-[var(--color-linear-blue)]/60 bg-white/5' : ''"
                                                                    >
                                                                        <div class="flex items-start gap-2">
                                                                            <div class="min-w-0 flex-1 truncate font-mono text-[12px] leading-5 text-[var(--color-linear-300)]">
                                                                                {{ ($row[$column['name']] ?? null) === null ? 'NULL' : Str::limit((string) $row[$column['name']], 160) }}
                                                                            </div>
                                                                            @if ($relationship && ($row[$column['name']] ?? null) !== null)
                                                                                <span class="rounded-[6px] bg-[var(--color-linear-blue)]/16 px-1.5 py-0.5 text-[10px] font-semibold text-[var(--color-linear-200)]">FK</span>
                                                                            @endif
                                                                            @if ($column['type'] === 'json' && ($row[$column['name']] ?? null) !== null)
                                                                                <span class="rounded-[6px] bg-white/6 px-1.5 py-0.5 text-[10px] font-semibold text-[var(--color-linear-300)]">JSON</span>
                                                                            @endif
                                                                        </div>
                                                                    </button>

                                                                    @if ($relationship && $relationshipPreview)
                                                                        <div
                                                                            x-cloak
                                                                            x-show="tooltipOpen && editingCell.row !== {{ $rowIndex }} && editingCell.col !== {{ $loop->index }}"
                                                                            x-transition.opacity.duration.120ms
                                                                            class="pointer-events-none absolute left-0 top-[calc(100%+0.45rem)] z-30 w-[280px] rounded-[12px] border border-white/8 bg-[var(--color-linear-900)]/96 p-3 shadow-[0_20px_50px_rgba(0,0,0,0.45)] backdrop-blur"
                                                                        >
                                                                            <div class="flex items-center justify-between gap-3">
                                                                                <div class="min-w-0">
                                                                                    <p class="truncate text-[12px] font-semibold text-[var(--color-linear-100)]">{{ $relationshipPreview['summary'] }}</p>
                                                                                    <p class="pt-0.5 text-[11px] text-[var(--color-linear-400)]">{{ $relationship['referenced_table'] }}.{{ $relationship['referenced_column'] }}</p>
                                                                                </div>
                                                                                <span class="shrink-0 rounded-[6px] bg-white/6 px-1.5 py-0.5 text-[10px] font-medium text-[var(--color-linear-300)]">Related</span>
                                                                            </div>

                                                                            <div class="mt-3 space-y-2">
                                                                                @foreach ($relationshipPreview['fields'] as $field)
                                                                                    <div class="flex items-start justify-between gap-3 text-[11px]">
                                                                                        <span class="shrink-0 text-[var(--color-linear-400)]">{{ $field['label'] }}</span>
                                                                                        <span class="min-w-0 truncate font-mono text-[var(--color-linear-200)]">{{ $field['value'] }}</span>
                                                                                    </div>
                                                                                @endforeach
                                                                            </div>
                                                                        </div>
                                                                    @endif
                                                                </div>
                                                                @if ($column['type'] === 'json' && ($row[$column['name']] ?? null) !== null)
                                                                    <button
                                                                        type="button"
                                                                        x-on:click="openJsonPreview('{{ $column['name'] }}', @js((string) $row[$column['name']]))"
                                                                        class="mt-1 text-[11px] font-medium text-[var(--color-linear-400)] transition hover:text-[var(--color-linear-200)]"
                                                                    >
                                                                        Pretty view
                                                                    </button>
                                                                @endif
                                                            @elseif ($this->isEditingRow($rowIndex) && ! $column['generated'] && ! $column['primary'] && ! $column['auto_increment'])
                                                                @if (in_array($column['type'], ['json', 'text', 'longtext', 'mediumtext'], true))
                                                                    <textarea
                                                                        wire:model.live="editingRowValues.{{ $column['name'] }}"
                                                                        rows="2"
                                                                        class="min-h-[2.5rem] w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-2.5 py-2 text-[12px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                                                                        placeholder="{{ $column['nullable'] ? '__NULL__' : '' }}"
                                                                    ></textarea>
                                                                @else
                                                                    <input
                                                                        type="text"
                                                                        wire:model.live="editingRowValues.{{ $column['name'] }}"
                                                                        class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-2.5 py-2 text-[12px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                                                                        placeholder="{{ $column['nullable'] ? '__NULL__' : '' }}"
                                                                    />
                                                                @endif
                                                            @else
                                                                <div class="truncate font-mono text-[12px] leading-5 text-[var(--color-linear-300)]">
                                                                    {{ ($row[$column['name']] ?? null) === null ? 'NULL' : Str::limit((string) $row[$column['name']], 160) }}
                                                                </div>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                    <td @class([
                                                        'sticky right-0 z-10 border-b border-l border-[var(--color-linear-775)] px-4 py-2 shadow-[-12px_0_24px_rgba(3,4,7,0.16)]',
                                                        'bg-[var(--color-linear-blue)]/6' => $this->isEditingRow($rowIndex),
                                                        'bg-[var(--color-linear-900)]' => $rowIndex % 2 === 0 && ! $this->isEditingRow($rowIndex),
                                                        'bg-[var(--color-linear-950)]' => $rowIndex % 2 !== 0 && ! $this->isEditingRow($rowIndex),
                                                    ])>
                                                        <div class="flex justify-end gap-2">
                                                            @if ($this->isEditingRow($rowIndex))
                                                                <button type="button" wire:click="clearEditingRow" class="rounded-[8px] border border-[var(--color-linear-600)] px-3 py-2 text-[12px] font-medium text-[var(--color-linear-300)]">Cancel</button>
                                                                <button type="button" wire:click="saveRow" class="rounded-[8px] bg-[var(--color-linear-blue)] px-3 py-2 text-[12px] font-medium text-white">Save</button>
                                                            @else
                                                                <button type="button" wire:click="startEditingRow({{ $rowIndex }})" class="rounded-[8px] border border-[var(--color-linear-600)] px-3 py-2 text-[12px] font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-blue)]">Edit</button>
                                                                <button type="button" wire:click="duplicateRow({{ $rowIndex }})" class="rounded-[8px] border border-[var(--color-linear-600)] px-3 py-2 text-[12px] font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-blue)]">Duplicate</button>
                                                                <button type="button" wire:click="deleteRow({{ $rowIndex }})" class="rounded-[8px] border border-transparent bg-white/5 px-3 py-2 text-[12px] font-medium text-[var(--color-linear-400)] transition hover:text-red-300">Delete</button>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="{{ count($orderedColumns) + 2 }}" class="px-6 py-12 text-center text-sm text-[var(--color-linear-400)]">
                                                        No rows match the current search.
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </section>
            @else
                <section class="flex min-h-0 flex-1 flex-col px-6 py-5">
                    <div class="grid gap-5 xl:grid-cols-[360px,minmax(0,1fr)]">
                        <div class="rounded-[12px] border border-[var(--color-linear-775)] bg-[var(--color-linear-900)] p-4 shadow-[0_1px_2px_rgba(0,0,0,0.25)]">
                            <h2 class="text-sm font-semibold text-[var(--color-linear-200)]">Add column</h2>
                            <p class="pt-1 text-sm text-[var(--color-linear-400)]">Extend the selected table schema without leaving the editor.</p>

                            <div class="mt-4 space-y-3">
                                <div>
                                    <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Column name</label>
                                    <input type="text" wire:model="newColumnName" placeholder="status" class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Type</label>
                                        <select wire:model.live="newColumnType" class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] text-[var(--color-linear-200)] outline-none transition focus:border-[var(--color-linear-blue)]">
                                            @foreach ($columnTypeOptions as $type)
                                                <option value="{{ $type }}">{{ $type }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Length / precision</label>
                                        <input type="text" wire:model="newColumnLength" placeholder="255 or 10,2" class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                                    </div>
                                </div>

                                <div>
                                    <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Default value</label>
                                    <input type="text" wire:model="newColumnDefault" placeholder="optional default" class="w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                                </div>

                                <label class="flex items-center gap-3 rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-sm text-[var(--color-linear-300)]">
                                    <input type="checkbox" wire:model="newColumnNullable" class="size-4 rounded border-[var(--color-linear-500)] bg-[var(--color-linear-900)] text-[var(--color-linear-blue)]" />
                                    Nullable
                                </label>

                                <button
                                    type="button"
                                    wire:click="addColumn"
                                    @disabled($selectedTable === '')
                                    class="w-full rounded-[8px] bg-[var(--color-linear-blue)] px-3 py-2 text-[13px] font-medium text-white transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    Add column
                                </button>

                                <div class="rounded-[10px] border border-[var(--color-linear-775)] bg-[var(--color-linear-950)]/60 p-3">
                                    <p class="text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Migration</p>
                                    <p class="pt-1 text-sm text-[var(--color-linear-400)]">Generate a Laravel migration file for the selected table in <span class="font-mono text-[var(--color-linear-300)]">database/migrations</span>.</p>
                                    <button
                                        type="button"
                                        wire:click="generateMigration"
                                        @disabled($selectedTable === '')
                                        class="mt-3 w-full rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] font-medium text-[var(--color-linear-200)] transition hover:border-[var(--color-linear-blue)] disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        Generate migration
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-hidden rounded-[12px] border border-[var(--color-linear-775)] bg-[var(--color-linear-900)] shadow-[0_1px_2px_rgba(0,0,0,0.25)]">
                            <div class="border-b border-[var(--color-linear-775)] px-4 py-3">
                                <h2 class="text-sm font-semibold text-[var(--color-linear-200)]">Current schema</h2>
                                <p class="pt-1 text-sm text-[var(--color-linear-400)]">The existing columns in {{ $selectedTable ?: 'this table' }}.</p>
                            </div>

                            <div class="overflow-auto">
                                <table class="min-w-full border-separate border-spacing-0">
                                    <thead>
                                        <tr>
                                            <th class="border-b border-r border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-4 py-3 text-left text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Name</th>
                                            <th class="border-b border-r border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-4 py-3 text-left text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Type</th>
                                            <th class="border-b border-r border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-4 py-3 text-left text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Nullable</th>
                                            <th class="border-b border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-4 py-3 text-left text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Default</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($columns as $column)
                                            <tr class="odd:bg-[var(--color-linear-900)] even:bg-[var(--color-linear-950)]">
                                                <td class="border-b border-r border-[var(--color-linear-775)] px-4 py-3 text-sm text-[var(--color-linear-200)]">
                                                    <div class="flex items-center gap-2">
                                                        <span>{{ $column['name'] }}</span>
                                                        @if ($column['primary'])
                                                            <span class="rounded-[4px] bg-[var(--color-linear-blue)]/18 px-1.5 py-0.5 text-[10px] font-semibold text-[var(--color-linear-200)]">PK</span>
                                                        @endif
                                                    </div>
                                                </td>
                                                <td class="border-b border-r border-[var(--color-linear-775)] px-4 py-3 font-mono text-[12px] text-[var(--color-linear-300)]">{{ $column['full_type'] }}</td>
                                                <td class="border-b border-r border-[var(--color-linear-775)] px-4 py-3 text-sm text-[var(--color-linear-300)]">{{ $column['nullable'] ? 'Yes' : 'No' }}</td>
                                                <td class="border-b border-[var(--color-linear-775)] px-4 py-3 text-sm text-[var(--color-linear-300)]">{{ $column['default'] ?? 'NULL' }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-6 py-12 text-center text-sm text-[var(--color-linear-400)]">
                                                    Select a table to inspect its schema.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            @endif
        </main>

        <div
            x-cloak
            x-show="connectionModalOpen"
            x-transition.opacity.duration.150ms
            x-on:connection-saved.window="connectionModalOpen = false"
            x-on:keydown.escape.window="connectionModalOpen = false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4 backdrop-blur-sm"
        >
            <div
                x-on:click.outside="connectionModalOpen = false"
                class="w-full max-w-2xl rounded-[20px] border border-[var(--color-linear-775)] bg-[var(--color-linear-925)] shadow-[0_24px_80px_rgba(0,0,0,0.45)]"
            >
                <div class="flex items-start justify-between border-b border-[var(--color-linear-775)] px-6 py-5">
                    <div>
                        <p class="text-[11px] font-medium uppercase tracking-[0.18em] text-[var(--color-linear-400)]">New Connection</p>
                        <h2 class="pt-1 text-lg font-semibold text-[var(--color-linear-200)]">Add a Forge SSH source</h2>
                        <p class="pt-1 text-sm text-[var(--color-linear-400)]">Save an RSA-backed SSH connection and browse its MySQL databases like your local source.</p>
                    </div>
                    <button
                        type="button"
                        x-on:click="connectionModalOpen = false"
                        class="rounded-[10px] border border-[var(--color-linear-700)] bg-[var(--color-linear-900)] px-3 py-2 text-xs font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-600)] hover:text-white"
                    >
                        Close
                    </button>
                </div>

                <form
                    wire:submit="saveConnection"
                    class="space-y-5 px-6 py-6"
                >
                    <div class="grid gap-5 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label for="connection-name" class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Name</label>
                            <input
                                id="connection-name"
                                type="text"
                                wire:model="connectionName"
                                placeholder="Production API"
                                class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                            />
                        </div>

                        <div>
                            <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Host</label>
                            <input type="text" wire:model="connectionHost" placeholder="server.example.com" class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                        </div>

                        <div>
                            <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">SSH port</label>
                            <input type="number" wire:model="connectionPort" placeholder="22" class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                        </div>

                        <div>
                            <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">SSH username</label>
                            <input type="text" wire:model="connectionSshUsername" placeholder="forge" class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                        </div>

                        <div>
                            <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">RSA key path</label>
                            <input type="text" wire:model="connectionPrivateKeyPath" placeholder="/Users/kylemcgowan/.ssh/id_rsa" class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                        </div>

                        <div>
                            <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Database host</label>
                            <input type="text" wire:model="connectionDatabaseHost" placeholder="127.0.0.1" class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                        </div>

                        <div>
                            <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Database port</label>
                            <input type="number" wire:model="connectionDatabasePort" placeholder="3306" class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                        </div>

                        <div>
                            <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Database username</label>
                            <input type="text" wire:model="connectionDatabaseUsername" placeholder="forge" class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                        </div>

                        <div>
                            <label class="mb-2 block text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">Database password</label>
                            <input type="password" wire:model="connectionDatabasePassword" placeholder="MySQL password" class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-850)] px-3 py-2.5 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-[var(--color-linear-775)] pt-5">
                        <button
                            type="button"
                            x-on:click="connectionModalOpen = false"
                            class="rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-4 py-2.5 text-[13px] font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-blue)]"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="rounded-[10px] bg-[var(--color-linear-blue)] px-4 py-2.5 text-[13px] font-medium text-white transition hover:brightness-110"
                        >
                            Save connection
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div
            x-cloak
            x-show="migrationModalOpen"
            x-transition.opacity.duration.150ms
            x-on:migration-generated.window="migrationModalOpen = true"
            x-on:keydown.escape.window="migrationModalOpen = false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4 backdrop-blur-sm"
        >
            <div
                x-on:click.outside="migrationModalOpen = false"
                class="w-full max-w-5xl rounded-[20px] border border-[var(--color-linear-775)] bg-[var(--color-linear-925)] shadow-[0_24px_80px_rgba(0,0,0,0.45)]"
            >
                <div class="flex items-start justify-between border-b border-[var(--color-linear-775)] px-6 py-5">
                    <div>
                        <p class="text-[11px] font-medium uppercase tracking-[0.18em] text-[var(--color-linear-400)]">Migration Preview</p>
                        <h2 class="pt-1 text-lg font-semibold text-[var(--color-linear-200)]">Laravel migration for {{ $selectedTable ?: 'selected table' }}</h2>
                        <p class="pt-1 text-sm text-[var(--color-linear-400)]">Review the generated migration code before copying it into your codebase.</p>
                    </div>
                    <button
                        type="button"
                        x-on:click="migrationModalOpen = false"
                        class="rounded-[10px] border border-[var(--color-linear-700)] bg-[var(--color-linear-900)] px-3 py-2 text-xs font-medium text-[var(--color-linear-300)] transition hover:border-[var(--color-linear-600)] hover:text-white"
                    >
                        Close
                    </button>
                </div>

                <div class="px-6 py-6">
                    <div class="overflow-hidden rounded-[14px] border border-[var(--color-linear-775)] bg-[var(--color-linear-950)]">
                        <div class="border-b border-[var(--color-linear-775)] px-4 py-3 text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">
                            database/migrations/create_{{ Str::snake($selectedTable ?: 'table') }}_table.php
                        </div>
                        <pre class="max-h-[70vh] overflow-auto px-4 py-4 text-[12px] leading-6 text-[var(--color-linear-300)]"><code>{{ $generatedMigrationCode }}</code></pre>
                    </div>
                </div>
            </div>
        </div>

        <div
            x-cloak
            x-show="commandPaletteOpen"
            x-transition.opacity.duration.150ms
            x-on:keydown.escape.window="commandPaletteOpen = false"
            class="fixed inset-0 z-50 flex items-start justify-center bg-black/60 px-4 pt-[12vh] backdrop-blur-sm"
        >
            <div
                x-on:click.outside="commandPaletteOpen = false"
                class="w-full max-w-2xl overflow-hidden rounded-[18px] border border-[var(--color-linear-775)] bg-[var(--color-linear-925)] shadow-[0_24px_80px_rgba(0,0,0,0.45)]"
            >
                <div class="border-b border-[var(--color-linear-775)] px-4 py-4">
                    <input
                        type="text"
                        x-model="commandSearch"
                        x-init="$watch('commandPaletteOpen', value => { if (value) { commandSearch = ''; $nextTick(() => $el.focus()) } })"
                        placeholder="Jump to a table, pinned view, or recent table..."
                        class="w-full rounded-[10px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-3 py-3 text-[14px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                    />
                </div>
                <div class="max-h-[60vh] overflow-auto p-2">
                    @foreach ($commandPaletteEntries as $entry)
                        @php
                            $commandLabel = $entry['database'].'.'.$entry['table'];
                            $commandSearchText = Str::lower($entry['database'].' '.$entry['table']);
                        @endphp
                        <button
                            type="button"
                            x-show="fuzzyMatch(commandSearch, '{{ $commandSearchText }}')"
                            wire:click="openTable('{{ $entry['database'] }}', '{{ $entry['table'] }}')"
                            x-on:click="commandPaletteOpen = false"
                            class="flex w-full items-center justify-between rounded-[10px] px-3 py-3 text-left transition hover:bg-[var(--color-linear-900)]"
                        >
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-[var(--color-linear-200)]">{{ $commandLabel }}</div>
                                <div class="pt-0.5 text-xs text-[var(--color-linear-400)]">{{ Number::format($entry['rows']) }} rows</div>
                            </div>
                            <div class="text-[11px] text-[var(--color-linear-500)]">Open</div>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div
            x-cloak
            x-show="jsonPreviewOpen"
            x-transition.opacity.duration.150ms
            x-on:keydown.escape.window="jsonPreviewOpen = false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4 backdrop-blur-sm"
        >
            <div
                x-on:click.outside="jsonPreviewOpen = false"
                class="w-full max-w-3xl overflow-hidden rounded-[18px] border border-[var(--color-linear-775)] bg-[var(--color-linear-925)] shadow-[0_24px_80px_rgba(0,0,0,0.45)]"
            >
                <div class="flex items-center justify-between border-b border-[var(--color-linear-775)] px-5 py-4">
                    <div>
                        <p class="text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]">JSON Preview</p>
                        <h2 class="pt-1 text-sm font-semibold text-[var(--color-linear-200)]" x-text="jsonPreviewTitle"></h2>
                    </div>
                    <button type="button" x-on:click="jsonPreviewOpen = false" class="rounded-[8px] border border-[var(--color-linear-600)] px-3 py-2 text-[12px] text-[var(--color-linear-300)]">Close</button>
                </div>
                <pre class="max-h-[70vh] overflow-auto px-5 py-5 text-[12px] leading-6 text-[var(--color-linear-300)]"><code x-text="jsonPreviewValue"></code></pre>
            </div>
        </div>
    </section>
</div>

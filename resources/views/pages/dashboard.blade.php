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

    public array $savedConnections = [];

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

    public function mount(): void
    {
        $this->perPage = max((int) config('herd.mysql.page_size', 25), 1);
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
        $validated = $this->validate([
            'newDatabaseName' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$-]+$/'],
        ]);

        app(MySqlManager::class)->createDatabase($validated['newDatabaseName'], $this->activeConnection());

        $this->newDatabaseName = '';
        $this->selectedDatabase = $validated['newDatabaseName'];
        $this->importDatabaseName = $validated['newDatabaseName'];

        $this->refreshWorkspace();

        session()->flash('status', "Created database {$this->selectedDatabase}.");
    }

    public function importDatabase(): void
    {
        $validated = $this->validate([
            'importDatabaseName' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'importFile' => ['required', 'file', 'max:51200', 'extensions:sql,txt'],
        ]);

        app(MySqlManager::class)->importDatabase($validated['importDatabaseName'], $this->importFile->getRealPath(), $this->activeConnection());

        $this->reset('importFile');
        $this->selectedDatabase = $validated['importDatabaseName'];
        $this->refreshWorkspace();

        session()->flash('status', "Imported dump into {$this->selectedDatabase}.");
    }

    public function addColumn(): void
    {
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

        session()->flash('status', "Added column {$validated['newColumnName']}.");
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

        app(MySqlManager::class)->insertRow(
            $this->selectedDatabase,
            $this->selectedTable,
            $this->createRowValues,
            $this->activeConnection(),
        );

        $this->refreshTableData();
        session()->flash('status', 'Row inserted.');
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

        app(MySqlManager::class)->updateRow(
            $this->selectedDatabase,
            $this->selectedTable,
            $this->editingRowIdentifiers,
            $this->editingRowValues,
            $this->activeConnection(),
        );

        $this->refreshTableData();
        session()->flash('status', 'Row updated.');
    }

    public function deleteRow(int $rowIndex): void
    {
        if (! $this->canMutateRows() || ! isset($this->rows[$rowIndex])) {
            return;
        }

        app(MySqlManager::class)->deleteRow(
            $this->selectedDatabase,
            $this->selectedTable,
            $this->rowIdentifiers($this->rows[$rowIndex]),
            $this->activeConnection(),
        );

        $this->refreshTableData();
        session()->flash('status', 'Row deleted.');
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

        session()->flash('status', "Saved connection {$connection->name}.");
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

        if ($this->selectedDatabase === '' || $this->selectedTable === '') {
            $this->columns = [];
            $this->rows = [];
            $this->createRowValues = [];
            $this->primaryKeyColumns = [];
            $this->totalRows = 0;
            $this->showCreateRow = false;

            return;
        }

        $manager = app(MySqlManager::class);
        $connection = $this->activeConnection();
        $this->columns = $manager->getTableColumns($this->selectedDatabase, $this->selectedTable, $connection);
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

        if ($this->showCreateRow && $this->createRowValues === []) {
            $this->createRowValues = $this->defaultFormValues();
        }
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
@endphp

<div class="min-h-screen bg-[var(--color-linear-950)] text-[var(--color-linear-200)]">
    <section
        class="min-h-screen xl:pl-[15vw]"
        x-data="{ dbOpen: false, actionsOpen: false, sourceOpen: false, connectionModalOpen: false, migrationModalOpen: false }"
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
                        <button
                            type="button"
                            wire:click="selectTable('{{ $table['name'] }}')"
                            @class([
                                'group flex w-full min-w-0 items-center justify-between rounded-[8px] px-3 py-2 text-left transition',
                                'bg-white/8 text-[var(--color-linear-200)] ring-1 ring-inset ring-white/4' => $selectedTable === $table['name'],
                                'text-[var(--color-linear-300)] hover:bg-[var(--color-linear-900)]' => $selectedTable !== $table['name'],
                            ])
                        >
                            <span class="min-w-0 flex-1 truncate text-[13px] font-medium">{{ $table['name'] }}</span>
                            <span class="rounded-full bg-[var(--color-linear-850)] px-2 py-0.5 text-[11px] text-[var(--color-linear-400)] group-hover:text-[var(--color-linear-300)]">
                                {{ Number::format($table['rows']) }}
                            </span>
                        </button>
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

            @if (session('status'))
                <div class="mx-6 mt-4 rounded-[10px] border border-[var(--color-linear-blue)]/28 bg-[var(--color-linear-blue)]/8 px-4 py-3 text-sm text-[var(--color-linear-200)]">
                    {{ session('status') }}
                </div>
            @endif

            @if ($activeTab === 'data')
                <section class="flex min-h-0 flex-1 flex-col">
                    <div class="sticky top-0 z-20 border-b border-[var(--color-linear-775)] bg-[var(--color-linear-950)]/95 px-6 py-4 backdrop-blur-md">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <input
                                    type="text"
                                    wire:model.live.debounce.250ms="rowSearch"
                                    placeholder="Search rows"
                                    class="w-full max-w-md rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-800)] px-3 py-2 text-[13px] text-[var(--color-linear-200)] outline-none transition placeholder:text-[var(--color-linear-400)] focus:border-[var(--color-linear-blue)]"
                                />
                                <div class="rounded-[8px] border border-[var(--color-linear-600)] bg-[var(--color-linear-900)] px-3 py-2 text-xs text-[var(--color-linear-400)]">
                                    {{ Number::format($totalRows) }} rows
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
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
                                                @foreach ($columns as $column)
                                                    <th
                                                        wire:click="sortBy('{{ $column['name'] }}')"
                                                        class="cursor-pointer whitespace-nowrap border-b border-r border-[var(--color-linear-775)] bg-[var(--color-linear-900)] px-4 py-3 text-left text-[11px] font-medium uppercase tracking-[0.16em] text-[var(--color-linear-400)]"
                                                    >
                                                        <div class="flex items-center gap-2">
                                                            <span>{{ $column['name'] }}</span>
                                                            @if ($sortColumn === $column['name'])
                                                                <span class="text-[var(--color-linear-blue)]">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                                            @elseif ($column['primary'])
                                                                <span class="rounded-[4px] bg-[var(--color-linear-blue)]/18 px-1.5 py-0.5 text-[10px] font-semibold tracking-normal text-[var(--color-linear-200)]">PK</span>
                                                            @endif
                                                        </div>
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
                                                    @foreach ($columns as $column)
                                                        <td class="border-b border-r border-[var(--color-linear-775)] px-3 py-2 align-top">
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
                                                    @foreach ($columns as $column)
                                                        <td class="max-w-[260px] border-b border-r border-[var(--color-linear-775)] px-3 py-2 align-top">
                                                            @if ($this->isEditingRow($rowIndex) && ! $column['generated'] && ! $column['primary'] && ! $column['auto_increment'])
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
                                                                <button type="button" wire:click="deleteRow({{ $rowIndex }})" class="rounded-[8px] border border-transparent bg-white/5 px-3 py-2 text-[12px] font-medium text-[var(--color-linear-400)] transition hover:text-red-300">Delete</button>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="{{ count($columns) + 1 }}" class="px-6 py-12 text-center text-sm text-[var(--color-linear-400)]">
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
    </section>
</div>

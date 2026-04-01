<?php

namespace App\Services\Herd;

use Illuminate\Support\Str;
use RuntimeException;

class MigrationGenerator
{
    /**
     * @param  array<int, array{name: string, type: string, full_type: string, nullable: bool, default: mixed, primary: bool, auto_increment: bool, generated: bool, comment: string}>  $columns
     */
    public function renderCreateTableMigration(string $table, array $columns): string
    {
        if ($table === '') {
            throw new RuntimeException('A table must be selected before generating a migration.');
        }

        if ($columns === []) {
            throw new RuntimeException('The selected table has no columns to export.');
        }

        return $this->buildMigration($table, $columns);
    }

    /**
     * @param  array<int, array{name: string, type: string, full_type: string, nullable: bool, default: mixed, primary: bool, auto_increment: bool, generated: bool, comment: string}>  $columns
     */
    private function buildMigration(string $table, array $columns): string
    {
        $bodyLines = [];
        $primaryColumns = [];
        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        $hasDeletedAt = false;

        foreach ($columns as $column) {
            if ($column['generated']) {
                $bodyLines[] = '            // Generated column omitted: '.$column['name'];

                continue;
            }

            if ($column['name'] === 'created_at' && $column['type'] === 'timestamp') {
                $hasCreatedAt = true;

                continue;
            }

            if ($column['name'] === 'updated_at' && $column['type'] === 'timestamp') {
                $hasUpdatedAt = true;

                continue;
            }

            if ($column['name'] === 'deleted_at' && in_array($column['type'], ['timestamp', 'datetime'], true)) {
                $hasDeletedAt = true;

                continue;
            }

            $bodyLines[] = '            '.$this->columnDefinition($column);

            if ($column['primary'] && ! $column['auto_increment']) {
                $primaryColumns[] = $column['name'];
            }
        }

        if ($hasCreatedAt && $hasUpdatedAt) {
            $bodyLines[] = '            $table->timestamps();';
        } elseif ($hasCreatedAt) {
            $bodyLines[] = '            $table->timestamp(\'created_at\')->nullable();';
        } elseif ($hasUpdatedAt) {
            $bodyLines[] = '            $table->timestamp(\'updated_at\')->nullable();';
        }

        if ($hasDeletedAt) {
            $bodyLines[] = '            $table->softDeletes();';
        }

        if ($primaryColumns !== []) {
            $primaryList = collect($primaryColumns)
                ->map(fn (string $column): string => "'{$column}'")
                ->implode(', ');

            $bodyLines[] = '            $table->primary(['.$primaryList.']);';
        }

        $body = implode("\n", $bodyLines);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table): void {
{$body}
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }

    /**
     * @param  array{name: string, type: string, full_type: string, nullable: bool, default: mixed, primary: bool, auto_increment: bool, generated: bool, comment: string}  $column
     */
    private function columnDefinition(array $column): string
    {
        $name = $column['name'];
        $method = match (true) {
            $column['auto_increment'] && $column['type'] === 'bigint' && $name === 'id' => '$table->id()',
            $column['auto_increment'] && $column['type'] === 'bigint' => '$table->bigIncrements(\''.$name.'\')',
            $column['auto_increment'] && $column['type'] === 'int' => '$table->increments(\''.$name.'\')',
            $column['type'] === 'varchar' => '$table->string(\''.$name.'\', '.$this->varcharLength($column['full_type']).')',
            $column['type'] === 'decimal' => '$table->decimal(\''.$name.'\', '.$this->decimalPrecision($column['full_type']).')',
            $column['type'] === 'bigint' => '$table->'.$this->integerMethod('bigInteger', $column['full_type']).'(\''.$name.'\')',
            $column['type'] === 'int' => '$table->'.$this->integerMethod('integer', $column['full_type']).'(\''.$name.'\')',
            $column['type'] === 'boolean' => '$table->boolean(\''.$name.'\')',
            $column['type'] === 'date' => '$table->date(\''.$name.'\')',
            $column['type'] === 'datetime' => '$table->dateTime(\''.$name.'\')',
            $column['type'] === 'timestamp' => '$table->timestamp(\''.$name.'\')',
            $column['type'] === 'json' => '$table->json(\''.$name.'\')',
            $column['type'] === 'text' => '$table->text(\''.$name.'\')',
            default => '$table->addColumn(\''.$column['type'].'\', \''.$name.'\')',
        };

        if (! $column['auto_increment'] && $column['nullable']) {
            $method .= '->nullable()';
        }

        if ($column['default'] !== null && ! $column['auto_increment']) {
            $defaultValue = var_export($column['default'], true);
            $method .= '->default('.$defaultValue.')';
        }

        if ($column['comment'] !== '') {
            $method .= '->comment('.var_export($column['comment'], true).')';
        }

        return $method.';';
    }

    private function varcharLength(string $fullType): int
    {
        preg_match('/\((\d+)\)/', $fullType, $matches);

        return (int) ($matches[1] ?? 255);
    }

    private function decimalPrecision(string $fullType): string
    {
        preg_match('/\((\d+),(\d+)\)/', $fullType, $matches);

        $precision = $matches[1] ?? 10;
        $scale = $matches[2] ?? 2;

        return $precision.', '.$scale;
    }

    private function integerMethod(string $defaultMethod, string $fullType): string
    {
        return Str::contains(Str::lower($fullType), 'unsigned')
            ? 'unsigned'.Str::ucfirst($defaultMethod)
            : $defaultMethod;
    }
}

<?php

use App\Services\Herd\MigrationGenerator;

test('migration generator renders a create table migration from live schema columns', function () {
    $generator = app(MigrationGenerator::class);
    $contents = $generator->renderCreateTableMigration('posts', [
        ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'primary' => true, 'auto_increment' => true, 'generated' => false, 'comment' => ''],
        ['name' => 'title', 'type' => 'varchar', 'full_type' => 'varchar(180)', 'nullable' => false, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
        ['name' => 'status', 'type' => 'varchar', 'full_type' => 'varchar(32)', 'nullable' => true, 'default' => 'draft', 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => 'Editorial state'],
        ['name' => 'price', 'type' => 'decimal', 'full_type' => 'decimal(10,2)', 'nullable' => false, 'default' => '0.00', 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
        ['name' => 'created_at', 'type' => 'timestamp', 'full_type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
        ['name' => 'updated_at', 'type' => 'timestamp', 'full_type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false, 'auto_increment' => false, 'generated' => false, 'comment' => ''],
    ]);

    expect($contents)
        ->toContain("Schema::create('posts'")
        ->toContain('$table->id();')
        ->toContain("\$table->string('title', 180);")
        ->toContain("\$table->string('status', 32)->nullable()->default('draft')->comment('Editorial state');")
        ->toContain("\$table->decimal('price', 10, 2)->default('0.00');")
        ->toContain('$table->timestamps();');
});

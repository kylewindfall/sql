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
        Schema::create('database_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver')->default('ssh_mysql');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('ssh_username');
            $table->string('private_key_path');
            $table->string('database_host')->default('127.0.0.1');
            $table->unsignedSmallInteger('database_port')->default(3306);
            $table->string('database_username');
            $table->text('database_password')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_connections');
    }
};

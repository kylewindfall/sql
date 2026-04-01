<?php

namespace App\Models;

use Database\Factories\DatabaseConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DatabaseConnection extends Model
{
    /** @use HasFactory<DatabaseConnectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'driver',
        'host',
        'port',
        'ssh_username',
        'private_key_path',
        'database_host',
        'database_port',
        'database_username',
        'database_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'database_password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'database_port' => 'integer',
            'database_password' => 'encrypted',
        ];
    }
}

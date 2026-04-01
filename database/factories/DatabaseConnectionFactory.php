<?php

namespace Database\Factories;

use App\Models\DatabaseConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnection>
 */
class DatabaseConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'driver' => 'ssh_mysql',
            'host' => fake()->domainName(),
            'port' => 22,
            'ssh_username' => 'forge',
            'private_key_path' => '/Users/kyle/.ssh/id_rsa',
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_username' => 'forge',
            'database_password' => 'secret',
        ];
    }
}

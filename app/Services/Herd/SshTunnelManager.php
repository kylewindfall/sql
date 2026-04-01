<?php

namespace App\Services\Herd;

use App\Models\DatabaseConnection;
use RuntimeException;
use Symfony\Component\Process\Process;

class SshTunnelManager
{
    public function ensure(DatabaseConnection $connection): int
    {
        if ($connection->driver !== 'ssh_mysql') {
            throw new RuntimeException("Unsupported connection driver [{$connection->driver}].");
        }

        if (! is_file($connection->private_key_path)) {
            throw new RuntimeException("RSA private key not found at [{$connection->private_key_path}].");
        }

        if (! $this->isRunning($connection)) {
            $this->start($connection);
        }

        return $this->localPort($connection);
    }

    public function localPort(DatabaseConnection $connection): int
    {
        return 43000 + $connection->id;
    }

    public function isRunning(DatabaseConnection $connection): bool
    {
        $socket = $this->controlSocketPath($connection);

        if (! is_file($socket)) {
            return false;
        }

        $process = new Process([
            'ssh',
            '-S',
            $socket,
            '-O',
            'check',
            $this->destination($connection),
        ]);

        $process->run();

        return $process->isSuccessful();
    }

    public function controlSocketPath(DatabaseConnection $connection): string
    {
        $directory = storage_path('app/private/ssh');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to prepare SSH control socket directory.');
        }

        return $directory.'/database-connection-'.$connection->id.'.sock';
    }

    private function start(DatabaseConnection $connection): void
    {
        $process = new Process([
            'ssh',
            '-f',
            '-N',
            '-M',
            '-S',
            $this->controlSocketPath($connection),
            '-o',
            'ControlPersist=yes',
            '-o',
            'ExitOnForwardFailure=yes',
            '-o',
            'ServerAliveInterval=30',
            '-o',
            'ServerAliveCountMax=3',
            '-o',
            'StrictHostKeyChecking=accept-new',
            '-i',
            $connection->private_key_path,
            '-L',
            $this->localPort($connection).':'.$connection->database_host.':'.$connection->database_port,
            '-p',
            (string) $connection->port,
            $this->destination($connection),
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Unable to establish the SSH tunnel.');
        }
    }

    private function destination(DatabaseConnection $connection): string
    {
        return $connection->ssh_username.'@'.$connection->host;
    }
}

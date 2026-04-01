<?php

namespace App\Http\Controllers;

use App\Models\DatabaseConnection;
use App\Services\Herd\MySqlManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseExportController extends Controller
{
    public function __invoke(Request $request, string $database, MySqlManager $manager): BinaryFileResponse
    {
        $source = (string) $request->query('source', 'local');
        $connection = null;

        if (str_starts_with($source, 'connection:')) {
            $connectionId = (int) str($source)->after('connection:')->value();
            $connection = DatabaseConnection::query()->findOrFail($connectionId);
        }

        $exportPath = $manager->exportDatabase($database, $connection);

        return response()->download(
            $exportPath,
            basename($exportPath),
            ['Content-Type' => 'application/sql'],
        )->deleteFileAfterSend();
    }
}

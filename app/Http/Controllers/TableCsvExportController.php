<?php

namespace App\Http\Controllers;

use App\Models\DatabaseConnection;
use App\Services\Herd\MySqlManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TableCsvExportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, string $database, string $table, MySqlManager $manager): BinaryFileResponse
    {
        $source = (string) $request->query('source', 'local');
        $connection = null;

        if (str_starts_with($source, 'connection:')) {
            $connectionId = (int) str($source)->after('connection:')->value();
            $connection = DatabaseConnection::query()->findOrFail($connectionId);
        }

        $exportPath = $manager->exportTableCsv(
            $database,
            $table,
            (string) $request->query('search', ''),
            $request->query('sort_column') ? (string) $request->query('sort_column') : null,
            (string) $request->query('sort_direction', 'asc'),
            $connection,
        );

        return response()->download(
            $exportPath,
            basename($exportPath),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        )->deleteFileAfterSend();
    }
}

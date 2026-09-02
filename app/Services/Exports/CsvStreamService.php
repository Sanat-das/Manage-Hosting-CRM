<?php

namespace App\Services\Exports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reusable CSV streaming service.
 *
 * Streams CSV via StreamedResponse + fopen('php://output') + fputcsv, matching
 * the pattern in ReportsController::export but with Excel-friendly UTF-8 BOM,
 * proper cache headers and chunk-safe defaults.
 *
 * Rollout pattern for other datatable indexes (customers/orders/tickets/users):
 *
 *   public function index(Request $request): View|StreamedResponse
 *   {
 *       $query = Customer::query()->with('user')
 *           ->when($search !== '', fn ($q) => $q->where(...))
 *           ->gridSort([...])
 *           ->orderByDesc('id');
 *
 *       if ($request->query('export') === 'csv') {
 *           return app(CsvStreamService::class)->stream(
 *               'customers-'.now()->format('Y-m-d_His').'.csv',
 *               ['ID','Name','Email','Company','Balance','Status','Created At'],
 *               function ($handle) use ($query): void {
 *                   $query->with('user')->chunk(500, function ($rows) use ($handle): void {
 *                       foreach ($rows as $row) {
 *                           fputcsv($handle, [ $row->id, $row->full_name, $row->user?->email ?? '' ]);
 *                       }
 *                   });
 *               }
 *           );
 *       }
 *
 *       $customers = (clone $query)->paginate(20)->withQueryString();
 *       return view('admin.customers.index', compact('customers'));
 *   }
 */
class CsvStreamService
{
    private const CHUNK_SIZE = 500;

    /**
     * Stream a CSV file.
     *
     * @param string   $filename File name for Content-Disposition (e.g. invoices-2026-09-01_120000.csv)
     * @param string[] $headers  Header row cells
     * @param callable $writer   Receives the php://output handle; should use fputcsv() for proper escaping
     * @param bool     $withBom  Prepend UTF-8 BOM for Excel compatibility
     */
    public function stream(string $filename, array $headers, callable $writer, bool $withBom = true): StreamedResponse
    {
        $safeFilename = str_replace(['"', "\r", "\n"], '', $filename);

        $responseHeaders = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$safeFilename.'"',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
        ];

        $callback = function () use ($headers, $writer, $withBom): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            if ($withBom) {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            fputcsv($handle, $headers);

            $writer($handle);

            fclose($handle);
        };

        return response()->stream($callback, 200, $responseHeaders);
    }

    /**
     * Convenience: chunk size used by callers.
     */
    public static function chunkSize(): int
    {
        return self::CHUNK_SIZE;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Repository\DealsExportRepository;

/**
 * Thin service over the deals export (YON-67), mirroring the revenue/conversion
 * services: the controller talks to this, this delegates to the repository.
 * Also owns CSV serialisation of the repository payload so the row/column
 * contract stays in one place.
 */
class DealsExportService
{
    public DealsExportRepository $repo;

    public function __construct()
    {
        $this->repo = resolve(DealsExportRepository::class);
    }

    public function export(string $from, string $to, string $status, ?int $boardId = null): array
    {
        return $this->repo->export($from, $to, $status, $boardId);
    }

    /**
     * Serialise an export payload to CSV text (RFC-4180 quoting). The header row
     * uses the human column labels; every data cell is stringified from the
     * matching column key so the CSV column order can never drift from the JSON.
     *
     * @param  array{columns:array<int,array{key:string,label:string}>,rows:array<int,array<string,mixed>>}  $payload
     */
    public function toCsv(array $payload): string
    {
        $columns = $payload['columns'];
        $out = fopen('php://temp', 'r+');

        // Excel opens UTF-8 CSVs correctly only with a BOM; otherwise accented
        // client names come out garbled.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array_map(fn ($c) => $c['label'], $columns));

        foreach ($payload['rows'] as $row) {
            fputcsv($out, array_map(function ($c) use ($row) {
                $v = $row[$c['key']] ?? '';

                return is_float($v) ? number_format($v, 2, '.', '') : (string) $v;
            }, $columns));
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}

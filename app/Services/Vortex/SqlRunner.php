<?php

namespace App\Services\Vortex;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Executes a single raw SQL statement for the Vortex console.
 *
 * Safety model (single trusted admin, "safe-ish" by design):
 * - one statement only (interior semicolons rejected)
 * - read-only statements always roll back
 * - writes run inside a transaction and only persist when commit=true
 *   (default is a dry-run that reports affected rows, then rolls back)
 * - result rows are capped, statement_timeout set on pgsql
 */
class SqlRunner
{
    private const READ_KEYWORDS = ['select', 'with', 'explain', 'values', 'show'];

    /** @return array<string, mixed> */
    public function run(string $sql, bool $commit = false): array
    {
        $sql = trim($sql);
        $sql = rtrim($sql, "; \t\n\r");

        if ($sql === '') {
            return ['error' => 'Empty statement.'];
        }
        if (str_contains($sql, ';')) {
            return ['error' => 'Multiple statements are not allowed — run one at a time.'];
        }

        $firstWord = strtolower(strtok(ltrim($sql), " \t\n\r(")) ?: '';
        $isRead = in_array($firstWord, self::READ_KEYWORDS, true);
        $driver = DB::connection()->getDriverName();

        $start = microtime(true);

        DB::beginTransaction();

        try {
            if ($driver === 'pgsql') {
                DB::statement('SET LOCAL statement_timeout = '.(int) config('vortex.sql_timeout_ms'));
                if ($isRead) {
                    DB::statement('SET TRANSACTION READ ONLY');
                }
            }

            if ($isRead) {
                $rows = DB::select($sql);
                DB::rollBack();

                $cap = (int) config('vortex.sql_max_rows');
                $capped = array_slice(array_map(fn ($r) => (array) $r, $rows), 0, $cap);

                return [
                    'type' => 'select',
                    'columns' => $capped === [] ? [] : array_keys($capped[0]),
                    'rows' => $capped,
                    'row_count' => count($rows),
                    'truncated' => count($rows) > $cap,
                    'committed' => false,
                    'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                ];
            }

            $affected = DB::affectingStatement($sql);
            $commit ? DB::commit() : DB::rollBack();

            return [
                'type' => 'write',
                'columns' => [],
                'rows' => [],
                'affected' => $affected,
                'committed' => $commit,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        } catch (QueryException $e) {
            DB::rollBack();

            return ['error' => $e->getMessage()];
        }
    }
}

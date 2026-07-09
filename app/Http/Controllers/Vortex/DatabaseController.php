<?php

namespace App\Http\Controllers\Vortex;

use App\Http\Controllers\Controller;
use App\Services\Vortex\SqlRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseController extends Controller
{
    public function tables()
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $rows = DB::select(
                'SELECT relname AS name, n_live_tup AS approx_rows, pg_total_relation_size(relid) AS size_bytes
                 FROM pg_stat_user_tables ORDER BY size_bytes DESC'
            );

            $tables = array_map(fn ($r) => [
                'name' => $r->name,
                'rows' => (int) $r->approx_rows,
                'size_bytes' => (int) $r->size_bytes,
            ], $rows);
        } else {
            $tables = [];
            foreach ($this->tableNames() as $name) {
                $tables[] = [
                    'name' => $name,
                    'rows' => (int) DB::table($name)->count(),
                    'size_bytes' => null,
                ];
            }
            usort($tables, fn ($a, $b) => $b['rows'] <=> $a['rows']);
        }

        return response()->json(['driver' => $driver, 'tables' => $tables]);
    }

    public function rows(Request $request, string $table)
    {
        abort_unless(in_array($table, $this->tableNames(), true), 404, "Unknown table: {$table}");

        $columns = Schema::getColumnListing($table);

        $data = $request->validate([
            'sort' => 'sometimes|string',
            'dir' => 'sometimes|in:asc,desc',
            'per_page' => 'sometimes|integer|min:1|max:200',
        ]);

        // Identifiers can't be bound as parameters — whitelist against the real
        // table/column listings instead.
        $sort = in_array($data['sort'] ?? '', $columns, true)
            ? $data['sort']
            : (in_array('id', $columns, true) ? 'id' : $columns[0]);

        $page = DB::table($table)
            ->orderBy($sort, $data['dir'] ?? 'desc')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['columns' => $columns] + $page->toArray());
    }

    public function query(Request $request, SqlRunner $runner)
    {
        $data = $request->validate([
            'sql' => 'required|string|max:20000',
            'commit' => 'sometimes|boolean',
        ]);

        $result = $runner->run($data['sql'], (bool) ($data['commit'] ?? false));

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    /** @return string[] */
    private function tableNames(): array
    {
        // getTableListing() may return schema-qualified names on pgsql — normalize.
        return array_map(
            fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t,
            Schema::getTableListing()
        );
    }
}

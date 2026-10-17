<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Yutopia has one drawing style now (yutopia docs/plan/22-people-3d.md, P0):
// City-pop is gone and everyone is Ligne claire, the people who picked
// City-pop too. Rows without a style get it written out as well.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('yutopia_avatars')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $layers = json_decode($row->layers, true);
                if (! is_array($layers) || ($layers['style'] ?? null) === 'ligne') {
                    continue;
                }
                $layers['style'] = 'ligne';
                DB::table('yutopia_avatars')->where('id', $row->id)->update(['layers' => json_encode($layers)]);
            }
        });
    }

    // Nothing to undo: who had City-pop isn't kept.
    public function down(): void {}
};

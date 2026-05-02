<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')->get();

        foreach ($users as $user) {
            $projectId = DB::table('projects')->insertGetId([
                'owner_id'   => $user->id,
                'name'       => 'Personal',
                'description' => null,
                'color'      => '#1976D2',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('project_user')->insert([
                'project_id' => $projectId,
                'user_id'    => $user->id,
                'role'       => 'owner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('boards')
                ->where('user_id', $user->id)
                ->whereNull('project_id')
                ->update(['project_id' => $projectId]);
        }
    }

    public function down(): void
    {
        DB::table('boards')->update(['project_id' => null]);
        DB::table('project_user')->delete();
        DB::table('projects')->delete();
    }
};

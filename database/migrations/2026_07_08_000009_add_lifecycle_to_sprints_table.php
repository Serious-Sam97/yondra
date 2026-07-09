<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sprints', function (Blueprint $table) {
            // Lifecycle: a sprint moves future → active → completed.
            $table->string('status', 12)->default('future')->after('name');
            $table->string('goal')->nullable()->after('status');
            $table->timestamp('started_at')->nullable()->after('end_date');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            // Scope frozen at start (committed) and at completion (completed).
            $table->unsignedInteger('committed_points')->nullable()->after('completed_at');
            $table->unsignedInteger('committed_count')->nullable()->after('committed_points');
            $table->unsignedInteger('completed_points')->nullable()->after('committed_count');
            $table->unsignedInteger('completed_count')->nullable()->after('completed_points');
            // Ticket set snapshot taken on completion, so the report/burndown survive
            // incomplete tickets being moved out of the sprint.
            $table->json('report_snapshot')->nullable()->after('completed_count');
        });

        // Carry the v1 is_active flag into the new status column.
        DB::table('sprints')->where('is_active', true)->update(['status' => 'active']);
    }

    public function down(): void {
        Schema::table('sprints', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'goal', 'started_at', 'completed_at',
                'committed_points', 'committed_count', 'completed_points', 'completed_count',
                'report_snapshot',
            ]);
        });
    }
};

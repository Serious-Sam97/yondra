<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sentinel v2 — graduates the approved prototype:
//  1. Structured BDD: reusable steps carry Gherkin lines; case timeline (step_refs)
//     already JSON, so local blocks + per-block evidence live inside it (no column).
//  2. Execution-as-checklist: runs stay append-only, gaining an OPTIONAL per-block
//     `items` result set + a `source` (manual|ci).
//  3. Quality Gate: a human `verdict` on the case, separate from the run-derived status.
//  4. Webhook CI: a per-case `ci_token` external pipelines POST to.
return new class extends Migration
{
    public function up(): void
    {
        // 1 — global blocks become structured: [{ keyword, text }]
        Schema::table('reusable_steps', function (Blueprint $table) {
            $table->json('gherkin_lines')->nullable()->after('content');
        });

        // 3 + 4 — human verdict and CI token on the case.
        Schema::table('test_cases', function (Blueprint $table) {
            $table->string('verdict')->nullable()->after('awaiting_retest'); // approved|rejected|blocked|awaiting_info
            $table->foreignId('verdict_by_user_id')->nullable()->after('verdict')->constrained('users')->nullOnDelete();
            $table->timestamp('verdict_at')->nullable()->after('verdict_by_user_id');
            $table->string('ci_token', 64)->nullable()->unique()->after('verdict_at');
        });

        // 2 — optional checklist layer on the (still append-only) run.
        Schema::table('test_runs', function (Blueprint $table) {
            $table->json('items')->nullable()->after('logs'); // [{ block_key, block_title, ok, evidence, bug_card_id, lines }]
            $table->string('source')->default('manual')->after('items'); // manual|ci
        });
    }

    public function down(): void
    {
        Schema::table('test_runs', function (Blueprint $table) {
            $table->dropColumn(['items', 'source']);
        });
        Schema::table('test_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verdict_by_user_id');
            $table->dropColumn(['verdict', 'verdict_at', 'ci_token']);
        });
        Schema::table('reusable_steps', function (Blueprint $table) {
            $table->dropColumn('gherkin_lines');
        });
    }
};

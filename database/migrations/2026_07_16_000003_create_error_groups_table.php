<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vortex "Anomalies" — a self-hosted, Bugsnag-style error monitor (YON-74).
     *
     * Two tables model the classic grouping: an `error_groups` row is one distinct
     * fault (identified by its `fingerprint` — class + normalised message + origin
     * frame), and each real occurrence of that fault is an `error_occurrences` row.
     * So a bug that fires 400 times is ONE group with occurrences_count = 400 and a
     * rolling window of the latest individual hits (capped by config), not 400 rows
     * of noise. The group carries the triage state (open / resolved / ignored) the
     * admin drives from the Vortex Anomalies panel; occurrences carry the stack +
     * request context for a single hit.
     */
    public function up(): void
    {
        Schema::create('error_groups', function (Blueprint $table) {
            $table->id();
            // sha1(class | normalised message | file:line) — the grouping key.
            $table->string('fingerprint')->unique();
            // Where the error was captured: 'backend' (Laravel report hook) or
            // 'frontend' (browser ingest webhook).
            $table->string('source')->default('backend');
            // Severity bucket driving the LED colour: error | warning | info.
            $table->string('level')->default('error');
            $table->string('exception_class');
            // Representative (latest) message; individual messages live on occurrences.
            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            // Triage state: open | resolved | ignored.
            $table->string('status')->default('open');
            $table->unsignedInteger('occurrences_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // The list view filters by status and orders by recency.
            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('error_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('error_group_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            // Normalised stack frames [{file,line,function,class}] (backend) or the
            // raw browser stack string wrapped as one frame (frontend).
            $table->json('stack')->nullable();
            // { url, method, route, user_id, ip, input, ... } — whatever the reporter
            // could gather about the request that produced this hit.
            $table->json('context')->nullable();
            $table->string('environment')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['error_group_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_occurrences');
        Schema::dropIfExists('error_groups');
    }
};

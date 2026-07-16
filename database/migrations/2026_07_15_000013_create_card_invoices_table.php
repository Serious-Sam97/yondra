<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nota fiscal / invoice ledger (YON-68). One issued invoice per card — the
        // unique card_id makes issuing idempotent: the 100% milestone (or a manual
        // "issue" click) upserts this row and (re)generates the attached PDF, keeping
        // the invoice number stable across re-issues. `number` is a per-board sequence.
        Schema::create('card_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            // The generated PDF lives as a CardDocument; keep the invoice if it's gone.
            $table->foreignId('document_id')->nullable()->constrained('card_documents')->nullOnDelete();
            $table->unsignedInteger('number'); // per-board sequential invoice number
            $table->string('currency', 3)->default('BRL');
            $table->decimal('amount', 12, 2)->default(0);
            // Point-in-time snapshots so a later issuer/contact edit never rewrites a
            // document that was already handed to a client.
            $table->json('issuer')->nullable();
            $table->json('recipient')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('card_id');            // one invoice per card
            $table->unique(['board_id', 'number']); // no duplicate numbers within a board
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_invoices');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The installment ledger for a deal (YON-63). Each row is one payment received
        // against a CRM card; the card's total paid = SUM(amount). Models "real payment
        // stages" (deposit, second installment, final) with history + an audit trail.
        Schema::create('card_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            // Denormalised for board-scoped queries without joining through cards.
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();
            // Effective date of the payment (may differ from when it was recorded).
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['card_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_payments');
    }
};

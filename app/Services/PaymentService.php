<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardPayment;
use App\Jobs\EvaluatePaymentMilestonesJob;

/**
 * Payment ledger for CRM deals (YON-63). Records installments, keeps the cached
 * cards.amount_paid in sync, and — after a payment lands — kicks off milestone
 * evaluation (the "50% paid → message", "100% paid → move to invoice" engine).
 */
class PaymentService
{
    private const CURRENCY_SYMBOLS = [
        'BRL' => 'R$', 'USD' => '$', 'EUR' => '€', 'GBP' => '£',
    ];

    /** Add a payment, refresh the cached total, then evaluate payment milestones. */
    public function record(Card $card, array $data, ?int $userId): CardPayment
    {
        $payment = CardPayment::create([
            'card_id' => (int) $card->id,
            'board_id' => (int) $card->board_id,
            'amount' => $data['amount'],
            'note' => $data['note'] ?? null,
            'paid_at' => $data['paid_at'] ?? now(),
            'recorded_by_user_id' => $userId,
        ]);

        $this->recomputeAmountPaid($card);
        EvaluatePaymentMilestonesJob::dispatch((int) $card->id);

        return $payment;
    }

    /**
     * Remove a payment and refresh the cached total. Milestones already fired are
     * NOT un-fired — the event log is historical — only the running total drops.
     */
    public function remove(CardPayment $payment): void
    {
        $card = $payment->card;
        $payment->delete();

        if ($card) {
            $this->recomputeAmountPaid($card);
        }
    }

    /** Cache SUM(payments.amount) onto cards.amount_paid; returns the new total. */
    public function recomputeAmountPaid(Card $card): float
    {
        $total = (float) CardPayment::where('card_id', $card->id)->sum('amount');
        // Guarded update so we never fire the section-automation hook (only cares
        // about section_id) and never churn if the total is unchanged.
        if ((float) $card->amount_paid !== $total) {
            $card->update(['amount_paid' => $total]);
        }

        return $total;
    }

    /** Headline numbers for the card Payments panel. `payment_pct` is null when no deal value. */
    public function summary(Card $card): array
    {
        $value = $card->value !== null ? (float) $card->value : null;
        $paid = (float) $card->amount_paid;
        $pct = ($value !== null && $value > 0) ? min(100.0, round($paid / $value * 100, 1)) : null;

        return [
            'value' => $value,
            'amount_paid' => round($paid, 2),
            'amount_remaining' => $value !== null ? round(max(0.0, $value - $paid), 2) : null,
            'payment_pct' => $pct,
            'currency' => $card->board?->currency ?? 'BRL',
        ];
    }

    public function formatMoney(mixed $value, string $currency): string
    {
        $symbol = self::CURRENCY_SYMBOLS[$currency] ?? ($currency.' ');

        return $symbol.number_format((float) $value, 2);
    }
}

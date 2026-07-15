<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Contact;
use App\Infrastructure\Models\EmailStageAutomation;
use App\Infrastructure\Models\EmailStageSend;
use App\Mail\StageAutomationMail;
use App\Services\Email\SpamSafeFormatter;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Stage-triggered client emails (card #53). Sibling of the WhatsApp stage automation
 * in {@see WhatsappService}: when a card enters a section that has an enabled email
 * template, render it with the card/contact variables and mail the card's contact.
 */
class EmailAutomationService
{
    private const CURRENCY_SYMBOLS = [
        'BRL' => 'R$', 'USD' => '$', 'EUR' => '€', 'GBP' => '£',
    ];

    /**
     * Run the configured email automation for a card that just entered a section.
     * Guardrails: the automation must exist and be active, and the card must carry a
     * contact with an email — we never invent a recipient.
     */
    public function runStageAutomation(int $cardId, int $sectionId): void
    {
        $card = Card::with(['board', 'contact', 'section'])->find($cardId);
        // Ignore if the card moved on again before the job ran.
        if (! $card || (int) $card->section_id !== $sectionId) {
            return;
        }

        $automation = EmailStageAutomation::where('board_id', $card->board_id)
            ->where('section_id', $sectionId)
            ->first();
        if (! $automation || ! $automation->isActive()) {
            return;
        }

        $contact = $card->contact;
        if (! $contact || ! $contact->email) {
            return;
        }

        // Opt-in gate (YON-52): when the board requires confirmation, hold the quote
        // until the contact has clicked the opt-in link — otherwise it just feeds spam.
        if (($card->board?->require_optin_before_email ?? false) && ! $contact->isConfirmed()) {
            EmailStageSend::create([
                'card_id' => (int) $card->id,
                'section_id' => $sectionId,
                'contact_id' => (int) $contact->id,
                'email' => $contact->email,
                'subject' => $automation->subject,
                'status' => 'skipped',
                'error' => 'Contact has not confirmed opt-in.',
            ]);

            return;
        }

        $vars = $this->variables($card, $contact);
        $subject = $this->interpolate($automation->subject, $vars);
        $bodyText = $this->interpolate($automation->body, $vars);

        // Deliverability pass (YON-51): strip currency symbols / soften quote keywords
        // that push the email into Gmail spam. Board-toggled, on by default.
        if ($card->board?->email_spam_safe ?? true) {
            $formatter = new SpamSafeFormatter;
            $currency = $card->board?->currency ?? 'BRL';
            $subject = $formatter->naturalize($subject, $currency);
            $bodyText = $formatter->naturalize($bodyText, $currency);
        }

        $send = EmailStageSend::create([
            'card_id' => (int) $card->id,
            'section_id' => $sectionId,
            'contact_id' => (int) $contact->id,
            'email' => $contact->email,
            'subject' => $subject,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        try {
            Mail::to($contact->email)->send(new StageAutomationMail(
                subjectLine: $subject,
                eyebrow: $card->section?->name ?? 'Update',
                // Author writes plain text with {{variables}}; keep it safe + preserve breaks.
                bodyHtml: nl2br(e($bodyText)),
            ));
        } catch (Throwable $e) {
            $send->update(['status' => 'failed', 'error' => $e->getMessage()]);
            report($e);
        }
    }

    /**
     * Send an ad-hoc templated email to a card's contact, reusing the stage-email
     * variables, opt-in gate, and spam-safe pass. Returns
     * ['status' => sent|failed|skipped, 'error' => ?string]. No EmailStageSend row is
     * written — the caller owns its own audit trail (payment milestones use their
     * event log). Used by the payment milestone engine (YON-63).
     *
     * @param  array<string,string>  $extraVars
     */
    public function sendToCard(Card $card, string $subject, string $body, array $extraVars = [], ?string $eyebrow = null): array
    {
        $card->loadMissing(['board', 'contact', 'section']);
        $contact = $card->contact;
        if (! $contact || ! $contact->email) {
            return ['status' => 'skipped', 'error' => 'Card has no contact email.'];
        }

        if (($card->board?->require_optin_before_email ?? false) && ! $contact->isConfirmed()) {
            return ['status' => 'skipped', 'error' => 'Contact has not confirmed opt-in.'];
        }

        $vars = array_merge($this->variables($card, $contact), $extraVars);
        $subjectOut = $this->interpolate($subject, $vars);
        $bodyOut = $this->interpolate($body, $vars);

        if ($card->board?->email_spam_safe ?? true) {
            $formatter = new SpamSafeFormatter;
            $currency = $card->board?->currency ?? 'BRL';
            $subjectOut = $formatter->naturalize($subjectOut, $currency);
            $bodyOut = $formatter->naturalize($bodyOut, $currency);
        }

        try {
            Mail::to($contact->email)->send(new StageAutomationMail(
                subjectLine: $subjectOut,
                eyebrow: $eyebrow ?? ($card->section?->name ?? 'Update'),
                bodyHtml: nl2br(e($bodyOut)),
            ));

            return ['status' => 'sent', 'error' => null];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /** Template variables available in a stage email's subject and body. */
    private function variables(Card $card, Contact $contact): array
    {
        $board = $card->board;
        $value = $card->value;

        return [
            'contact_name' => $contact->name ?: 'there',
            'contact_email' => (string) ($contact->email ?? ''),
            'card_name' => (string) ($card->name ?? ''),
            'ticket_key' => Card::ticketKey($board?->ticket_prefix, $card->ticket_number),
            'stage' => (string) ($card->section?->name ?? ''),
            'deal_value' => $value !== null ? $this->formatMoney($value, $board?->currency ?? 'BRL') : '',
            // The "urgency trigger": the card's due date, if the deal has one.
            'deadline' => $card->due_date ? $card->due_date->format('F j, Y') : '',
        ];
    }

    /** Replace {{ var }} tokens; unknown tokens collapse to empty string. */
    private function interpolate(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn (array $m): string => (string) ($vars[$m[1]] ?? ''),
            $template,
        ) ?? $template;
    }

    private function formatMoney(mixed $value, string $currency): string
    {
        $symbol = self::CURRENCY_SYMBOLS[$currency] ?? ($currency.' ');

        return $symbol.number_format((float) $value, 2);
    }
}

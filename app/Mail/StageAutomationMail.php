<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A client-facing, stage-triggered email built from a board's per-stage template.
 * Unlike {@see NotificationMail} this is NOT ShouldQueue — it is sent from inside
 * the already-queued {@see \App\Jobs\SendStageEmailJob}, so queueing again would
 * defer it twice. `$bodyHtml` is pre-rendered (interpolated + escaped) by
 * {@see \App\Services\EmailAutomationService}.
 */
class StageAutomationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $eyebrow,
        public string $bodyHtml,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stage-automation',
            with: [
                'eyebrow' => $this->eyebrow,
                'bodyHtml' => $this->bodyHtml,
            ],
        );
    }
}

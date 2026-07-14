<?php

declare(strict_types=1);

namespace App\Mail;

use App\Jobs\SendIntakeConfirmationJob;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The double-opt-in email sent to a form submitter (YON-52). Clicking its single
 * button hits the public confirm endpoint, which whitelists the sender so the
 * eventual quote lands in the inbox instead of spam. Sent from inside the queued
 * {@see SendIntakeConfirmationJob}, so it is NOT ShouldQueue itself.
 * Copy is deliberately plain (no prices/currency) to stay out of spam.
 */
class IntakeConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $contactName,
        public string $confirmUrl,
        public string $boardName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Please confirm your request');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.intake-confirmation',
            with: [
                'contactName' => $this->contactName,
                'confirmUrl' => $this->confirmUrl,
                'boardName' => $this->boardName,
            ],
        );
    }
}

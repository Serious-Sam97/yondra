<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Q-01/Q-02 · his emails (opt-in): the weekly Void Report, and the single
 * "you vanished" note after 14 days away. No tracking pixels; one-click
 * unsubscribe. System emails (invites, password resets) never use his voice.
 */
class VoidReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subjectLine,
        public string $label,
        public array $lines,
        public ?string $ps,
        public string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.void-report', with: [
            'label' => $this->label,
            'lines' => $this->lines,
            'ps' => $this->ps,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ]);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}

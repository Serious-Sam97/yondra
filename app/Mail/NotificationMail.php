<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic branded email for any Yondra notification. Queued so sending never
 * blocks the request; dispatched by {@see Notifier} only when the
 * recipient has the email channel enabled for that event type.
 */
class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $eyebrow,
        public string $heading,
        public ?string $actionUrl = null,
        public string $actionText = 'Open in Yondra',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            with: [
                'eyebrow' => $this->eyebrow,
                'heading' => $this->heading,
                'actionUrl' => $this->actionUrl,
                'actionText' => $this->actionText,
            ],
        );
    }
}

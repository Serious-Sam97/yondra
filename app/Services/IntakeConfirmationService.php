<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Models\Contact;
use App\Mail\IntakeConfirmationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends the opt-in confirmation email for a freshly-created intake contact (YON-52).
 * Mints the confirm token on first send; a no-op if the contact has no email or has
 * already confirmed. Sibling of {@see EmailAutomationService} on the outbound path.
 */
class IntakeConfirmationService
{
    public function sendFor(int $contactId): void
    {
        $contact = Contact::with('board')->find($contactId);
        if (! $contact || ! $contact->email || $contact->isConfirmed()) {
            return;
        }

        if (! $contact->confirm_token) {
            $contact->confirm_token = Str::random(64);
            $contact->save();
        }

        $url = route('intake.confirm', ['token' => $contact->confirm_token]);

        Mail::to($contact->email)->send(new IntakeConfirmationMail(
            contactName: $contact->name ?: 'there',
            confirmUrl: $url,
            boardName: $contact->board?->name ?? 'our team',
        ));
    }
}

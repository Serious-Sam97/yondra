<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Contact;
use App\Mail\IntakeConfirmationMail;
use Illuminate\Http\Response;

/**
 * Public landing endpoint a form submitter hits by clicking the opt-in link in
 * {@see IntakeConfirmationMail} (YON-52). The unguessable token in the
 * URL is the credential — same pattern as the intake webhook. Idempotent: a
 * re-click on an already-confirmed contact still shows success.
 */
class IntakeConfirmationController extends Controller
{
    public function confirm(string $token): Response
    {
        $contact = strlen($token) >= 20
            ? Contact::where('confirm_token', $token)->first()
            : null;

        if (! $contact) {
            return response()->view('intake.confirm-result', ['ok' => false, 'name' => null], 404);
        }

        if (! $contact->isConfirmed()) {
            $contact->confirmed_at = now();
            $contact->save();
        }

        return response()->view('intake.confirm-result', ['ok' => true, 'name' => $contact->name]);
    }
}

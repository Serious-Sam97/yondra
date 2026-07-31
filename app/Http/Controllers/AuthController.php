<?php

namespace App\Http\Controllers;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = Auth::user();

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Fire off the reset link (the broker handles token creation + throttling).
        Password::sendResetLink($request->only('email'));

        // Always respond generically so we don't reveal whether the email exists.
        return response()->json([
            'message' => 'If that email exists, a reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                // A reset is the recovery path after a compromise — revoke every token.
                $user->tokens()->delete();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successfully.']);
        }

        // Invalid/expired token or unknown email → 422 with the broker's message.
        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            // Destination for WhatsApp-channel notifications; empty clears it.
            'whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        if (array_key_exists('whatsapp_number', $validated)) {
            $digits = preg_replace('/\D+/', '', (string) $validated['whatsapp_number']);
            $validated['whatsapp_number'] = $digits !== '' ? $digits : null;
        }

        $user->update($validated);

        return response()->json($user);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update(['password' => Hash::make($request->password)]);

        // Revoke every other token so a password change ends any hijacked session;
        // only the session performing the change stays valid.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
            ->delete();

        return response()->json(['message' => 'Password updated successfully']);
    }

    /**
     * LGPD/GDPR right of access + portability: a machine-readable dump of the
     * account and everything the user owns, streamed as a JSON download.
     */
    public function exportData(Request $request)
    {
        $user = $request->user();

        $boards = $user->boards()
            ->with([
                'sections',
                'sprints',
                'tags:id,board_id,name,color,kind',
                'cards.checklistItems:id,card_id,text,is_done,position',
                'cards.comments:id,card_id,user_id,body,created_at',
                'cards.links:id,card_id,url,title,state,created_at',
                'cards.documents:id,card_id,original_name,size,created_at',
                'cards.tags:id,name',
                'cards.contact:id,name,email,phone',
                'cards.payments:id,card_id,amount,paid_at',
            ])
            ->get();

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'format' => 'yondra.account-export.v1',
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'whatsapp_number' => $user->whatsapp_number,
                'notification_preferences' => $user->notification_preferences,
                'created_at' => optional($user->created_at)->toIso8601String(),
            ],
            'boards' => $boards->map(function (Board $board) {
                return [
                    'id' => $board->id,
                    'name' => $board->name,
                    'type' => $board->type,
                    'description' => $board->description,
                    'created_at' => optional($board->created_at)->toIso8601String(),
                    'sections' => $board->sections,
                    'sprints' => $board->sprints,
                    'tags' => $board->tags,
                    'cards' => $board->cards->map(fn (Card $card) => [
                        'id' => $card->id,
                        'name' => $card->name,
                        'description' => $card->description,
                        'priority' => $card->priority,
                        'due_date' => $card->due_date,
                        'value' => $card->value,
                        'story_points' => $card->story_points,
                        'section_id' => $card->section_id,
                        'checklist_items' => $card->checklistItems,
                        'comments' => $card->comments,
                        'links' => $card->links,
                        'documents' => $card->documents,
                        'tags' => $card->tags->pluck('name'),
                        'contact' => $card->contact,
                        'payments' => $card->payments,
                    ]),
                ];
            }),
        ];

        $filename = 'yondra-data-'.now()->format('Y-m-d').'.json';

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * LGPD/GDPR right to erasure: permanently delete the account and everything
     * the user owns. Guarded by the current password. Owned boards/cards/sections
     * are removed explicitly (their FKs don't cascade from users); the database
     * cascades the rest — projects, shares, comments, notifications — off the
     * user delete, while cards on OTHER people's boards keep existing with the
     * assignee/author simply nulled.
     */
    public function destroyAccount(Request $request)
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user) {
            $boardIds = $user->boards()->pluck('id');

            // Cards and sections don't cascade from a board delete, so clear them
            // first — deleting a card row cascades its own children (comments,
            // checklist items, links, tags, payments, …) at the database level.
            Card::whereIn('board_id', $boardIds)->delete();
            Section::whereIn('board_id', $boardIds)->delete();

            // Boards cascade their remaining children (sprints, tags, contacts,
            // activity, messages, automations, …) on delete.
            Board::whereIn('id', $boardIds)->delete();

            // API tokens are a polymorphic table with no FK to cascade.
            $user->tokens()->delete();

            $user->delete();
        });

        return response()->json(['message' => 'Account deleted']);
    }
}

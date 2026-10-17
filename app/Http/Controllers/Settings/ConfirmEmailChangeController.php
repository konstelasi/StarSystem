<?php

namespace App\Http\Controllers\Settings;

use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * What the link in the confirmation email opens. The route is signed, so a
 * link can't be altered, and it only works for the account it was made for
 * while that account's waiting address is still the one it names.
 */
class ConfirmEmailChangeController extends Controller
{
    public function __construct(private readonly ActivityLog $activity) {}

    /**
     * Names an address in a link without putting the address in the URL.
     */
    public static function hash(string $email): string
    {
        return hash('sha256', mb_strtolower($email));
    }

    public function __invoke(Request $request, int $user, string $hash): RedirectResponse
    {
        /** @var User $account */
        $account = $request->user();

        abort_unless($account->id === $user, 403);

        $pending = $account->pending_email;

        if ($pending === null || ! hash_equals(self::hash($pending), $hash)) {
            return $this->refuse('This link no longer works. Ask for a new one from your profile.');
        }

        // The address was free when it was asked for. Someone may have taken it since.
        if (User::query()->where('email', $pending)->whereKeyNot($account->id)->exists()) {
            $account->forceFill(['pending_email' => null])->save();

            return $this->refuse('That address now belongs to another account, so it was not used.');
        }

        $old = $account->email;

        $account->forceFill(['email' => $pending, 'pending_email' => null, 'email_verified_at' => now()])->save();

        $this->activity->record(ActivityAction::ACCOUNT_UPDATED, null, [
            'changed' => ['email'],
            'email_from' => $old,
            'email_to' => $pending,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Your email is now {$pending}."]);

        return to_route('profile.edit');
    }

    private function refuse(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('profile.edit');
    }
}

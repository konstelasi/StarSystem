<?php

namespace App\Http\Controllers;

use App\Access\Invitations;
use App\Access\SiteAccess;
use App\Concerns\PasswordValidationRules;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use App\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page an invitation link opens, and the accepting.
 *
 * A new email makes an account here; the link proves the inbox, so the
 * account starts verified. An email that already has an account signs in as
 * usual first, with its two-factor step, and then accepts.
 */
class InvitationController extends Controller
{
    use PasswordValidationRules;

    public function __construct(
        private readonly Invitations $invitations,
        private readonly CurrentSite $site,
        private readonly SiteAccess $access,
    ) {}

    public function show(Request $request, string $token): InertiaResponse|RedirectResponse|Response
    {
        $invite = $this->invitations->find($token);

        if ($invite === null) {
            return $this->invalid($request);
        }

        $account = User::query()->where('email', $invite->email)->first();
        $user = Auth::user();

        // Remember the link, so signing in can bring them back to it.
        $request->session()->put('invitation_token', $token);

        if ($account !== null && $user === null) {
            return redirect()->route('login')->with('status', "Sign in as {$invite->email} to join {$this->site->get()->name}.");
        }

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'siteName' => $this->site->get()->name,
            'email' => $invite->email,
            'role' => $invite->role->name,
            'invitedBy' => $invite->inviter?->name,
            // new: make an account. join: signed in as the invited account.
            // other: signed in as someone else, who must sign out first. An
            // existing account without a session was sent to sign in above.
            'mode' => $account === null ? 'new' : (strtolower($user->email) === $invite->email ? 'join' : 'other'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse|Response
    {
        $invite = $this->invitations->find($token);

        if ($invite === null) {
            return $this->invalid($request);
        }

        $account = User::query()->where('email', $invite->email)->first();

        if ($account === null) {
            $user = $this->createAccount($request, $invite);
            Auth::login($user);
            $request->session()->regenerate();
        } else {
            $user = $request->user();

            abort_unless($user !== null && strtolower($user->email) === $invite->email, 403);
        }

        DB::transaction(function () use ($invite, $user) {
            SiteUser::query()->updateOrCreate(['user_id' => $user->id], ['role_id' => $invite->role_id]);
            $invite->update(['accepted_at' => now()]);
        });

        $request->session()->forget('invitation_token');
        $this->access->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => "You joined {$this->site->get()->name}."]);

        return redirect()->route('dashboard');
    }

    private function createAccount(Request $request, SiteInvite $invite): User
    {
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ])->validate();

        // The email was free when the page opened. If someone made the
        // account since, they must sign in instead.
        if (User::query()->where('email', $invite->email)->exists()) {
            throw ValidationException::withMessages(['name' => 'An account with this email now exists. Open the link again to sign in.']);
        }

        $user = new User(['name' => $data['name'], 'email' => $invite->email, 'password' => $data['password']]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * The same answer for an expired, used, withdrawn or made-up link, so a
     * stranger learns nothing about which it was.
     */
    private function invalid(Request $request): Response
    {
        return Inertia::render('auth/InvitationInvalid')->toResponse($request)->setStatusCode(410);
    }
}

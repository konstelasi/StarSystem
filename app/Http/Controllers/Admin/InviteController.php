<?php

namespace App\Http\Controllers\Admin;

use App\Access\Invitations;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use App\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Invitations from the Users page: invite, send again, withdraw.
 *
 * The link is flashed to the page whether or not the email went out, since
 * many shared hosts have no working mail and the link is shown only once.
 */
class InviteController extends Controller
{
    /**
     * Most addresses one request may invite.
     */
    private const MOST_AT_ONCE = 20;

    public function __construct(
        private readonly Invitations $invitations,
        private readonly SiteAccess $access,
        private readonly CurrentSite $site,
        private readonly ActivityLog $activity,
    ) {}

    /**
     * Invites one address, or several at once when the box holds more than
     * one, separated by commas, spaces or new lines. Each gets the same role.
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        $request->validate([
            'email' => ['required', 'string', 'max:5000'],
            'role_id' => ['required', 'integer', Rule::exists('ss_roles', 'id')->where(
                fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->site->tenantId()),
            )],
        ]);

        $emails = $this->addresses($request->string('email')->toString());
        $role = Role::query()->findOrFail($request->integer('role_id'));

        abort_unless($this->access->canAssign($actor, $role), 403);

        $invited = [];
        $skipped = 0;

        foreach ($emails as $email) {
            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null && ($existing->is_install_owner || SiteUser::query()->where('user_id', $existing->id)->exists())) {
                $skipped++;

                continue;
            }

            ['invite' => $invite, 'link' => $link] = $this->invitations->issue($email, $role, $actor);

            $this->activity->record(ActivityAction::INVITE_SENT, $invite, ['role' => $role->name]);

            $invited[] = [
                'email' => $invite->email,
                'link' => $link,
                'emailed' => $this->invitations->send($invite, $link, $actor),
                'mailConfigured' => $this->invitations->mailConfigured(),
            ];
        }

        if ($invited === []) {
            throw ValidationException::withMessages(['email' => count($emails) === 1 ? 'This person already has access to the site.' : 'Everyone listed already has access to the site.']);
        }

        if (count($invited) === 1 && count($emails) === 1) {
            Inertia::flash('invite', $invited[0]);

            return to_route('admin.users.index');
        }

        // Links are shown for the people the email did not reach. The rest
        // have theirs in their inbox.
        $unsent = array_values(array_filter($invited, fn (array $one) => ! $one['emailed']));

        if ($unsent !== []) {
            Inertia::flash('invites', $unsent);
        }

        $count = count($invited);
        $message = $count === 1 ? 'Invited 1 person.' : "Invited {$count} people.";

        if ($skipped > 0) {
            $message .= $skipped === 1 ? ' 1 already had access.' : " {$skipped} already had access.";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.users.index');
    }

    public function resend(Request $request, SiteInvite $invite): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->access->canAssign($actor, $invite->role), 403);

        $link = $this->invitations->reissue($invite);

        $this->activity->record(ActivityAction::INVITE_RESENT, $invite, ['role' => $invite->role->name]);

        return $this->shown($invite, $link, $actor);
    }

    public function destroy(Request $request, SiteInvite $invite): RedirectResponse
    {
        abort_unless($this->access->canAssign($this->actor($request), $invite->role), 403);

        $invite->delete();

        $this->activity->record(ActivityAction::INVITE_WITHDRAWN, $invite, ['role' => $invite->role->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "The invitation to {$invite->email} was withdrawn."]);

        return to_route('admin.users.index');
    }

    private function shown(SiteInvite $invite, string $link, User $actor): RedirectResponse
    {
        $emailed = $this->invitations->send($invite, $link, $actor);

        Inertia::flash('invite', [
            'email' => $invite->email,
            'link' => $link,
            'emailed' => $emailed,
            'mailConfigured' => $this->invitations->mailConfigured(),
        ]);

        return to_route('admin.users.index');
    }

    /**
     * The addresses typed into the box, lowercased and without repeats.
     *
     * @return list<string>
     *
     * @throws ValidationException when there are none, too many, or one is not an address
     */
    private function addresses(string $typed): array
    {
        $emails = array_values(array_unique(array_map(
            fn (string $email) => mb_strtolower($email),
            preg_split('/[\s,;]+/', $typed, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        )));

        if ($emails === []) {
            throw ValidationException::withMessages(['email' => 'Give an email address.']);
        }

        if (count($emails) > self::MOST_AT_ONCE) {
            throw ValidationException::withMessages(['email' => 'Invite at most '.self::MOST_AT_ONCE.' people at once.']);
        }

        $invalid = array_values(array_filter($emails, fn (string $email) => Validator::make(['email' => $email], ['email' => ['email:rfc', 'max:255']])->fails()));

        if ($invalid !== []) {
            throw ValidationException::withMessages(['email' => count($invalid) === 1
                ? "{$invalid[0]} does not look like an email address."
                : 'These do not look like email addresses: '.implode(', ', $invalid).'.']);
        }

        return $emails;
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

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
    public function __construct(
        private readonly Invitations $invitations,
        private readonly SiteAccess $access,
        private readonly CurrentSite $site,
        private readonly ActivityLog $activity,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('ss_roles', 'id')->where(
                fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->site->tenantId()),
            )],
        ]);

        $email = mb_strtolower(trim($request->string('email')->toString()));
        $role = Role::query()->findOrFail($request->integer('role_id'));

        abort_unless($this->access->canAssign($actor, $role), 403);

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && ($existing->is_install_owner || SiteUser::query()->where('user_id', $existing->id)->exists())) {
            throw ValidationException::withMessages(['email' => 'This person already has access to the site.']);
        }

        ['invite' => $invite, 'link' => $link] = $this->invitations->issue($email, $role, $actor);

        $this->activity->record(ActivityAction::INVITE_SENT, $invite, ['role' => $role->name]);

        return $this->shown($invite, $link, $actor);
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

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

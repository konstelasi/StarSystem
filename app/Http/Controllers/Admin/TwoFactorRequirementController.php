<?php

namespace App\Http\Controllers\Admin;

use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Security\TwoFactorRequirement;
use App\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Saves which roles must sign in with two-factor on this site.
 *
 * It refuses a list that would cover the person saving it before they have
 * turned two-factor on, since they would be locked out of this very page.
 */
class TwoFactorRequirementController extends Controller
{
    public function __construct(
        private readonly TwoFactorRequirement $requirement,
        private readonly CurrentSite $site,
        private readonly ActivityLog $activity,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['integer', Rule::exists('ss_roles', 'id')->where(
                fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->site->tenantId()),
            )],
        ]);

        $ids = array_values(array_unique(array_map(intval(...), $request->array('roles'))));

        if ($this->requirement->appliesTo($actor, $ids) && ! $actor->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'roles' => 'This would apply to you, and you have not turned on two-factor yet. Turn it on in your security settings first.',
            ]);
        }

        $before = $this->requirement->roleIds();

        if (array_diff($ids, $before) !== [] || array_diff($before, $ids) !== []) {
            $this->requirement->setRoleIds($ids);

            $this->activity->record(ActivityAction::TWO_FACTOR_REQUIREMENT_CHANGED, null, [
                'roles' => Role::query()->whereIn('id', $ids)->orderBy('id')->pluck('name')->all(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Two-factor requirement saved.']);

        return to_route('admin.roles.index');
    }
}

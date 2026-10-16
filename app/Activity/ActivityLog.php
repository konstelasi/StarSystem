<?php

namespace App\Activity;

use App\Models\Activity;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use App\Sites\CurrentSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writes the activity log.
 *
 * A failure to log is reported and swallowed, because a full disk or a long
 * name must not stop someone from signing in or changing a role.
 */
class ActivityLog
{
    public function __construct(private readonly CurrentSite $site) {}

    /**
     * Record that $actor, by default whoever is signed in, did $action.
     *
     * $subject is the thing it was done to. Pass a model to link it, or just
     * a string to name something with no row, such as the email typed into a
     * failed sign-in. Nothing is recorded without a current site, so a
     * command run from the terminal leaves no trace.
     *
     * @param  array<string, mixed>  $with
     */
    public function record(string $action, Model|string|null $subject = null, array $with = [], ?User $actor = null): void
    {
        if (! $this->site->has()) {
            return;
        }

        $actor ??= Auth::user();

        // A membership is logged as the person it belongs to.
        if ($subject instanceof SiteUser) {
            $subject = $subject->user;
        }

        try {
            Activity::query()->create([
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'action' => $action,
                'subject_type' => $subject instanceof Model ? $this->type($subject) : null,
                'subject_id' => $subject instanceof Model ? $subject->getKey() : null,
                'subject_label' => is_string($subject) ? $subject : ($subject instanceof Model ? $this->label($subject) : null),
                'properties' => $with === [] ? null : $with,
                'ip_address' => request()->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The words a row uses for a model: an account by its email, a role by
     * its name.
     */
    private function label(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof User, $subject instanceof SiteInvite => $subject->email,
            $subject instanceof Role, $subject instanceof Site => $subject->name,
            default => null,
        };
    }

    private function type(Model $subject): string
    {
        return match (true) {
            $subject instanceof User => 'user',
            $subject instanceof SiteInvite => 'invite',
            $subject instanceof Role => 'role',
            $subject instanceof Site => 'site',
            default => strtolower(class_basename($subject)),
        };
    }
}

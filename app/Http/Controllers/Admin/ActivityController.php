<?php

namespace App\Http\Controllers\Admin;

use App\Activity\ActivityAction;
use App\Activity\ActivityDescriber;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\SiteUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * What people did on this site, newest first.
 *
 * "Load more" asks for the next page with `cursor`, the id of the last row
 * it was given, and the client appends it, since `rows` is a merge prop. A
 * filter that makes no sense is ignored instead of refused, because it comes
 * from a link and there is nothing to correct.
 */
class ActivityController extends Controller
{
    private const PAGE_SIZE = 50;

    public function __invoke(Request $request): Response
    {
        $person = $request->integer('person') ?: null;
        $group = $request->query('group');
        $group = is_string($group) && array_key_exists($group, ActivityAction::groups()) ? $group : null;
        $from = $this->day($request->query('from'));
        $to = $this->day($request->query('to'));
        $cursor = $request->integer('cursor') ?: null;

        $rows = Activity::query()
            ->when($person !== null, fn ($query) => $query->where('actor_id', $person))
            ->when($group !== null, fn ($query) => $query->whereIn('action', array_keys(ActivityAction::groups()[$group])))
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<=', $to->endOfDay()))
            ->when($cursor !== null, fn ($query) => $query->where('id', '<', $cursor))
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $more = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);

        return Inertia::render('admin/activity/Index', [
            'rows' => Inertia::merge($rows->map(fn (Activity $row) => [
                'id' => $row->id,
                'at' => $row->created_at->toIso8601String(),
                'actor' => $row->actor_name,
                'action' => $row->action,
                'label' => ActivityAction::label($row->action),
                'subject' => $row->subject_label,
                'details' => ActivityDescriber::details($row),
                'ip' => $row->ip_address,
            ])->values()),
            'nextCursor' => $more ? (string) $rows->last()->id : null,
            'filters' => [
                'person' => $person,
                'group' => $group,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
            ],
            'people' => $this->people(),
            'groups' => array_keys(ActivityAction::groups()),
        ]);
    }

    /**
     * The people to pick from: everyone with access to this site, and the
     * install owner, who has it without a membership.
     *
     * @return list<array{id: int, name: string}>
     */
    private function people(): array
    {
        $users = User::query()
            ->whereIn('id', SiteUser::query()->select('user_id'))
            ->orWhere('is_install_owner', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return array_values($users->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all());
    }

    private function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        // PHP rolls 2026-13-45 over to a later real date, which isn't what was typed.
        return $day && $day->format('Y-m-d') === $value ? $day : null;
    }
}

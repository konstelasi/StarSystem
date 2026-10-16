<?php

namespace App\Security;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Where an account is signed in, and ending those sessions.
 *
 * This works on the `sessions` table, so it only exists when the session
 * driver is `database`, which is the default. With any other driver the
 * Security page leaves it out.
 *
 * Sessions are not tied to a site, so an account that belongs to several
 * sites sees the sessions it has on all of them.
 */
class Sessions
{
    public function available(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * The account's live sessions, most recently used first. Rows older than
     * the session lifetime are waiting to be cleaned up and aren't live.
     *
     * @return list<array{browser: string, os: string, ip: string|null, lastActive: string, current: bool}>
     */
    public function for(User $user, string $currentId): array
    {
        $rows = DB::table($this->table())
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', $this->oldestLive())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        $sessions = [];

        foreach ($rows as $row) {
            $sessions[] = [
                ...DeviceName::from($row->user_agent),
                'ip' => $row->ip_address,
                'lastActive' => CarbonImmutable::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                'current' => $row->id === $currentId,
            ];
        }

        return $sessions;
    }

    /**
     * Ends every session of the account except $keepId. Pass an empty string
     * to end them all, as after a forgotten password is reset.
     *
     * @return int how many were ended
     */
    public function endOthers(User $user, string $keepId = ''): int
    {
        if (! $this->available()) {
            return 0;
        }

        return DB::table($this->table())
            ->where('user_id', $user->id)
            ->when($keepId !== '', fn ($query) => $query->where('id', '!=', $keepId))
            ->delete();
    }

    private function table(): string
    {
        return config()->string('session.table', 'sessions');
    }

    private function oldestLive(): int
    {
        return CarbonImmutable::now()->subMinutes(config()->integer('session.lifetime'))->getTimestamp();
    }
}

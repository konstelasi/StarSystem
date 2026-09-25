<?php

namespace App\StarDust;

use Carbon\CarbonImmutable;

/**
 * Holds StarDust's background work while something (an update) must not
 * race the tick.
 *
 * A file rather than a table: the flag has to be readable while
 * migrations are half applied, and by the cron process, the tick URL and
 * the web request alike, with nothing but the filesystem in common.
 * Maintenance mode alone isn't enough, because a tick that started just
 * before it would keep running with the old code.
 */
class TickPause
{
    public function path(): string
    {
        return storage_path('framework/stardust-tick-paused');
    }

    public function pause(string $reason): void
    {
        file_put_contents($this->path(), json_encode([
            'reason' => $reason,
            'since' => CarbonImmutable::now()->toIso8601String(),
        ]), LOCK_EX);
    }

    public function resume(): void
    {
        if (is_file($this->path())) {
            unlink($this->path());
        }
    }

    public function active(): bool
    {
        return is_file($this->path());
    }

    /**
     * @return array{reason: string, since: ?string}|null
     */
    public function info(): ?array
    {
        if (! $this->active()) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($this->path()), true);

        // A flag someone created by hand still pauses; it just has no details.
        return [
            'reason' => is_array($data) && is_string($data['reason'] ?? null) ? $data['reason'] : 'unknown',
            'since' => is_array($data) && is_string($data['since'] ?? null) ? $data['since'] : null,
        ];
    }
}

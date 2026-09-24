<?php

namespace Tests\Concerns;

use App\Models\Site;
use App\Sites\CurrentSite;

/**
 * Helpers for cross-site tests. One missed query shows one site's data on
 * another, so every site-scoped feature gets a test that writes as one
 * site and reads as another.
 */
trait CrossSite
{
    protected function makeSite(string $domain, string $name = 'Other site'): Site
    {
        return Site::create(['name' => $name, 'domains' => [$domain]]);
    }

    protected function defaultSite(): Site
    {
        return Site::findOrFail(config('starsystem.default_site'));
    }

    protected function asSite(Site $site): static
    {
        app(CurrentSite::class)->set($site);

        return $this;
    }

    /**
     * Writes with $write as $owner, then asserts that $read, run as
     * $other, sees nothing.
     *
     * @param  callable(): mixed  $write
     * @param  callable(): iterable<mixed>|int  $read
     */
    protected function assertInvisibleAcrossSites(Site $owner, Site $other, callable $write, callable $read): void
    {
        $this->asSite($owner);
        $write();
        $seenByOwner = $read();

        $this->asSite($other);
        $seenByOther = $read();

        $count = fn ($result) => is_int($result) ? $result : count(is_array($result) ? $result : iterator_to_array($result));

        $this->assertGreaterThan(0, $count($seenByOwner), 'The owning site should see what it wrote.');
        $this->assertSame(0, $count($seenByOther), "Site {$other->id} can see site {$owner->id}'s data.");
    }
}

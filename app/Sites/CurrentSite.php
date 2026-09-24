<?php

namespace App\Sites;

use App\Models\Site;
use LogicException;

/**
 * The site the current request (or job) works on. Everything site-scoped
 * reads its tenant from here, and fails loudly when there is none rather
 * than falling back to a site the caller didn't choose.
 */
class CurrentSite
{
    private ?Site $site = null;

    public function set(Site $site): void
    {
        $this->site = $site;
    }

    public function get(): Site
    {
        return $this->site ?? throw new LogicException('No current site. Resolve one (ResolveSite middleware, or CurrentSite::set() in jobs and commands) before touching site data.');
    }

    public function tenantId(): int
    {
        return $this->get()->id;
    }

    public function has(): bool
    {
        return $this->site !== null;
    }

    public function forget(): void
    {
        $this->site = null;
    }
}

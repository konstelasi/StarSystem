<?php

namespace Tests\Feature\Sites;

use App\Models\SchemaChange;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class SiteResolutionTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    public function test_every_install_starts_with_the_default_site()
    {
        $this->assertSame(1, $this->defaultSite()->id);
    }

    public function test_single_site_mode_resolves_any_host_to_the_default_site()
    {
        $this->get('http://anything.test/')->assertOk();

        $this->assertSame(1, app(CurrentSite::class)->tenantId());
    }

    public function test_multisite_mode_resolves_the_site_from_the_host()
    {
        config(['starsystem.multisite' => true]);
        $shop = $this->makeSite('shop.example.com');

        $this->get('http://SHOP.example.com/')->assertOk();

        $this->assertSame($shop->id, app(CurrentSite::class)->tenantId());
    }

    public function test_multisite_mode_rejects_unknown_hosts()
    {
        config(['starsystem.multisite' => true]);

        $this->get('http://forged.example.com/')->assertNotFound();
    }

    public function test_site_data_needs_a_current_site()
    {
        app(CurrentSite::class)->forget();

        $this->expectException(LogicException::class);

        SchemaChange::query()->count();
    }

    public function test_schema_changes_are_invisible_across_sites()
    {
        $other = $this->makeSite('other.example.com');

        $this->assertInvisibleAcrossSites(
            owner: $other,
            other: $this->defaultSite(),
            write: fn () => SchemaChange::create(['op' => 'defineField']),
            read: fn () => SchemaChange::query()->count(),
        );
    }

    public function test_new_site_rows_get_the_current_site()
    {
        $other = $this->makeSite('other.example.com');
        $this->asSite($other);

        $this->assertSame($other->id, SchemaChange::create(['op' => 'defineField'])->tenant_id);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitors_see_the_holding_page_with_the_site_name()
    {
        $site = Site::query()->findOrFail(config()->integer('starsystem.default_site'));

        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('<h1>'.e($site->name).'</h1>', false)
            ->assertSee('Nothing has been published here yet.');

        // Plain HTML from the default theme, not an Inertia page.
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $response->assertDontSee('data-page=', false);
        $response->assertDontSee('id="app"', false);
        $this->assertSame([], $response->headers->getCookies());
    }
}

<?php

namespace Tests\Feature\Hooks;

use App\Hooks\Hook;
use App\Hooks\HookRegistry;
use App\Models\Site;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class HookRegistryTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    private HookRegistry $hooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hooks = app(HookRegistry::class);
    }

    public function test_filters_run_by_priority_then_in_the_order_they_were_added()
    {
        $this->hooks->addFilter('test.filter', fn (array $v) => [...$v, 'b'], 20);
        $this->hooks->addFilter('test.filter', fn (array $v) => [...$v, 'a1']);
        $this->hooks->addFilter('test.filter', fn (array $v) => [...$v, 'first'], 5);
        $this->hooks->addFilter('test.filter', fn (array $v) => [...$v, 'a2']);

        $this->assertSame(['first', 'a1', 'a2', 'b'], $this->hooks->applyFilters('test.filter', []));
    }

    public function test_a_filter_without_callbacks_returns_the_value_untouched()
    {
        $this->assertSame('same', $this->hooks->applyFilters('test.nothing', 'same', 'extra'));
        $this->assertFalse($this->hooks->hasFilter('test.nothing'));
    }

    public function test_filters_get_the_extra_arguments_then_the_current_site()
    {
        $site = $this->makeSite('shop.example.com');
        $this->asSite($site);

        $this->hooks->addFilter('backend.controller:entries:edit:data', function (array $data, string $model, ?Site $current) {
            return [...$data, 'model' => $model, 'site' => $current?->id];
        });

        $this->assertSame(
            ['title' => 'Hi', 'model' => 'articles', 'site' => $site->id],
            $this->hooks->applyFilters('backend.controller:entries:edit:data', ['title' => 'Hi'], 'articles'),
        );
    }

    public function test_handlers_get_null_when_no_site_is_resolved()
    {
        app(CurrentSite::class)->forget();
        $seen = 'unset';

        $this->hooks->addAction('test.action', function (?Site $site) use (&$seen) {
            $seen = $site;
        });
        $this->hooks->doAction('test.action');

        $this->assertNull($seen);
    }

    public function test_built_in_functions_work_as_filters()
    {
        $this->hooks->addFilter('test.title', 'trim');
        $this->hooks->addFilter('test.title', 'strtoupper');

        $this->assertSame('HELLO', $this->hooks->applyFilters('test.title', '  hello  '));
    }

    public function test_removing_a_filter()
    {
        $shout = fn (string $v) => strtoupper($v);
        $this->hooks->addFilter('test.title', $shout);
        $this->hooks->addFilter('test.title', $shout, 99);

        $this->assertTrue($this->hooks->removeFilter('test.title', $shout, 99));
        $this->assertTrue($this->hooks->hasFilter('test.title'));

        $this->assertTrue($this->hooks->removeFilter('test.title', $shout));
        $this->assertFalse($this->hooks->removeFilter('test.title', $shout));
        $this->assertFalse($this->hooks->hasFilter('test.title'));
        $this->assertSame('quiet', $this->hooks->applyFilters('test.title', 'quiet'));
    }

    public function test_actions_run_by_priority_with_their_arguments_and_the_site()
    {
        $this->asSite($this->defaultSite());
        $calls = [];

        $this->hooks->addAction('backend.controller:entries:edit', function (int $id, Site $site) use (&$calls) {
            $calls[] = "late {$id} {$site->id}";
        }, 50);
        $this->hooks->addAction('backend.controller:entries:edit', function (int $id) use (&$calls) {
            $calls[] = "early {$id}";
        }, 1);

        Hook::doAction('backend.controller:entries:edit', 7);

        $this->assertSame(['early 7', 'late 7 1'], $calls);
    }

    public function test_actions_reach_laravel_listeners()
    {
        $seen = null;
        Event::listen('backend.controller:settings', function (string $tab, ?Site $site) use (&$seen) {
            $seen = [$tab, $site?->id];
        });

        $this->asSite($this->defaultSite());
        $this->assertTrue($this->hooks->hasAction('backend.controller:settings'));

        $this->hooks->doAction('backend.controller:settings', 'general');

        $this->assertSame(['general', 1], $seen);
    }

    public function test_actions_can_be_faked_like_any_event()
    {
        $ran = false;
        $this->hooks->addAction('test.action', function () use (&$ran) {
            $ran = true;
        });

        Event::fake(['test.action']);
        $this->hooks->doAction('test.action', 'payload');

        Event::assertDispatched('test.action');
        $this->assertFalse($ran);
    }

    public function test_removing_an_action()
    {
        $count = 0;
        $bump = function () use (&$count) {
            $count++;
        };

        $this->hooks->addAction('test.action', $bump);
        $this->assertTrue($this->hooks->hasAction('test.action'));

        $this->hooks->removeAction('test.action', $bump);
        $this->hooks->doAction('test.action');

        $this->assertFalse($this->hooks->hasAction('test.action'));
        $this->assertSame(0, $count);
    }

    public function test_callbacks_added_by_an_owner_can_be_dropped_together()
    {
        $this->hooks->addFilter('test.list', fn (array $v) => [...$v, 'core']);
        $this->hooks->asOwner('broken', function () {
            $this->hooks->addFilter('test.list', fn (array $v) => [...$v, 'module']);
            $this->hooks->addAction('test.action', fn () => throw new \RuntimeException('boom'));
        });

        $this->hooks->removeOwnedBy('broken');

        $this->assertSame(['core'], $this->hooks->applyFilters('test.list', []));
        $this->hooks->doAction('test.action');
    }

    public function test_registries_are_built_from_a_keyed_filter()
    {
        // The pattern schema.field_types uses: core supplies the base list
        // and modules add or replace entries by key.
        $this->hooks->addFilter('schema.field_types', fn (array $types) => [...$types, 'rating' => ['label' => 'Rating']]);
        $this->hooks->addFilter('schema.field_types', function (array $types) {
            $types['text']['label'] = 'Short text';

            return $types;
        });

        $types = $this->hooks->applyFilters('schema.field_types', ['text' => ['label' => 'Text']]);

        $this->assertSame(['text' => ['label' => 'Short text'], 'rating' => ['label' => 'Rating']], $types);
    }
}

<?php

namespace Tests\Feature\Hooks;

use App\Hooks\Hook;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CrossSite;
use Tests\TestCase;
use UnexpectedValueException;

class ViewSlotTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    public function test_an_empty_slot_is_an_empty_list()
    {
        $this->assertSame([], Hook::viewSlot('backend.view:entries:edit'));
    }

    public function test_slot_items_come_back_in_priority_order_as_descriptors()
    {
        Hook::addToSlot('backend.view:entries:edit', 'LateButton', ['label' => 'Late'], 20);
        Hook::addToSlot('backend.view:entries:edit', 'example::Notice', ['tone' => 'info'], 5, '/_modules/example/components.js');
        Hook::addFilter('backend.view:entries:edit', fn (array $items) => [...$items, ['component' => 'Plain']]);

        $this->assertSame([
            ['component' => 'example::Notice', 'props' => ['tone' => 'info'], 'src' => '/_modules/example/components.js'],
            ['component' => 'Plain', 'props' => [], 'src' => null],
            ['component' => 'LateButton', 'props' => ['label' => 'Late'], 'src' => null],
        ], Hook::viewSlot('backend.view:entries:edit'));
    }

    public function test_props_callbacks_get_the_context_and_site_and_can_skip_the_item()
    {
        $this->asSite($this->defaultSite());

        Hook::addToSlot('backend.view:entries:edit', 'EditorButton', function (array $context, ?Site $site) {
            return $context['model'] === 'pages' ? ['entry' => $context['entry'], 'site' => $site?->id] : null;
        });

        $this->assertSame(
            [['component' => 'EditorButton', 'props' => ['entry' => 3, 'site' => 1], 'src' => null]],
            Hook::viewSlot('backend.view:entries:edit', ['model' => 'pages', 'entry' => 3]),
        );
        $this->assertSame([], Hook::viewSlot('backend.view:entries:edit', ['model' => 'articles', 'entry' => 4]));
    }

    public function test_a_malformed_item_names_the_slot()
    {
        Hook::addFilter('backend.view:settings', fn (array $items) => [...$items, ['props' => []]]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('backend.view:settings');

        Hook::viewSlot('backend.view:settings');
    }
}

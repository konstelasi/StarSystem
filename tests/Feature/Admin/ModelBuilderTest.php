<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModelBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const CORE_TYPES = [
        'text', 'email', 'url', 'color', 'hidden', 'radio', 'select', 'number',
        'range', 'checkbox', 'datetime', 'textarea', 'rich_text', 'code', 'checkboxes', 'file',
    ];

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('admin.models.builder'))->assertRedirect(route('login'));
    }

    public function test_it_renders_the_builder_with_field_types_schema_and_states()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.models.builder'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/models/Builder')
                ->has('fieldTypes', count(self::CORE_TYPES))
                ->has('fieldTypes.0', fn (Assert $type) => $type
                    ->has('key')
                    ->has('label')
                    ->has('icon')
                    ->has('category')
                    ->has('settings')
                    ->has('storage', fn (Assert $storage) => $storage
                        ->has('declaredType')
                        ->has('canFilter')
                    )
                )
                ->where('schema.version', 1)
                ->where('schema.model.slug', 'article')
                ->has('schema.layout')
                ->has('schema.fields.0', fn (Assert $field) => $field
                    ->has('uuid')
                    ->has('key')
                    ->has('label')
                    ->has('type')
                    ->has('required')
                    ->has('filterable')
                    ->has('layout_slot')
                    ->has('settings')
                    ->etc()
                )
                ->has('states')
            );
    }

    public function test_the_fixtures_cover_every_core_type_and_some_busy_fields()
    {
        $props = $this->actingAs(User::factory()->create())
            ->get(route('admin.models.builder'))
            ->inertiaProps();

        $typeKeys = array_column($props['fieldTypes'], 'key');
        $fieldTypes = array_values(array_unique(array_column($props['schema']['fields'], 'type')));
        $slotIds = collect($props['schema']['layout'])->pluck('slots.*.id')->flatten()->all();

        $this->assertEqualsCanonicalizing(self::CORE_TYPES, $typeKeys);
        $this->assertEqualsCanonicalizing(self::CORE_TYPES, $fieldTypes);
        $this->assertNotEmpty($props['schema']['layout']);

        foreach ($props['schema']['fields'] as $field) {
            $this->assertTrue($field['layout_slot'] === null || in_array($field['layout_slot'], $slotIds, true));
        }

        $uuids = array_column($props['schema']['fields'], 'uuid');
        $this->assertSame($uuids, array_unique($uuids));

        foreach ($props['states'] as $uuid => $state) {
            $this->assertContains($uuid, $uuids);
            $this->assertContains($state, ['ready', 'indexing', 'renaming', 'retyping', 'deleting', 'waiting']);
        }

        $this->assertNotEmpty(array_diff($props['states'], ['ready']));
    }
}

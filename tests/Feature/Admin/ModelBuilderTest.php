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

    public function test_guests_cannot_preview_or_save()
    {
        $this->post(route('admin.models.builder.preview'), ['schema' => []])
            ->assertRedirect(route('login'));

        $this->post(route('admin.models.builder.save'), ['schema' => []])
            ->assertRedirect(route('login'));
    }

    public function test_preview_and_save_require_a_schema()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.models.builder.preview'), [])
            ->assertSessionHasErrors('schema');

        $this->actingAs($user)
            ->post(route('admin.models.builder.save'), [])
            ->assertSessionHasErrors('schema');
    }

    public function test_preview_diffs_the_posted_schema_against_the_fixture()
    {
        $schema = $this->fixtureSchema();

        // Rename "title" -> "headline".
        $schema['fields'][0]['key'] = 'headline';

        // Retype "summary" from textarea to text, and promote it to filterable.
        $schema['fields'][1]['type'] = 'text';
        $schema['fields'][1]['filterable'] = true;

        // Demote "reading_time" (filterable true -> false).
        foreach ($schema['fields'] as &$field) {
            if ($field['key'] === 'reading_time') {
                $field['filterable'] = false;
            }
        }
        unset($field);

        // Delete "embed".
        $schema['fields'] = array_values(array_filter(
            $schema['fields'],
            fn ($field) => $field['key'] !== 'embed',
        ));

        // Add a new field.
        $schema['fields'][] = [
            'uuid' => 'new-field-uuid',
            'key' => 'excerpt',
            'label' => 'Excerpt',
            'type' => 'text',
            'required' => false,
            'filterable' => false,
            'layout_slot' => null,
            'settings' => [],
        ];

        // Change model metadata.
        $schema['model']['label'] = 'News Article';

        $props = $this->actingAs(User::factory()->create())
            ->post(route('admin.models.builder.preview'), ['schema' => $schema])
            ->assertOk()
            ->inertiaProps();

        $operations = collect($props['preview']['operations']);
        $this->assertEmpty($props['preview']['errors']);

        $this->assertTrue($operations->contains(
            fn ($op) => $op['op'] === 'rename' && $op['field_uuid'] === '0f4c7a52-8f3e-4b8e-9a51-3c2d1e6f7a01'
        ));
        $this->assertTrue($operations->contains(
            fn ($op) => $op['op'] === 'retype' && $op['field_uuid'] === '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c02'
        ));
        $this->assertTrue($operations->contains(
            fn ($op) => $op['op'] === 'promote' && $op['field_uuid'] === '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c02'
        ));
        $this->assertTrue($operations->contains(fn ($op) => $op['op'] === 'demote'));
        $this->assertTrue($operations->contains(
            fn ($op) => $op['op'] === 'delete' && $op['summary'] === 'Delete "embed"'
        ));
        $this->assertTrue($operations->contains(
            fn ($op) => $op['op'] === 'add' && $op['field_uuid'] === 'new-field-uuid'
        ));
        $this->assertTrue($operations->contains(fn ($op) => $op['op'] === 'metadata'));

        $destructive = $operations->where('destructive', true)->pluck('op');
        $this->assertTrue($destructive->contains('retype'));
        $this->assertTrue($destructive->contains('delete'));
        $this->assertFalse($destructive->contains('add'));
        $this->assertFalse($destructive->contains('promote'));
    }

    public function test_preview_reports_key_and_label_problems_as_errors()
    {
        $schema = $this->fixtureSchema();

        $schema['fields'][0]['label'] = '';
        $schema['fields'][1]['key'] = $schema['fields'][2]['key'];
        $schema['fields'][3]['key'] = '1bad';

        $props = $this->actingAs(User::factory()->create())
            ->post(route('admin.models.builder.preview'), ['schema' => $schema])
            ->assertOk()
            ->inertiaProps();

        $messages = collect($props['preview']['errors'])->pluck('message');

        $this->assertTrue($messages->contains('Give this field a label.'));
        $this->assertTrue($messages->contains('Another field already uses this key.'));
        $this->assertTrue($messages->contains(
            'Use letters, numbers and underscores, starting with a letter.'
        ));
    }

    public function test_save_accepts_the_schema_but_does_not_persist_it()
    {
        $user = User::factory()->create();
        $schema = $this->fixtureSchema();
        $schema['model']['label'] = 'Changed Label';

        $this->actingAs($user)
            ->post(route('admin.models.builder.save'), ['schema' => $schema])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('saved', true));

        $this->actingAs($user)
            ->get(route('admin.models.builder'))
            ->assertInertia(
                fn (Assert $page) => $page->where('schema.model.label', 'Article')
            );
    }

    /** @return array<string, mixed> */
    private function fixtureSchema(): array
    {
        return json_decode(
            file_get_contents(resource_path('fixtures/builder/schema.json')),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}

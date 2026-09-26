<?php

namespace Tests\Unit\Schema;

use App\Schema\FieldSpec;
use App\Schema\FieldTypes\FieldTypeRegistry;
use App\Schema\InvalidSchemaException;
use App\Schema\ModelSchema;
use App\Schema\OptionsSource;
use Tests\TestCase;

/**
 * The builder UI is written against fixed shapes. These tests check the
 * JSON the server sends, after encoding, so an empty object that would
 * encode as [] is caught too.
 */
class BuilderContractTest extends TestCase
{
    public function test_field_type_descriptors_have_the_agreed_shape()
    {
        $descriptors = $this->encoded(app(FieldTypeRegistry::class)->toArray());

        $this->assertIsList($descriptors);

        foreach ($descriptors as $type) {
            $this->assertKeys(['key', 'label', 'icon', 'category', 'settings', 'storage'], [], $type);
            $this->assertIsString($type['key']);
            $this->assertIsString($type['label']);
            $this->assertIsString($type['icon']);
            $this->assertContains($type['category'], ['basic', 'choice', 'number', 'date', 'rich', 'media', 'advanced']);

            $this->assertKeys(['declaredType', 'canFilter'], [], $type['storage']);
            $this->assertContains($type['storage']['declaredType'], ['string', 'int', 'numeric', 'datetime']);
            $this->assertIsBool($type['storage']['canFilter']);

            $this->assertIsList($type['settings']);
            foreach ($type['settings'] as $setting) {
                $this->assertKeys(['key', 'label', 'input', 'default'], ['help', 'choices'], $setting);
                $this->assertContains($setting['input'], ['text', 'number', 'boolean', 'select', 'options_source', 'string_list']);

                if (isset($setting['help'])) {
                    $this->assertIsString($setting['help']);
                }

                if (isset($setting['choices'])) {
                    $this->assertIsList($setting['choices']);
                    foreach ($setting['choices'] as $choice) {
                        $this->assertKeys(['value', 'label'], [], $choice);
                        $this->assertIsString($choice['value']);
                    }
                }
            }
        }
    }

    public function test_core_types_have_the_agreed_settings()
    {
        $expected = [
            'text' => ['placeholder' => 'text', 'default' => 'text', 'max_length' => 'number'],
            'email' => ['placeholder' => 'text', 'default' => 'text'],
            'url' => ['placeholder' => 'text', 'default' => 'text'],
            'color' => ['default' => 'text'],
            'hidden' => ['default' => 'text'],
            'radio' => ['options' => 'options_source'],
            'select' => ['options' => 'options_source', 'multiple' => 'boolean'],
            'number' => ['whole' => 'boolean', 'min' => 'number', 'max' => 'number', 'step' => 'number'],
            'range' => ['min' => 'number', 'max' => 'number', 'step' => 'number'],
            'checkbox' => ['default' => 'boolean'],
            'datetime' => ['date_only' => 'boolean'],
            'textarea' => ['rows' => 'number'],
            'rich_text' => [],
            'code' => ['language' => 'select'],
            'checkboxes' => ['options' => 'options_source'],
            'file' => ['multiple' => 'boolean', 'accept' => 'string_list'],
        ];

        $actual = [];
        foreach ($this->encoded(app(FieldTypeRegistry::class)->toArray()) as $type) {
            $actual[$type['key']] = array_column($type['settings'], 'input', 'key');
        }

        $this->assertEquals($expected, $actual);
    }

    public function test_an_options_source_default_is_an_empty_static_list()
    {
        $select = collect($this->encoded(app(FieldTypeRegistry::class)->toArray()))->firstWhere('key', 'select');

        $this->assertSame(['kind' => 'static', 'options' => []], $select['settings'][0]['default']);
    }

    public function test_model_schema_json_has_the_agreed_shape()
    {
        $json = $this->encoded($this->schema()->toJson());

        $this->assertKeys(['version', 'model', 'layout', 'fields'], [], $json);
        $this->assertSame(1, $json['version']);
        $this->assertKeys(['slug', 'label'], ['icon', 'group'], $json['model']);

        foreach ($json['layout'] as $block) {
            $this->assertKeys(['id', 'kind', 'slots'], ['label'], $block);
            $this->assertContains($block['kind'], ['section', 'tabs', 'columns']);
            foreach ($block['slots'] as $slot) {
                $this->assertKeys(['id'], ['label'], $slot);
            }
        }

        foreach ($json['fields'] as $field) {
            $this->assertKeys(['uuid', 'key', 'label', 'type', 'required', 'filterable', 'shown_in_list', 'layout_slot', 'settings'], ['helper'], $field);
            $this->assertIsBool($field['required']);
            $this->assertIsBool($field['filterable']);
            $this->assertIsBool($field['shown_in_list']);
            $this->assertTrue($field['layout_slot'] === null || is_string($field['layout_slot']));
        }

        $this->assertSame('main', $json['fields'][0]['layout_slot']);
        $this->assertNull($json['fields'][1]['layout_slot']);
        $this->assertSame('Shown under the field.', $json['fields'][0]['helper']);
        $this->assertArrayNotHasKey('helper', $json['fields'][1]);
    }

    public function test_empty_settings_encode_as_an_object()
    {
        $encoded = json_encode($this->schema()->toJson());

        $this->assertIsString($encoded);
        $this->assertStringContainsString('"type":"rich_text","required":false,"filterable":false,"shown_in_list":false,"layout_slot":null,"settings":{}', $encoded);
    }

    public function test_the_json_round_trips()
    {
        $schema = $this->schema();

        $this->assertEquals($schema, ModelSchema::fromJson($schema->toJson()));
        $this->assertEquals($schema, ModelSchema::fromJson((string) json_encode($schema->toJson())));
    }

    public function test_optional_model_keys_are_left_out_when_empty()
    {
        $json = (new ModelSchema('pages', 'Pages'))->toJson();

        $this->assertSame(['slug' => 'pages', 'label' => 'Pages'], $json['model']);
        $this->assertSame([], $json['layout']);
        $this->assertSame([], $json['fields']);
    }

    public function test_a_field_without_a_uuid_gets_one()
    {
        $schema = ModelSchema::fromJson([
            'version' => 1,
            'model' => ['slug' => 'pages', 'label' => 'Pages'],
            'fields' => [['key' => 'title', 'label' => 'Title', 'type' => 'text']],
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $schema->fields[0]->uuid);
        $this->assertFalse($schema->fields[0]->filterable);
    }

    public function test_malformed_json_is_refused_with_its_path()
    {
        $cases = [
            'not json' => '{',
            'wrong version' => ['version' => 2, 'model' => ['slug' => 'a', 'label' => 'A']],
            'no model' => ['version' => 1],
            'fields not a list' => ['version' => 1, 'model' => ['slug' => 'a', 'label' => 'A'], 'fields' => ['a' => []]],
            'settings a list' => ['version' => 1, 'model' => ['slug' => 'a', 'label' => 'A'], 'fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text', 'settings' => [1]]]],
            'bad layout kind' => ['version' => 1, 'model' => ['slug' => 'a', 'label' => 'A'], 'layout' => [['id' => 'x', 'kind' => 'grid', 'slots' => []]]],
            'bad uuid' => ['version' => 1, 'model' => ['slug' => 'a', 'label' => 'A'], 'fields' => [['uuid' => 'nope', 'key' => 'a', 'label' => 'A', 'type' => 'text']]],
        ];

        foreach ($cases as $name => $json) {
            try {
                ModelSchema::fromJson($json);
                $this->fail("[{$name}] should be refused.");
            } catch (InvalidSchemaException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_fresh_uuids_keep_everything_else()
    {
        $schema = $this->schema();
        $copy = $schema->withFreshUuids();

        $this->assertNotSame($schema->fields[0]->uuid, $copy->fields[0]->uuid);
        $this->assertSame($schema->fields[0]->key, $copy->fields[0]->key);
        $this->assertSame($schema->fields[0]->settings, $copy->fields[0]->settings);
    }

    private function schema(): ModelSchema
    {
        return new ModelSchema(
            slug: 'articles',
            label: 'Articles',
            icon: 'newspaper',
            group: 'Content',
            layout: [
                ['id' => 'top', 'kind' => 'section', 'label' => 'Main', 'slots' => [['id' => 'main']]],
                ['id' => 'extra', 'kind' => 'tabs', 'slots' => [['id' => 'seo', 'label' => 'SEO'], ['id' => 'media', 'label' => 'Media']]],
            ],
            fields: [
                new FieldSpec('0b4a3d4e-7f1b-4c47-9f6e-1c2d3e4f5a6b', 'title', 'Title', 'text', ['max_length' => 120], helper: 'Shown under the field.', required: true, filterable: true, layoutSlot: 'main'),
                new FieldSpec('1c5b4e5f-8a2c-4d58-8a7f-2d3e4f5a6b7c', 'body', 'Body', 'rich_text'),
                new FieldSpec('2d6c5f6a-9b3d-4e69-9b8a-3e4f5a6b7c8d', 'tags', 'Tags', 'checkboxes', ['options' => OptionsSource::static([['value' => 'news', 'label' => 'News']])], layoutSlot: 'seo'),
            ],
        );
    }

    /**
     * What the builder receives: the value after a JSON round trip.
     *
     * @return array<array-key, mixed>
     */
    private function encoded(mixed $value): array
    {
        return json_decode((string) json_encode($value), true);
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    private function assertKeys(array $required, array $optional, mixed $object): void
    {
        $this->assertIsArray($object);
        $keys = array_keys($object);

        $this->assertEmpty(array_diff($required, $keys), 'Missing: '.implode(', ', array_diff($required, $keys)));
        $this->assertEmpty(array_diff($keys, $required, $optional), 'Unexpected: '.implode(', ', array_diff($keys, $required, $optional)));
    }
}

<?php

namespace Tests\Unit\Schema;

use App\Schema\DiffResult;
use App\Schema\FieldSpec;
use App\Schema\FieldTypes\FieldTypeRegistry;
use App\Schema\ModelSchema;
use App\Schema\Operations\AddField;
use App\Schema\Operations\MetadataOnly;
use App\Schema\Operations\Operation;
use App\Schema\Operations\RenameField;
use App\Schema\Operations\RetypeField;
use App\Schema\SchemaDiff;
use Tests\TestCase;

class SchemaDiffTest extends TestCase
{
    private const TITLE = '00000000-0000-4000-8000-000000000001';

    private const BODY = '00000000-0000-4000-8000-000000000002';

    private const PRICE = '00000000-0000-4000-8000-000000000003';

    private const NEW = '00000000-0000-4000-8000-000000000009';

    public function test_no_change_means_no_operations()
    {
        $result = $this->diff($this->current(), $this->current());

        $this->assertSame([], $result->operations);
        $this->assertSame([], $result->errors);
    }

    public function test_reordering_is_metadata_only()
    {
        $current = $this->current();
        $desired = $current->withFields(array_reverse($current->fields));

        $result = $this->diff($current, $desired);

        $this->assertSame(['metadata'], $this->kinds($result));
        $metadata = $result->operations[0];
        $this->assertInstanceOf(MetadataOnly::class, $metadata);
        $this->assertSame(0, $metadata->fields[self::PRICE]['position']);
        $this->assertSame(2, $metadata->fields[self::TITLE]['position']);
        $this->assertArrayNotHasKey(self::BODY, $metadata->fields, 'The middle field did not move.');
    }

    public function test_a_label_change_is_metadata_only()
    {
        $result = $this->diff($this->current(), $this->change(self::TITLE, ['label' => 'Headline']));

        $this->assertSame(['metadata'], $this->kinds($result));
        $this->assertFalse($result->operations[0]->affectsExistingData());
    }

    public function test_a_type_change_with_the_same_storage_is_metadata_only()
    {
        $result = $this->diff($this->current(), $this->change(self::TITLE, ['type' => 'email', 'settings' => []]));

        $this->assertSame(['metadata'], $this->kinds($result));
        $this->assertSame('email', $result->operations[0]->payload()['fields'][self::TITLE]['type']);
    }

    public function test_the_model_slug_and_layout_are_metadata()
    {
        $current = $this->current();
        $desired = new ModelSchema('posts', 'Posts', 'newspaper', 'Blog', [['id' => 's', 'kind' => 'section', 'slots' => [['id' => 'main']]]], $current->fields);

        $result = $this->diff($current, $desired);

        $this->assertSame(['metadata'], $this->kinds($result));
        $metadata = $result->operations[0];
        $this->assertInstanceOf(MetadataOnly::class, $metadata);
        $this->assertTrue($metadata->renamesModel());
        $this->assertSame([], $metadata->fields);
    }

    public function test_a_new_key_on_the_same_uuid_is_a_rename_not_a_delete_and_an_add()
    {
        $result = $this->diff($this->current(), $this->change(self::TITLE, ['key' => 'headline']));

        $this->assertSame(['rename'], $this->kinds($result));
        $rename = $result->operations[0];
        $this->assertInstanceOf(RenameField::class, $rename);
        $this->assertSame(['title', 'headline', 101], [$rename->from, $rename->to, $rename->stardustFieldId]);
        $this->assertTrue($rename->affectsExistingData());
        $this->assertFalse($rename->destructive());
    }

    public function test_a_change_of_storage_type_is_a_retype()
    {
        $result = $this->diff($this->current(), $this->change(self::PRICE, ['settings' => ['whole' => true]]));

        $this->assertSame(['retype'], $this->kinds($result));
        $retype = $result->operations[0];
        $this->assertInstanceOf(RetypeField::class, $retype);
        $this->assertSame(['numeric', 'int'], [$retype->fromDeclared, $retype->toDeclared]);
        $this->assertTrue($retype->settings['whole']);
    }

    public function test_filterability_changes_are_promote_and_demote()
    {
        $desired = $this->change(self::TITLE, ['filterable' => false], $this->change(self::PRICE, ['filterable' => true]));

        $this->assertSame(['demote', 'promote'], $this->kinds($this->diff($this->current(), $desired)));
    }

    public function test_removing_a_field_is_a_destructive_delete()
    {
        $current = $this->current();
        $result = $this->diff($current, $current->withFields([$current->fields[0], $current->fields[2]]));

        $this->assertContains('delete', $this->kinds($result));
        $delete = collect($result->operations)->first(fn (Operation $op) => $op->kind() === 'delete');
        $this->assertTrue($delete?->destructive());
        $this->assertSame(self::BODY, $delete?->fieldUuid());
    }

    public function test_a_new_field_is_an_add()
    {
        $current = $this->current();
        $desired = $current->withFields([...$current->fields, new FieldSpec(self::NEW, 'summary', 'Summary', 'textarea')]);

        $result = $this->diff($current, $desired);

        $this->assertSame(['add'], $this->kinds($result));
        $add = $result->operations[0];
        $this->assertInstanceOf(AddField::class, $add);
        $this->assertSame('string', $add->declaredType);
        $this->assertSame(['rows' => 4], $add->field['settings']);
        $this->assertSame(3, $add->field['position']);
    }

    public function test_operations_run_in_a_fixed_order()
    {
        $current = $this->current();
        $desired = $current->withFields([
            new FieldSpec(self::NEW, 'summary', 'Summary', 'text'),
            $current->fields[2]->with(['settings' => ['whole' => true]]),
            $current->fields[0]->with(['key' => 'headline', 'label' => 'Headline']),
        ]);
        $desired = new ModelSchema('articles', 'Stories', fields: $desired->fields);

        $this->assertSame(['metadata', 'delete', 'rename', 'retype', 'add'], $this->kinds($this->diff($current, $desired)));
    }

    public function test_number_and_date_can_not_swap()
    {
        $this->assertRefused($this->diff($this->current(), $this->change(self::PRICE, ['type' => 'datetime', 'settings' => []])), self::PRICE, 'number and a date');

        $current = $this->current()->withFields([new FieldSpec(self::PRICE, 'published', 'Published', 'datetime')]);
        $desired = $current->withFields([new FieldSpec(self::PRICE, 'published', 'Published', 'number')]);
        $this->assertRefused($this->diff($current, $desired), self::PRICE, 'number and a date');
    }

    public function test_text_can_become_a_date()
    {
        $this->assertSame(['retype'], $this->kinds($this->diff($this->current(), $this->change(self::TITLE, ['type' => 'datetime', 'settings' => []]))));
    }

    public function test_a_rename_and_a_retype_of_one_field_need_two_saves()
    {
        $desired = $this->change(self::PRICE, ['key' => 'cost', 'settings' => ['whole' => true]]);

        $this->assertRefused($this->diff($this->current(), $desired), self::PRICE, 'first');
    }

    public function test_a_rename_and_a_filter_change_of_one_field_need_two_saves()
    {
        $this->assertRefused($this->diff($this->current(), $this->change(self::PRICE, ['key' => 'cost', 'filterable' => true])), self::PRICE, 'first');
    }

    public function test_a_retype_while_turning_filtering_off_needs_two_saves()
    {
        $desired = $this->change(self::TITLE, ['type' => 'number', 'settings' => [], 'filterable' => false]);

        $this->assertRefused($this->diff($this->current(), $desired), self::TITLE, 'two separate saves');
    }

    public function test_a_retype_while_turning_filtering_on_is_allowed()
    {
        $desired = $this->change(self::PRICE, ['settings' => ['whole' => true], 'filterable' => true]);

        $this->assertSame(['retype', 'promote'], $this->kinds($this->diff($this->current(), $desired)));
    }

    public function test_a_busy_field_refuses_storage_changes_but_takes_metadata()
    {
        foreach (['indexing', 'renaming', 'retyping', 'deleting', 'waiting'] as $state) {
            $states = [self::TITLE => $state];

            $this->assertRefused($this->diff($this->current(), $this->change(self::TITLE, ['key' => 'headline']), $states), self::TITLE, 'finishes');
            $this->assertRefused($this->diff($this->current(), $this->change(self::TITLE, ['filterable' => false]), $states), self::TITLE, 'finishes');
            $this->assertSame(['metadata'], $this->kinds($this->diff($this->current(), $this->change(self::TITLE, ['label' => 'Headline']), $states)));
        }

        $current = $this->current();
        $this->assertRefused($this->diff($current, $current->withFields([$current->fields[1], $current->fields[2]]), [self::TITLE => 'renaming']), self::TITLE, 'deleted yet');
    }

    public function test_a_key_held_by_a_field_being_deleted_can_not_be_reused()
    {
        $current = $this->current();
        $desired = $current->withFields([...$current->fields, new FieldSpec(self::NEW, 'old_body', 'Old body', 'text')]);

        $this->assertRefused($this->diff($current, $desired, heldKeys: ['old_body']), self::NEW, 'still being cleared');
        $this->assertRefused($this->diff($current, $this->change(self::TITLE, ['key' => 'old_body']), heldKeys: ['old_body']), self::TITLE, 'still being cleared');
    }

    public function test_a_key_deleted_in_the_same_save_can_not_be_reused_yet()
    {
        $current = $this->current();
        $desired = $current->withFields([$current->fields[0], $current->fields[2], new FieldSpec(self::NEW, 'body', 'Body', 'rich_text')]);

        $this->assertRefused($this->diff($current, $desired), self::NEW, 'still belongs to another field');
    }

    public function test_keys_can_not_be_swapped_in_one_save()
    {
        $desired = $this->change(self::TITLE, ['key' => 'body'], $this->change(self::BODY, ['key' => 'title']));

        $result = $this->diff($this->current(), $desired);

        $this->assertRefused($result, self::TITLE, 'still belongs to another field');
        $this->assertRefused($result, self::BODY, 'still belongs to another field');
        $this->assertSame([], $result->operations);
    }

    public function test_keys_are_checked()
    {
        $cases = [
            '_status' => 'reserved',
            'Title' => 'lower-case',
            '1st' => 'start with a letter',
            'with space' => 'lower-case',
            str_repeat('a', 129) => '128',
            '' => 'needs a key',
        ];

        foreach ($cases as $key => $message) {
            $this->assertRefused($this->diff($this->current(), $this->change(self::TITLE, ['key' => $key])), self::TITLE, $message);
        }
    }

    public function test_keys_are_unique_within_the_model()
    {
        $this->assertRefused($this->diff($this->current(), $this->change(self::BODY, ['key' => 'title'])), self::BODY, 'already uses the key');
    }

    public function test_a_json_only_type_can_not_be_filterable()
    {
        $this->assertRefused($this->diff($this->current(), $this->change(self::BODY, ['filterable' => true])), self::BODY, "can't be filterable");

        $multiple = $this->change(self::TITLE, ['type' => 'select', 'settings' => ['multiple' => true]]);
        $this->assertRefused($this->diff($this->current(), $multiple), self::TITLE, "can't be filterable");
    }

    public function test_unknown_and_reserved_types_are_refused()
    {
        $this->assertRefused($this->diff($this->current(), $this->change(self::BODY, ['type' => 'hologram'])), self::BODY, 'unknown field type');
        $this->assertRefused($this->diff($this->current(), $this->change(self::BODY, ['type' => 'relation'])), self::BODY, 'not available yet');
    }

    public function test_settings_are_checked()
    {
        $this->assertRefused($this->diff($this->current(), $this->change(self::PRICE, ['settings' => ['min' => 10, 'max' => 1]])), self::PRICE, 'minimum');
    }

    public function test_a_field_must_sit_in_an_existing_layout_slot()
    {
        $this->assertRefused($this->diff($this->current(), $this->change(self::BODY, ['layoutSlot' => 'sidebar'])), self::BODY, 'layout slot');
    }

    public function test_the_model_slug_is_checked()
    {
        $current = $this->current();
        $result = $this->diff($current, $current->withSlug('Not a slug'));

        $this->assertSame(null, $result->errors[0]['field_uuid']);
        $this->assertStringContainsString('slug', $result->errors[0]['message']);
    }

    public function test_a_duplicate_uuid_is_refused()
    {
        $current = $this->current();
        $desired = $current->withFields([...$current->fields, $current->fields[0]->with(['key' => 'again'])]);

        $this->assertRefused($this->diff($current, $desired), self::TITLE, 'twice');
    }

    public function test_the_preview_has_the_agreed_shape()
    {
        $preview = $this->diff($this->current(), $this->change(self::TITLE, ['key' => 'headline', 'label' => 'Headline']))->toPreview();

        $this->assertSame(['operations', 'errors'], array_keys($preview));
        foreach ($preview['operations'] as $operation) {
            $this->assertSame(['op', 'field_uuid', 'summary', 'affects_existing_data', 'destructive'], array_keys($operation));
        }

        $refused = $this->diff($this->current(), $this->change(self::TITLE, ['key' => '_x']))->toPreview();
        $this->assertSame(['field_uuid', 'message'], array_keys($refused['errors'][0]));
    }

    public function test_operations_round_trip_through_their_payload()
    {
        $current = $this->current();
        $desired = $current->withFields([
            new FieldSpec(self::NEW, 'summary', 'Summary', 'text'),
            $current->fields[2]->with(['settings' => ['whole' => true], 'filterable' => true]),
            $current->fields[0]->with(['key' => 'headline']),
        ]);

        foreach ($this->diff($current, new ModelSchema('stories', 'Stories', fields: $desired->fields))->operations as $operation) {
            $this->assertEquals($operation, Operation::restore($operation->kind(), json_decode((string) json_encode($operation->payload()), true)));
        }
    }

    private function current(): ModelSchema
    {
        return new ModelSchema('articles', 'Articles', fields: [
            new FieldSpec(self::TITLE, 'title', 'Title', 'text', ['placeholder' => null, 'default' => null, 'max_length' => null], filterable: true),
            new FieldSpec(self::BODY, 'body', 'Body', 'rich_text'),
            new FieldSpec(self::PRICE, 'price', 'Price', 'number', ['whole' => false, 'min' => null, 'max' => null, 'step' => null]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function change(string $uuid, array $changes, ?ModelSchema $schema = null): ModelSchema
    {
        $schema ??= $this->current();

        return $schema->withFields(array_map(
            fn (FieldSpec $field) => $field->uuid === $uuid ? $field->with($changes) : $field,
            $schema->fields,
        ));
    }

    /**
     * @param  array<string, string>  $states
     * @param  list<string>  $heldKeys
     */
    private function diff(ModelSchema $current, ModelSchema $desired, array $states = [], array $heldKeys = []): DiffResult
    {
        return (new SchemaDiff(app(FieldTypeRegistry::class)))->diff(
            $current,
            $desired,
            [self::TITLE => 101, self::BODY => 102, self::PRICE => 103],
            $states,
            $heldKeys,
        );
    }

    /**
     * @return list<string>
     */
    private function kinds(DiffResult $result): array
    {
        $this->assertSame([], $result->errors, 'Unexpected errors: '.json_encode($result->errors));

        return array_map(fn (Operation $operation) => $operation->kind(), $result->operations);
    }

    private function assertRefused(DiffResult $result, string $uuid, string $message): void
    {
        $matching = array_filter(
            $result->errors,
            fn ($error) => $error['field_uuid'] === $uuid && str_contains($error['message'], $message),
        );

        $this->assertNotEmpty($matching, "Expected an error for {$uuid} mentioning \"{$message}\"; got ".json_encode($result->errors));
    }
}

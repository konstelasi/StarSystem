<?php

namespace Tests\Unit\Schema;

use App\Hooks\Hook;
use App\Schema\FieldTypes\Core\RelationType;
use App\Schema\FieldTypes\Core\TextType;
use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\FieldTypeRegistry;
use App\Schema\OptionsSource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FieldTypesTest extends TestCase
{
    public function test_the_core_types_are_registered_in_palette_order()
    {
        $this->assertSame(
            ['text', 'textarea', 'rich_text', 'email', 'url', 'number', 'range', 'select', 'radio', 'checkbox', 'checkboxes', 'datetime', 'file', 'color', 'code', 'hidden'],
            array_keys($this->registry()->all()),
        );
    }

    public function test_repeater_is_reserved()
    {
        foreach (FieldTypeRegistry::RESERVED as $key) {
            $type = $this->fakeType($key);

            try {
                $this->registry()->register($type);
                $this->fail("[{$key}] should be reserved.");
            } catch (InvalidArgumentException) {
                $this->assertFalse($this->registry()->has($key));
            }
        }
    }

    public function test_a_module_can_register_a_type_once()
    {
        $registry = $this->registry();
        $registry->register($this->fakeType('rating'));

        $this->assertTrue(app(FieldTypeRegistry::class)->has('rating'), 'The registry is a singleton.');

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->fakeType('rating'));
    }

    public function test_a_module_can_add_a_type_through_the_schema_field_types_filter()
    {
        Hook::addFilter('schema.field_types', function (array $types) {
            $types['rating'] = $this->fakeType('rating');

            return $types;
        });

        $registry = $this->registry();

        $this->assertTrue($registry->has('rating'));
        $this->assertSame('rating', $registry->get('rating')->key());
    }

    public function test_a_type_from_the_filter_is_dropped_instead_of_crashing_on_a_reserved_or_taken_key()
    {
        Hook::addFilter('schema.field_types', function (array $types) {
            $types['repeater'] = $this->fakeType('repeater');
            $types['text'] = $this->fakeType('text');

            return $types;
        });

        $registry = $this->registry();

        $this->assertFalse($registry->has('repeater'));
        $this->assertInstanceOf(TextType::class, $registry->get('text'));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('storageCases')]
    public function test_storage(string $type, array $settings, string $declaredType, bool $canFilter)
    {
        $fieldType = $this->registry()->get($type);

        $this->assertSame(
            ['declaredType' => $declaredType, 'canFilter' => $canFilter],
            $fieldType->storage($fieldType->resolveSettings($settings)),
        );
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string, bool}>
     */
    public static function storageCases(): array
    {
        return [
            'text' => ['text', [], 'string', true],
            'email' => ['email', [], 'string', true],
            'url' => ['url', [], 'string', true],
            'color' => ['color', [], 'string', true],
            'hidden' => ['hidden', [], 'string', true],
            'radio' => ['radio', [], 'string', true],
            'single select' => ['select', [], 'string', true],
            'multiple select is JSON-only' => ['select', ['multiple' => true], 'string', false],
            'decimal number' => ['number', [], 'numeric', true],
            'whole number' => ['number', ['whole' => true], 'int', true],
            'range' => ['range', [], 'numeric', true],
            'checkbox is an int' => ['checkbox', [], 'int', true],
            'datetime' => ['datetime', [], 'datetime', true],
            'date only is still a datetime' => ['datetime', ['date_only' => true], 'datetime', true],
            'textarea is JSON-only' => ['textarea', [], 'string', false],
            'rich text is JSON-only' => ['rich_text', [], 'string', false],
            'code is JSON-only' => ['code', [], 'string', false],
            'checkboxes are JSON-only' => ['checkboxes', [], 'string', false],
            'file is JSON-only' => ['file', [], 'string', false],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('ruleCases')]
    public function test_rules(string $type, array $settings, bool $required, mixed $value, bool $passes)
    {
        $fieldType = $this->registry()->get($type);
        $rules = [];
        foreach ($fieldType->rules($fieldType->resolveSettings($settings), $required) as $suffix => $list) {
            $rules['value'.$suffix] = $list;
        }

        $validator = validator(['value' => $value], $rules);

        $this->assertSame($passes, $validator->passes(), json_encode($validator->errors()->all()) ?: '');
    }

    /**
     * @return array<string, array{string, array<string, mixed>, bool, mixed, bool}>
     */
    public static function ruleCases(): array
    {
        $colours = ['options' => OptionsSource::static([['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']])];

        return [
            'optional text may be empty' => ['text', [], false, null, true],
            'required text may not' => ['text', [], true, null, false],
            'text respects max length' => ['text', ['max_length' => 3], false, 'abcd', false],
            'text is capped at a slot' => ['text', [], false, str_repeat('a', 4097), false],
            'email' => ['email', [], false, 'a@example.com', true],
            'not an email' => ['email', [], false, 'nope', false],
            'url' => ['url', [], false, 'https://example.com', true],
            'not a url' => ['url', [], false, 'example', false],
            'colour' => ['color', [], false, '#aabbcc', true],
            'not a colour' => ['color', [], false, 'red', false],
            'radio in options' => ['radio', $colours, false, 'red', true],
            'radio outside options' => ['radio', $colours, false, 'green', false],
            'radio from a hook is not checked here' => ['radio', ['options' => OptionsSource::hook('menus')], false, 'anything', true],
            'select one' => ['select', $colours, false, 'blue', true],
            'select several' => ['select', [...$colours, 'multiple' => true], false, ['red', 'blue'], true],
            'select several outside options' => ['select', [...$colours, 'multiple' => true], false, ['red', 'green'], false],
            'number' => ['number', [], false, '1.5', true],
            'whole number refuses decimals' => ['number', ['whole' => true], false, '1.5', false],
            'number below min' => ['number', ['min' => 5], false, 4, false],
            'number above max' => ['number', ['max' => 5], false, 6, false],
            'range inside' => ['range', ['min' => 1, 'max' => 10], false, 10, true],
            'range outside' => ['range', ['min' => 1, 'max' => 10], false, 11, false],
            'checkbox' => ['checkbox', [], false, false, true],
            'required checkbox must be ticked' => ['checkbox', [], true, false, false],
            'datetime' => ['datetime', [], false, '2026-09-24T10:00', true],
            'not a datetime' => ['datetime', [], false, 'soon', false],
            'date only' => ['datetime', ['date_only' => true], false, '2026-09-24', true],
            'date only refuses a time' => ['datetime', ['date_only' => true], false, '2026-09-24 10:00', false],
            'textarea' => ['textarea', [], false, "Line one\nLine two", true],
            'rich text' => ['rich_text', [], true, '<p>Hi</p>', true],
            'code' => ['code', [], false, '<?php echo 1;', true],
            'checkboxes' => ['checkboxes', $colours, false, ['red'], true],
            'checkboxes outside options' => ['checkboxes', $colours, false, ['green'], false],
            'checkboxes need a list' => ['checkboxes', $colours, false, 'red', false],
            // A picked file must exist for the current site, which needs a
            // database and a resolved site: see FileFieldValidationTest,
            // not this data provider.
            'several files need a list' => ['file', ['multiple' => true], false, 'uploads/a.pdf', false],
        ];
    }

    public function test_normalize_produces_what_stardust_stores()
    {
        $registry = $this->registry();

        $this->assertSame(1, $registry->get('checkbox')->normalize('on'));
        $this->assertSame(0, $registry->get('checkbox')->normalize(false));
        $this->assertSame(7, $registry->get('number')->normalize('7', ['whole' => true]));
        $this->assertSame(7.5, $registry->get('number')->normalize('7.5'));
        $this->assertNull($registry->get('number')->normalize(''));
        $this->assertSame('a@example.com', $registry->get('email')->normalize(' A@Example.com '));
        $this->assertSame('#aabbcc', $registry->get('color')->normalize('#AABBCC'));
        $this->assertSame('2026-09-24 03:00:00', $registry->get('datetime')->normalize('2026-09-24T10:00:00+07:00'));
        $this->assertSame('2026-09-24 00:00:00', $registry->get('datetime')->normalize('2026-09-24', ['date_only' => true]));
        $this->assertSame(['a', 'b'], $registry->get('checkboxes')->normalize(['a', 'b', 'a']));
        $this->assertNull($registry->get('checkboxes')->normalize([]));
        $this->assertSame(['x.pdf'], $registry->get('file')->normalize('x.pdf', ['multiple' => true]));
    }

    public function test_settings_are_checked()
    {
        $registry = $this->registry();
        $errors = fn (string $type, array $settings) => $registry->get($type)->settingErrors($registry->get($type)->resolveSettings($settings));

        $this->assertSame([], $errors('number', ['min' => 1, 'max' => 10, 'step' => 0.5]));
        $this->assertNotSame([], $errors('number', ['min' => 10, 'max' => 1]));
        $this->assertNotSame([], $errors('number', ['step' => 0]));
        $this->assertNotSame([], $errors('number', ['whole' => 'maybe']));
        $this->assertNotSame([], $errors('range', ['min' => 5, 'max' => 5]));
        $this->assertNotSame([], $errors('text', ['max_length' => 5000]));
        $this->assertNotSame([], $errors('code', ['language' => 'cobol']));
        $this->assertNotSame([], $errors('textarea', ['rows' => 0]));
        $this->assertNotSame([], $errors('file', ['accept' => '.pdf']));
        $this->assertSame([], $errors('file', ['accept' => ['.pdf', 'image/*']]));
    }

    public function test_options_sources_are_checked()
    {
        $registry = $this->registry();
        $errors = fn (mixed $options) => $registry->get('select')->settingErrors($registry->get('select')->resolveSettings(['options' => $options]));

        $this->assertSame([], $errors(OptionsSource::static([['value' => 'a', 'label' => 'A']])));
        $this->assertSame([], $errors(OptionsSource::model('authors', 'name')));
        $this->assertSame([], $errors(OptionsSource::hook('menus')));
        $this->assertNotSame([], $errors(OptionsSource::static([['value' => 'a', 'label' => 'A'], ['value' => 'a', 'label' => 'Again']])));
        $this->assertNotSame([], $errors(['kind' => 'model', 'model' => 'authors']));
        $this->assertNotSame([], $errors(['kind' => 'sql', 'query' => 'select 1']));
        $this->assertNotSame([], $errors('red,blue'));
    }

    public function test_resolve_settings_fills_defaults_and_drops_unknown_keys()
    {
        $this->assertSame(
            ['min' => 0, 'max' => 50, 'step' => 1],
            $this->registry()->get('range')->resolveSettings(['max' => 50, 'colour' => 'red']),
        );
    }

    public function test_a_relation_describes_itself_to_the_builder()
    {
        $descriptor = (new RelationType)->toArray();

        $this->assertSame(['relation', 'Relation', 'link-2', 'choice'], [$descriptor['key'], $descriptor['label'], $descriptor['icon'], $descriptor['category']]);
        $this->assertSame(['link' => 'model_link', 'multiple' => 'boolean'], array_column($descriptor['settings'], 'input', 'key'));
        $this->assertSame(['model' => null, 'label_field' => null], $descriptor['settings'][0]['default']);
        $this->assertSame(['declaredType' => 'int', 'canFilter' => true], $descriptor['storage']);
    }

    public function test_a_relation_stores_a_whole_number_and_only_a_single_link_can_be_filtered()
    {
        $type = new RelationType;

        $this->assertSame(['declaredType' => 'int', 'canFilter' => true], $type->storage($type->resolveSettings([])));
        $this->assertSame(['declaredType' => 'int', 'canFilter' => false], $type->storage($type->resolveSettings(['multiple' => true])));
    }

    #[DataProvider('relationNormalizeCases')]
    public function test_a_relation_is_normalized_to_ids(mixed $value, bool $multiple, mixed $expected)
    {
        $type = new RelationType;

        $this->assertSame($expected, $type->normalize($value, $type->resolveSettings(['multiple' => $multiple])));
    }

    /**
     * @return array<string, array{mixed, bool, mixed}>
     */
    public static function relationNormalizeCases(): array
    {
        return [
            'null' => [null, false, null],
            'empty text' => ['', false, null],
            'an id' => [12, false, 12],
            'an id as text' => ['12', false, 12],
            'not an id' => ['abc', false, null],
            'zero' => [0, false, null],
            'a list on a single link keeps the first id' => [[3, 7], false, 3],
            'nothing on a link to several' => [null, true, null],
            'an empty list' => [[], true, null],
            'a list' => [[3, 7], true, [3, 7]],
            'a list without repeats' => [['3', 7, 3], true, [3, 7]],
            'a lone id is a list of one' => [12, true, [12]],
        ];
    }

    public function test_relation_rules_have_the_agreed_shape()
    {
        $type = new RelationType;

        $this->assertSame(['' => ['nullable', 'integer', 'min:1']], $type->rules($type->resolveSettings([]), false));
        $this->assertSame(['' => ['required', 'integer', 'min:1']], $type->rules($type->resolveSettings([]), true));
        $this->assertSame(
            ['' => ['nullable', 'array', 'list', 'max:50'], '.*' => ['integer', 'min:1']],
            $type->rules($type->resolveSettings(['multiple' => true]), false),
        );
    }

    #[DataProvider('relationRuleCases')]
    public function test_relation_rules(bool $multiple, bool $required, mixed $value, bool $passes)
    {
        $type = new RelationType;
        $rules = [];
        foreach ($type->rules($type->resolveSettings(['multiple' => $multiple]), $required) as $suffix => $list) {
            $rules['value'.$suffix] = $list;
        }

        $validator = validator(['value' => $value], $rules);

        $this->assertSame($passes, $validator->passes(), json_encode($validator->errors()->all()) ?: '');
    }

    /**
     * @return array<string, array{bool, bool, mixed, bool}>
     */
    public static function relationRuleCases(): array
    {
        return [
            'an id' => [false, false, 5, true],
            'an id as text' => [false, false, '5', true],
            'none' => [false, false, null, true],
            'none when required' => [false, true, null, false],
            'zero' => [false, false, 0, false],
            'a word' => [false, false, 'five', false],
            'a list on a single link' => [false, false, [5], false],
            'ids' => [true, false, [3, 7], true],
            'no ids' => [true, false, null, true],
            'no ids when required' => [true, true, [], false],
            'a lone id on a link to several' => [true, false, 5, false],
            'a bad id in a list' => [true, false, [3, 0], false],
            'fifty ids' => [true, false, range(1, 50), true],
            'fifty one ids' => [true, false, range(1, 51), false],
        ];
    }

    public function test_a_relation_keeps_the_linked_model_as_a_number()
    {
        $type = new RelationType;
        $uuid = '3f0c9a2e-5b1d-4c8e-9a47-6d2e1f0b8c35';

        $this->assertSame(
            ['link' => ['model' => 7, 'label_field' => $uuid], 'multiple' => false],
            $type->resolveSettings(['link' => ['model' => '7', 'label_field' => $uuid], 'colour' => 'red']),
        );
        $this->assertSame(['link' => ['model' => null, 'label_field' => null], 'multiple' => false], $type->resolveSettings([]));
        $this->assertSame('posts', $type->resolveSettings(['link' => ['model' => 'posts']])['link']['model']);
        $this->assertSame('posts', $type->resolveSettings(['link' => 'posts'])['link']);
    }

    public function test_a_relation_link_is_checked()
    {
        $type = new RelationType;
        $uuid = '3f0c9a2e-5b1d-4c8e-9a47-6d2e1f0b8c35';
        $message = 'The link must name a model and one of its fields.';
        $errors = fn (mixed $link) => $type->settingErrors($type->resolveSettings(['link' => $link]));

        $this->assertSame([], $errors(['model' => 7, 'label_field' => $uuid]));
        $this->assertSame([], $errors(['model' => '7', 'label_field' => $uuid]));
        $this->assertSame([], $errors(['model' => null, 'label_field' => null]));
        $this->assertSame([], $errors([]));
        $this->assertSame([$message], $errors(['model' => 0, 'label_field' => null]));
        $this->assertSame([$message], $errors(['model' => 'posts', 'label_field' => null]));
        $this->assertSame([$message], $errors(['model' => 7, 'label_field' => 'title']));
        $this->assertSame([$message], $errors(['model' => 7, 'label_field' => 12]));
        $this->assertCount(1, $errors('posts'));
    }

    public function test_link_reads_raw_or_resolved_settings()
    {
        $uuid = '3f0c9a2e-5b1d-4c8e-9a47-6d2e1f0b8c35';
        $none = ['model' => null, 'label_field' => null];

        $this->assertSame(['model' => 7, 'label_field' => $uuid], RelationType::link(['link' => ['model' => '7', 'label_field' => $uuid]]));
        $this->assertSame($none, RelationType::link(null));
        $this->assertSame($none, RelationType::link([]));
        $this->assertSame($none, RelationType::link(['link' => 'posts']));
        $this->assertSame($none, RelationType::link(['link' => ['model' => 0, 'label_field' => '']]));
    }

    public function test_ids_reads_a_stored_or_submitted_value()
    {
        $this->assertSame([], RelationType::ids(null));
        $this->assertSame([], RelationType::ids(''));
        $this->assertSame([], RelationType::ids([]));
        $this->assertSame([], RelationType::ids('x'));
        $this->assertSame([12], RelationType::ids(12));
        $this->assertSame([12], RelationType::ids('12'));
        $this->assertSame([3, 7], RelationType::ids([3, '7', 3, 0, -2, 'x', null, 1.5]));
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    #[DataProvider('canLabelCases')]
    public function test_which_fields_can_name_an_entry(string $type, ?array $settings, bool $can)
    {
        $this->assertSame($can, RelationType::canLabel($type, $settings));
    }

    /**
     * @return array<string, array{string, array<string, mixed>|null, bool}>
     */
    public static function canLabelCases(): array
    {
        return [
            'text' => ['text', [], true],
            'textarea' => ['textarea', null, true],
            'email' => ['email', [], true],
            'url' => ['url', [], true],
            'hidden' => ['hidden', [], true],
            'number' => ['number', [], true],
            'radio' => ['radio', [], true],
            'single select' => ['select', ['multiple' => false], true],
            'select without settings' => ['select', null, true],
            'select with several choices' => ['select', ['multiple' => true], false],
            'checkboxes' => ['checkboxes', [], false],
            'rich text' => ['rich_text', [], false],
            'datetime' => ['datetime', [], false],
            'file' => ['file', [], false],
            'relation' => ['relation', [], false],
        ];
    }

    public function test_every_type_that_can_name_an_entry_is_a_core_type()
    {
        foreach (RelationType::LABEL_TYPES as $key) {
            $this->assertTrue($this->registry()->has($key), "[{$key}] is not a core field type.");
        }
    }

    private function registry(): FieldTypeRegistry
    {
        return app(FieldTypeRegistry::class);
    }

    private function fakeType(string $key): FieldType
    {
        return new class($key) extends FieldType
        {
            public function __construct(private readonly string $typeKey) {}

            public function key(): string
            {
                return $this->typeKey;
            }

            public function label(): string
            {
                return 'Fake';
            }

            public function icon(): string
            {
                return 'star';
            }

            public function category(): string
            {
                return 'advanced';
            }

            public function storage(array $settings): array
            {
                return ['declaredType' => 'int', 'canFilter' => true];
            }
        };
    }
}

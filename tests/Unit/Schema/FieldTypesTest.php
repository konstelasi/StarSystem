<?php

namespace Tests\Unit\Schema;

use App\Hooks\Hook;
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

    public function test_relation_and_repeater_are_reserved()
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
            $types['relation'] = $this->fakeType('relation');
            $types['text'] = $this->fakeType('text');

            return $types;
        });

        $registry = $this->registry();

        $this->assertFalse($registry->has('relation'));
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

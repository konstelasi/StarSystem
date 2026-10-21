<?php

namespace App\Schema\FieldTypes;

use App\Schema\OptionsSource;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One inspector setting of a field type. toArray() is the builder's
 * SettingDescriptor; rules() is the server-side check of a saved value,
 * which the builder never sees.
 */
final class Setting
{
    public const INPUTS = ['text', 'number', 'boolean', 'select', 'options_source', 'string_list', 'model_link'];

    /**
     * @param  list<array{value: string, label: string}>|null  $choices
     * @param  list<mixed>|null  $rules  Replaces the input's default rules.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $input,
        public readonly mixed $default = null,
        public readonly ?string $help = null,
        public readonly ?array $choices = null,
        private readonly ?array $rules = null,
    ) {}

    public static function text(string $key, string $label, mixed $default = null, ?string $help = null): self
    {
        return new self($key, $label, 'text', $default, $help);
    }

    /**
     * @param  list<mixed>|null  $rules
     */
    public static function number(string $key, string $label, int|float|null $default = null, ?string $help = null, ?array $rules = null): self
    {
        return new self($key, $label, 'number', $default, $help, rules: $rules);
    }

    public static function boolean(string $key, string $label, bool $default = false, ?string $help = null): self
    {
        return new self($key, $label, 'boolean', $default, $help);
    }

    /**
     * @param  array<string, string>  $choices  value => label
     */
    public static function select(string $key, string $label, array $choices, string $default, ?string $help = null): self
    {
        $list = [];
        foreach ($choices as $value => $choiceLabel) {
            $list[] = ['value' => (string) $value, 'label' => $choiceLabel];
        }

        return new self($key, $label, 'select', $default, $help, $list);
    }

    public static function options(string $key = 'options', string $label = 'Options', ?string $help = null): self
    {
        return new self($key, $label, 'options_source', OptionsSource::empty(), $help);
    }

    public static function stringList(string $key, string $label, ?string $help = null): self
    {
        return new self($key, $label, 'string_list', [], $help);
    }

    /**
     * The model an entry field links to and the field that names its
     * entries, as {model: <ss_models id>, label_field: <ss_fields uuid>}.
     * Whether they exist is checked when the model is saved.
     */
    public static function modelLink(string $key, string $label, ?string $help = null): self
    {
        return new self($key, $label, 'model_link', ['model' => null, 'label_field' => null], $help);
    }

    /**
     * Validation rules for this setting's value, keyed by path suffix: ''
     * for the value itself and '.*' for list items.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return ['' => $this->rules];
        }

        return match ($this->input) {
            'text' => ['' => ['nullable', 'string', 'max:4096']],
            'number' => ['' => ['nullable', 'numeric']],
            'boolean' => ['' => ['boolean']],
            'select' => ['' => ['required', 'string', Rule::in(array_column($this->choices ?? [], 'value'))]],
            'options_source' => ['' => ['required', 'array', $this->optionsRule()]],
            'string_list' => ['' => ['array', 'list'], '.*' => ['string', 'max:255']],
            'model_link' => ['' => ['nullable', 'array', $this->linkRule()]],
            default => ['' => []],
        };
    }

    /**
     * The builder's SettingDescriptor.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'label' => $this->label,
            'help' => $this->help,
            'input' => $this->input,
            'default' => $this->default,
            'choices' => $this->choices,
        ], fn ($value, $key) => ! in_array($key, ['help', 'choices'], true) || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    private function optionsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            foreach (OptionsSource::errors($value) as $error) {
                $fail($error);
            }
        };
    }

    private function linkRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            // Not an array: the array rule has already said so.
            if (! is_array($value)) {
                return;
            }

            $model = $value['model'] ?? null;
            $labelField = $value['label_field'] ?? null;

            $modelFits = $model === null
                || (is_int($model) && $model > 0)
                || (is_string($model) && ctype_digit($model) && (int) $model > 0);

            if (! $modelFits || ($labelField !== null && ! Str::isUuid($labelField))) {
                $fail('The link must name a model and one of its fields.');
            }
        };
    }
}

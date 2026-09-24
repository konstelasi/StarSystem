<?php

namespace App\Schema\FieldTypes;

use Illuminate\Support\Facades\Validator;

/**
 * A kind of field the builder offers: text, select, number and so on.
 *
 * The type decides how StarDust stores the value (storage()), which
 * settings the inspector shows (settings()) and how an entry's value is
 * checked (rules()) and cleaned (normalize()). storage() may depend on the
 * settings, which is how a number field with "whole numbers only" becomes
 * an int field; the schema diff turns a change in storage into a StarDust
 * retype.
 *
 * Every field is registered with StarDust, JSON-only types included:
 * StarDust only rewrites stored entries on rename or delete for fields it
 * knows, so an unregistered key would survive in every entry forever.
 * JSON-only types register as a non-filterable string and never allow
 * filtering (canFilter false).
 */
abstract class FieldType
{
    public const CATEGORIES = ['basic', 'choice', 'number', 'date', 'rich', 'media', 'advanced'];

    public const DECLARED_TYPES = ['string', 'int', 'numeric', 'datetime'];

    abstract public function key(): string;

    abstract public function label(): string;

    /**
     * A lucide icon name.
     */
    abstract public function icon(): string;

    /**
     * @return 'basic'|'choice'|'number'|'date'|'rich'|'media'|'advanced'
     */
    abstract public function category(): string;

    /**
     * @param  array<string, mixed>  $settings  Resolved settings.
     * @return array{declaredType: 'string'|'int'|'numeric'|'datetime', canFilter: bool}
     */
    abstract public function storage(array $settings): array;

    /**
     * @return list<Setting>
     */
    public function settings(): array
    {
        return [];
    }

    /**
     * Laravel rules for an entry's value, keyed by path suffix: '' for the
     * value itself and '.*' for list items. A caller validating field
     * "tags" uses ['tags' => $rules[''], 'tags.*' => $rules['.*']].
     *
     * @param  array<string, mixed>  $settings  Resolved settings.
     * @return array<string, list<mixed>>
     */
    public function rules(array $settings, bool $required): array
    {
        return ['' => [$required ? 'required' : 'nullable', ...$this->valueRules($settings)]];
    }

    /**
     * The value as it is stored: the shape StarDust coerces without
     * complaint, and the same shape whatever the form sent.
     *
     * @param  array<string, mixed>  $settings  Resolved settings.
     */
    public function normalize(mixed $value, array $settings = []): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : $value;
    }

    /**
     * The palette entry: what the builder shows before a field exists.
     *
     * @return array{key: string, label: string, icon: string, category: string}
     */
    public function palette(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'icon' => $this->icon(),
            'category' => $this->category(),
        ];
    }

    /**
     * The builder's FieldTypeDescriptor. storage is for the default
     * settings; the server recomputes it from the saved settings.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->palette(),
            'settings' => array_map(fn (Setting $setting) => $setting->toArray(), $this->settings()),
            'storage' => $this->storage($this->resolveSettings([])),
        ];
    }

    /**
     * The settings with defaults filled in and unknown keys dropped.
     *
     * @param  array<array-key, mixed>  $given
     * @return array<string, mixed>
     */
    public function resolveSettings(array $given): array
    {
        $resolved = [];
        foreach ($this->settings() as $setting) {
            $resolved[$setting->key] = array_key_exists($setting->key, $given) ? $given[$setting->key] : $setting->default;
        }

        return $resolved;
    }

    /**
     * Problems with a field's settings, as plain sentences.
     *
     * @param  array<string, mixed>  $settings  Resolved settings.
     * @return list<string>
     */
    public function settingErrors(array $settings): array
    {
        $rules = [];
        $attributes = [];
        foreach ($this->settings() as $setting) {
            foreach ($setting->rules() as $suffix => $settingRules) {
                $rules[$setting->key.$suffix] = $settingRules;
            }
            $attributes[$setting->key] = strtolower($setting->label);
        }

        $validator = Validator::make($settings, $rules, [], $attributes);

        return [...array_values($validator->errors()->all()), ...($validator->fails() ? [] : $this->crossCheck($settings))];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function canFilter(array $settings): bool
    {
        return $this->storage($settings)['canFilter'];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function declaredType(array $settings): string
    {
        return $this->storage($settings)['declaredType'];
    }

    /**
     * Rules for a present value, before required/nullable.
     *
     * @param  array<string, mixed>  $settings
     * @return list<mixed>
     */
    protected function valueRules(array $settings): array
    {
        return ['string'];
    }

    /**
     * Checks across settings, run once each setting is valid on its own.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    protected function crossCheck(array $settings): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    protected function rangeErrors(array $settings, bool $strict): array
    {
        $errors = [];
        $min = $settings['min'] ?? null;
        $max = $settings['max'] ?? null;

        if ($min !== null && $max !== null && ($strict ? $min >= $max : $min > $max)) {
            $errors[] = $strict ? 'The minimum must be below the maximum.' : 'The minimum can\'t be above the maximum.';
        }

        if (($settings['step'] ?? null) !== null && $settings['step'] <= 0) {
            $errors[] = 'The step must be above zero.';
        }

        return $errors;
    }
}

<?php

namespace App\Schema;

/**
 * Where a choice field's options come from, stored as a plain array in the
 * field's settings so it round-trips through the export format unchanged:
 *
 *   {kind: 'static', options: [{value, label}]}
 *   {kind: 'model', model: <model slug>, label_field: <field key>}
 *   {kind: 'hook', name: <hook name>}
 *
 * Only static options can be checked when a field is saved. Model and hook
 * options are resolved when an entry form is built.
 */
final class OptionsSource
{
    public const KINDS = ['static', 'model', 'hook'];

    /**
     * @return array{kind: 'static', options: list<array{value: string, label: string}>}
     */
    public static function empty(): array
    {
        return ['kind' => 'static', 'options' => []];
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     * @return array{kind: 'static', options: list<array{value: string, label: string}>}
     */
    public static function static(array $options): array
    {
        return ['kind' => 'static', 'options' => $options];
    }

    /**
     * @return array{kind: 'model', model: string, label_field: string}
     */
    public static function model(string $model, string $labelField): array
    {
        return ['kind' => 'model', 'model' => $model, 'label_field' => $labelField];
    }

    /**
     * @return array{kind: 'hook', name: string}
     */
    public static function hook(string $name): array
    {
        return ['kind' => 'hook', 'name' => $name];
    }

    /**
     * The allowed values of a static source, or null when they are only
     * known at runtime.
     *
     * @return list<string>|null
     */
    public static function staticValues(mixed $source): ?array
    {
        if (! is_array($source) || ($source['kind'] ?? null) !== 'static' || ! is_array($source['options'] ?? null)) {
            return null;
        }

        return array_values(array_map(fn ($option) => (string) ($option['value'] ?? ''), $source['options']));
    }

    /**
     * @return list<string>
     */
    public static function errors(mixed $source): array
    {
        if (! is_array($source) || ! in_array($source['kind'] ?? null, self::KINDS, true)) {
            return ['Options must come from a list, a model or a hook.'];
        }

        return match ($source['kind']) {
            'static' => self::staticErrors($source['options'] ?? null),
            'model' => self::filled($source, ['model', 'label_field'], 'Options from a model need the model and the field to show.'),
            'hook' => self::filled($source, ['name'], 'Options from a hook need the hook name.'),
        };
    }

    /**
     * @return list<string>
     */
    private static function staticErrors(mixed $options): array
    {
        if (! is_array($options) || ! array_is_list($options)) {
            return ['The options must be a list.'];
        }

        $seen = [];
        foreach ($options as $option) {
            if (! is_array($option) || ! is_string($option['value'] ?? null) || ! is_string($option['label'] ?? null)) {
                return ['Every option needs a text value and a label.'];
            }

            if (isset($seen[$option['value']])) {
                return ["The option value \"{$option['value']}\" is used twice."];
            }

            $seen[$option['value']] = true;
        }

        return [];
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function filled(array $source, array $keys, string $message): array
    {
        foreach ($keys as $key) {
            if (! is_string($source[$key] ?? null) || trim($source[$key]) === '') {
                return [$message];
            }
        }

        return [];
    }
}

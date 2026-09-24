<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;
use App\Schema\OptionsSource;
use Illuminate\Validation\Rule;

/**
 * One choice out of a few, all shown at once.
 */
class RadioType extends FieldType
{
    public function key(): string
    {
        return 'radio';
    }

    public function label(): string
    {
        return 'Radio buttons';
    }

    public function icon(): string
    {
        return 'circle-dot';
    }

    public function category(): string
    {
        return 'choice';
    }

    public function settings(): array
    {
        return [Setting::options()];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => true];
    }

    protected function valueRules(array $settings): array
    {
        $values = OptionsSource::staticValues($settings['options'] ?? null);

        return $values === null ? ['string'] : ['string', Rule::in($values)];
    }
}

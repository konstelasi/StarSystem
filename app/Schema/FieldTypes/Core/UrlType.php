<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

class UrlType extends FieldType
{
    public function key(): string
    {
        return 'url';
    }

    public function label(): string
    {
        return 'URL';
    }

    public function icon(): string
    {
        return 'link';
    }

    public function category(): string
    {
        return 'basic';
    }

    public function settings(): array
    {
        return [
            Setting::text('placeholder', 'Placeholder'),
            Setting::text('default', 'Default value'),
        ];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => true];
    }

    protected function valueRules(array $settings): array
    {
        return ['string', 'url', 'max:2048'];
    }
}

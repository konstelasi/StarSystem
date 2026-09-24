<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * A reference to one or more stored files, by path or id. JSON-only.
 * Checking the upload itself is the media library's job.
 */
class FileType extends FieldType
{
    public function key(): string
    {
        return 'file';
    }

    public function label(): string
    {
        return 'File';
    }

    public function icon(): string
    {
        return 'paperclip';
    }

    public function category(): string
    {
        return 'media';
    }

    public function settings(): array
    {
        return [
            Setting::boolean('multiple', 'Allow several files'),
            Setting::stringList('accept', 'Allowed file types', 'Extensions or MIME types, such as .pdf or image/*. Leave empty to allow any.'),
        ];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => false];
    }

    public function rules(array $settings, bool $required): array
    {
        if (! ($settings['multiple'] ?? false)) {
            return parent::rules($settings, $required);
        }

        return [
            '' => [$required ? 'required' : 'nullable', 'array', 'list'],
            '.*' => $this->valueRules($settings),
        ];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        return ($settings['multiple'] ?? false) ? CheckboxesType::normalizeList($value) : parent::normalize($value, $settings);
    }

    protected function valueRules(array $settings): array
    {
        return ['string', 'max:2048'];
    }
}

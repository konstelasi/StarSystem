<?php

namespace App\Schema\FieldTypes\Core;

use App\Files\File;
use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;
use Closure;

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
        $value = [...$this->valueRules($settings), $this->existsRule()];

        if (! ($settings['multiple'] ?? false)) {
            return ['' => [$required ? 'required' : 'nullable', ...$value]];
        }

        return [
            '' => [$required ? 'required' : 'nullable', 'array', 'list'],
            '.*' => $value,
        ];
    }

    /**
     * A picked uuid must be a file that exists for the current site: File
     * uses BelongsToSite, so this can't be a plain `exists:files,uuid`
     * rule, which would query the table directly and validate any site's
     * uuid as belonging to every other site.
     */
    private function existsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! File::query()->where('uuid', $value)->exists()) {
                $fail('The selected file does not exist.');
            }
        };
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

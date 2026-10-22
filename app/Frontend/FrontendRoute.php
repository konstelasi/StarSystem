<?php

namespace App\Frontend;

/**
 * One row of the address table: a model the site publishes and where.
 *
 * The row names the model and its fields by id, not by name, so renaming
 * a field or a model leaves the address working. $path is one segment such
 * as "blog", or empty for the root address. The model at the root address
 * may also say which entry is the home page, by its slug.
 */
final class FrontendRoute
{
    /**
     * @param  int  $modelId  The ss_models id.
     * @param  string  $slugField  The uuid of the text field that holds an entry's address.
     * @param  string  $titleField  The uuid of the field that names an entry.
     * @param  string|null  $publishedField  The uuid of a checkbox field that must be ticked, or null when every entry that isn't trashed is public.
     * @param  bool  $list  Whether the address itself lists the model's entries.
     */
    public function __construct(
        public readonly int $modelId,
        public readonly string $path,
        public readonly string $slugField,
        public readonly string $titleField,
        public readonly ?string $publishedField,
        public readonly bool $list,
        public readonly ?string $homeSlug,
        public readonly string $label,
    ) {}

    /**
     * Reads a row as it is stored or submitted. Anything missing or of the
     * wrong kind becomes empty, which the settings check then reports.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $text = fn (string $key): string => is_string($row[$key] ?? null) ? trim($row[$key]) : '';
        $optional = fn (string $key): ?string => $text($key) === '' ? null : $text($key);

        return new self(
            modelId: is_int($row['model_id'] ?? null) || (is_string($row['model_id'] ?? null) && ctype_digit($row['model_id'])) ? (int) $row['model_id'] : 0,
            path: trim($text('path'), '/'),
            slugField: $text('slug_field'),
            titleField: $text('title_field'),
            publishedField: $optional('published_field'),
            list: filter_var($row['list'] ?? false, FILTER_VALIDATE_BOOL),
            homeSlug: $optional('home_slug'),
            label: $text('label'),
        );
    }

    /**
     * @return array{model_id: int, path: string, slug_field: string, title_field: string, published_field: string|null, list: bool, home_slug: string|null, label: string}
     */
    public function toArray(): array
    {
        return [
            'model_id' => $this->modelId,
            'path' => $this->path,
            'slug_field' => $this->slugField,
            'title_field' => $this->titleField,
            'published_field' => $this->publishedField,
            'list' => $this->list,
            'home_slug' => $this->homeSlug,
            'label' => $this->label,
        ];
    }

    public function isRoot(): bool
    {
        return $this->path === '';
    }
}

<?php

namespace App\Frontend;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * An entry as a theme sees it. A theme gets plain values and these few
 * methods and never reaches the database or the rest of the application,
 * so what a theme can do stays small and a template can be swapped for
 * another engine's later.
 *
 * Fields are asked for by key, the name the builder gave them. Print text
 * with {{ }}, which escapes it. html() is for the one case where markup is
 * wanted, and what it returns is safe to print with {!! !!}.
 */
final class PresentedEntry
{
    /**
     * @param  array<string, array{label: string, type: string, value: mixed, labels: array<string, string>}>  $fields  Every field of the model by key, in order. $labels maps a choice field's stored value to the label to show.
     * @param  list<string>  $leave  Keys that fields() leaves out, because the page shows them itself.
     * @param  array<string, PresentedFile>  $files  The files the entry names, by lowercase uuid.
     * @param  array<string, list<PresentedLink>>  $links  The entries each relation field links to, by field key.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $title,
        public readonly CarbonImmutable $createdAt,
        private readonly array $fields,
        private readonly HtmlCleaner $cleaner,
        private readonly array $leave = [],
        private readonly array $files = [],
        private readonly array $links = [],
        private readonly string $locale = 'en',
    ) {}

    /**
     * The value as it is stored, for a theme that needs its shape.
     */
    public function raw(string $key): mixed
    {
        return $this->fields[$key]['value'] ?? null;
    }

    /**
     * Whether the field has something to show. A checkbox has it only when
     * it is ticked, and a number of 0 still has it.
     */
    public function has(string $key): bool
    {
        $value = $this->raw($key);

        if ($value === null || $value === '' || $value === []) {
            return false;
        }

        return ($this->fields[$key]['type'] ?? '') === 'checkbox' ? filter_var($value, FILTER_VALIDATE_BOOL) : true;
    }

    /**
     * The value as plain text, or an empty string. A choice shows its label,
     * several values are joined with commas, and a file or a linked entry
     * shows its name. Print it with {{ }}.
     */
    public function text(string $key): string
    {
        $field = $this->fields[$key] ?? null;

        if ($field === null || $field['value'] === null) {
            return '';
        }

        if ($field['type'] === 'file') {
            return implode(', ', array_map(fn (PresentedFile $file) => $file->name, $this->files($key)));
        }

        if ($field['type'] === 'relation') {
            return implode(', ', array_map(fn (PresentedLink $link) => $link->title, $this->links($key)));
        }

        $parts = [];

        foreach (is_array($field['value']) ? $field['value'] : [$field['value']] as $item) {
            if (is_scalar($item)) {
                $parts[] = $field['labels'][(string) $item] ?? (string) $item;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Rich text with scripts and other unsafe markup removed. For any other
     * type it is the text, escaped.
     */
    public function html(string $key): HtmlString
    {
        if (($this->fields[$key]['type'] ?? '') === 'rich_text') {
            $value = $this->raw($key);

            return new HtmlString(is_string($value) ? $this->cleaner->clean($value) : '');
        }

        return new HtmlString(e($this->text($key)));
    }

    /**
     * A date or datetime field written out with the given PHP date format,
     * in the site's language. Empty when the field has no date.
     */
    public function date(string $key, string $format = 'j F Y'): string
    {
        $value = $this->raw($key);

        if ($value instanceof DateTimeInterface) {
            $date = CarbonImmutable::instance($value);
        } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            try {
                $date = CarbonImmutable::parse($value, 'UTC');
            } catch (Throwable) {
                return '';
            }
        } else {
            return '';
        }

        return $this->write($date, $format);
    }

    /**
     * The day the entry was made, written like date().
     */
    public function created(string $format = 'j F Y'): string
    {
        return $this->write($this->createdAt, $format);
    }

    public function file(string $key): ?PresentedFile
    {
        return $this->files($key)[0] ?? null;
    }

    /**
     * @return list<PresentedFile> The files the field names that still exist, in order.
     */
    public function files(string $key): array
    {
        $value = $this->raw($key);
        $found = [];

        foreach (is_array($value) ? $value : [$value] as $uuid) {
            if (is_string($uuid) && isset($this->files[strtolower($uuid)])) {
                $found[] = $this->files[strtolower($uuid)];
            }
        }

        return $found;
    }

    public function link(string $key): ?PresentedLink
    {
        return $this->links($key)[0] ?? null;
    }

    /**
     * The entries a relation field links to that a visitor may see, in the
     * order they were picked.
     *
     * @return list<PresentedLink>
     */
    public function links(string $key): array
    {
        return $this->links[$key] ?? [];
    }

    /**
     * The fields a page can show in a generic way, in the model's order. The
     * title and the address are left out because the page shows them already,
     * and so are hidden fields, which are not meant for visitors. A theme
     * that wants one of those can still ask for it by key.
     *
     * @return list<array{key: string, label: string, type: string, value: mixed}>
     */
    public function fields(): array
    {
        $list = [];

        foreach ($this->fields as $key => $field) {
            if ($field['type'] !== 'hidden' && ! in_array($key, $this->leave, true)) {
                $list[] = ['key' => $key, 'label' => $field['label'], 'type' => $field['type'], 'value' => $field['value']];
            }
        }

        return $list;
    }

    private function write(CarbonImmutable $date, string $format): string
    {
        try {
            return $date->settings(['locale' => $this->locale])->translatedFormat($format);
        } catch (Throwable) {
            return $date->format($format);
        }
    }
}

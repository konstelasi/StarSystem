<?php

namespace App\Frontend;

use App\Entries\EntryManager;
use App\Files\File;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Schema\FieldTypes\Core\RelationType;
use App\Schema\OptionsSource;
use App\Sites\CurrentSite;
use Carbon\CarbonImmutable;
use StarDust\Read\Entry;

/**
 * Turns an entry into what a theme receives. It does the reading a theme
 * must not do for itself: the files an entry names and the entries it
 * links to are looked up here, once, with the current site's scope, and a
 * linked entry that a visitor may not see is left out.
 *
 * Use one presenter for a whole page. It remembers the address table and
 * the models it has read, so a list of entries doesn't read them again for
 * each one.
 */
class EntryPresenter
{
    /** @var array<int, FrontendRoute>|null By model id. */
    private ?array $routes = null;

    /** @var array<int, SchemaModel|null> By model id. */
    private array $models = [];

    public function __construct(
        private readonly HtmlCleaner $cleaner,
        private readonly EntryManager $entries,
        private readonly FrontendSettings $settings,
        private readonly PublicEntries $public,
        private readonly CurrentSite $site,
    ) {}

    public function present(Entry $entry, SchemaModel $model, FrontendRoute $route): PresentedEntry
    {
        $fields = [];

        foreach ($model->fields as $field) {
            $fields[$field->key] = [
                'label' => $field->label,
                'type' => $field->type,
                'value' => $entry->fields[$field->key] ?? null,
                'labels' => $this->optionLabels($field),
            ];
        }

        $slugKey = $this->key($model, $route->slugField);
        $titleKey = $this->key($model, $route->titleField);
        $slug = $slugKey === null ? '' : $this->scalar($entry->fields[$slugKey] ?? null);
        $title = $titleKey === null ? '' : $this->scalar($entry->fields[$titleKey] ?? null);

        return new PresentedEntry(
            id: $entry->id,
            url: $this->address($route, $slug),
            title: $title !== '' ? $title : ($slug !== '' ? $slug : 'Untitled'),
            createdAt: CarbonImmutable::instance($entry->createdAt),
            fields: $fields,
            cleaner: $this->cleaner,
            leave: array_values(array_filter([$slugKey, $titleKey])),
            files: $this->files($entry, $model),
            links: $this->links($entry, $model),
            locale: $this->site->get()->locale ?: config()->string('app.locale'),
        );
    }

    /**
     * The address of an entry. The home page entry is the site's root.
     */
    public function address(FrontendRoute $route, string $slug): string
    {
        if ($route->isRoot() && $route->homeSlug !== null && $slug === $route->homeSlug) {
            return url('/');
        }

        return url($route->isRoot() ? $slug : $route->path.'/'.$slug);
    }

    /**
     * The files the entry's file fields name, by lowercase uuid, in one
     * query. File sees the current site only, so a uuid from another site
     * or a file in the trash is simply not there.
     *
     * @return array<string, PresentedFile>
     */
    private function files(Entry $entry, SchemaModel $model): array
    {
        $uuids = [];

        foreach ($model->fields as $field) {
            if ($field->type !== 'file') {
                continue;
            }

            $value = $entry->fields[$field->key] ?? null;

            foreach (is_array($value) ? $value : [$value] as $uuid) {
                if (is_string($uuid) && $uuid !== '') {
                    $uuids[] = strtolower($uuid);
                }
            }
        }

        if ($uuids === []) {
            return [];
        }

        $files = [];

        foreach (File::query()->whereIn('uuid', array_unique($uuids))->get() as $file) {
            $files[strtolower($file->uuid)] = new PresentedFile($file->url(), $file->original_name, $file->mime, (string) $file->alt);
        }

        return $files;
    }

    /**
     * The linked entries a visitor may see, by relation field key. A trashed
     * entry is left out, and so is one its model hides behind a published
     * switch that is off. A model with no address still shows its entries,
     * as names without a link.
     *
     * @return array<string, list<PresentedLink>>
     */
    private function links(Entry $entry, SchemaModel $model): array
    {
        $links = [];

        foreach ($model->fields as $field) {
            if ($field->type !== 'relation') {
                continue;
            }

            $ids = RelationType::ids($entry->fields[$field->key] ?? null);
            $link = RelationType::link($field->settings);
            $target = $link['model'] === null ? null : $this->model($link['model']);

            if ($ids === [] || $target === null) {
                continue;
            }

            $route = $this->routes()[$target->id] ?? null;
            $found = $this->entries->getMany($target, $ids);

            foreach ($ids as $id) {
                $linked = $found[$id] ?? null;

                if ($linked === null) {
                    continue;
                }

                $visible = $route === null ? ! $this->entries->isTrashed($linked) : $this->public->isVisible($linked, $target, $route);

                if (! $visible) {
                    continue;
                }

                $slugKey = $route === null ? null : $this->key($target, $route->slugField);
                $slug = $slugKey === null ? '' : $this->scalar($linked->fields[$slugKey] ?? null);

                $links[$field->key][] = new PresentedLink(
                    $linked->id,
                    $this->name($linked, $target, $link['label_field'], $route, $slug),
                    $route === null || $slug === '' ? null : $this->address($route, $slug),
                );
            }
        }

        return $links;
    }

    /**
     * What names a linked entry: the field the relation was set up to name
     * entries by, else the title field of the model's address, else its address.
     */
    private function name(Entry $linked, SchemaModel $target, ?string $labelField, ?FrontendRoute $route, string $slug): string
    {
        foreach ([$labelField, $route?->titleField] as $uuid) {
            $key = $uuid === null ? null : $this->key($target, $uuid);
            $text = $key === null ? '' : $this->scalar($linked->fields[$key] ?? null);

            if ($text !== '') {
                return $text;
            }
        }

        return $slug !== '' ? $slug : 'Untitled';
    }

    /**
     * The labels of a choice field's static options by stored value.
     *
     * @return array<string, string>
     */
    private function optionLabels(SchemaField $field): array
    {
        $source = $field->settings['options'] ?? null;

        if (! in_array($field->type, ['select', 'radio', 'checkboxes'], true) || ($source['kind'] ?? null) !== 'static' || OptionsSource::staticValues($source) === null) {
            return [];
        }

        $labels = [];

        foreach ($source['options'] as $option) {
            $labels[(string) $option['value']] = (string) $option['label'];
        }

        return $labels;
    }

    /**
     * @return array<int, FrontendRoute>
     */
    private function routes(): array
    {
        if ($this->routes === null) {
            $this->routes = [];

            foreach ($this->settings->routes() as $route) {
                $this->routes[$route->modelId] ??= $route;
            }
        }

        return $this->routes;
    }

    private function model(int $id): ?SchemaModel
    {
        if (! array_key_exists($id, $this->models)) {
            $model = SchemaModel::query()->find($id);
            $this->models[$id] = $model !== null && ! $model->isDeleting() ? $model : null;
        }

        return $this->models[$id];
    }

    private function key(SchemaModel $model, string $uuid): ?string
    {
        /** @var SchemaField|null $field */
        $field = $model->fields->firstWhere('id', $uuid);

        return $field?->key;
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

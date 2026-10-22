<?php

namespace App\Frontend;

use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Sites\CurrentSite;
use App\StarDust\StarDustService;
use StarDust\Schema\ModelDescription;

/**
 * Which of the site's models are public and at which address: the address
 * table, kept in the current site's settings under `frontend.routes`.
 *
 * It sits in the settings, and not on each model, so copying or importing a
 * model never carries an address with it and two models can't fight for one.
 * A row names its model and fields by id, and a row whose model or field
 * has since gone is left out when read, never half used. A published model
 * that lost its "published" switch would otherwise show every draft.
 */
class FrontendSettings
{
    /** The longest address, in characters. */
    public const MOST_PATH = 64;

    /** Field types that can name an entry on a page. */
    public const TITLE_TYPES = ['text', 'textarea', 'email', 'url'];

    /** The shape of an address and of an entry's slug: words joined by single hyphens. */
    public const ADDRESS_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const MOST_LABEL = 100;

    public function __construct(
        private readonly CurrentSite $site,
        private readonly StarDustService $stardust,
        private readonly AddressConflicts $conflicts,
    ) {}

    /**
     * The rows that still work, in the order they were saved.
     *
     * @return list<FrontendRoute>
     */
    public function routes(): array
    {
        $routes = array_map(FrontendRoute::fromArray(...), $this->stored());

        if ($routes === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(fn (FrontendRoute $route) => $route->modelId, $routes)));
        $models = SchemaModel::query()->whereIn('id', $ids)->where('status', SchemaModel::ACTIVE)->pluck('id')->all();
        $fields = $this->fieldsByModel($ids);

        return array_values(array_filter($routes, function (FrontendRoute $route) use ($models, $fields) {
            $has = fn (?string $uuid) => $uuid === null || isset($fields[$route->modelId][$uuid]);

            return in_array($route->modelId, $models, true)
                && $has($route->slugField) && $has($route->titleField) && $has($route->publishedField);
        }));
    }

    /**
     * What is wrong with the rows, in sentences for the admin, under the
     * position of the row each belongs to. Empty when the table is valid.
     *
     * @param  array<array-key, mixed>  $rows  The rows as submitted.
     * @return array<int, list<string>>
     */
    public function problems(array $rows): array
    {
        $routes = array_map(fn ($row) => FrontendRoute::fromArray(is_array($row) ? $row : []), array_values($rows));
        $ids = array_values(array_unique(array_filter(array_map(fn (FrontendRoute $route) => $route->modelId, $routes))));
        $models = SchemaModel::query()->whereIn('id', $ids)->get()->keyBy('id');
        $fields = $this->fieldsByModel($ids);
        $described = [];

        $problems = [];
        $taken = [];
        $published = [];

        foreach ($routes as $position => $route) {
            $model = $models[$route->modelId] ?? null;

            if ($route->modelId === 0) {
                $problems[$position][] = 'Pick a model for each address.';

                continue;
            }

            if ($model === null || $model->isDeleting()) {
                $problems[$position][] = "This model doesn't exist or is being deleted.";

                continue;
            }

            if (isset($published[$model->id])) {
                $problems[$position][] = 'This model is already published at another address.';
            }

            $published[$model->id] = true;

            $description = $described[$model->id] ??= $this->stardust->describeModel($model->stardust_model_id);

            foreach ([
                ...$this->pathProblems($route, $taken, $position),
                ...$this->slugFieldProblems($route, $fields[$model->id] ?? [], $description),
                ...$this->titleFieldProblems($route, $fields[$model->id] ?? []),
                ...$this->publishedFieldProblems($route, $fields[$model->id] ?? [], $description),
                ...$this->pageProblems($route),
            ] as $message) {
                $problems[$position][] = $message;
            }
        }

        return $problems;
    }

    /**
     * Saves the table.
     *
     * @param  array<array-key, mixed>  $rows
     *
     * @throws InvalidFrontendSettings
     */
    public function save(array $rows): void
    {
        $problems = $this->problems($rows);

        if ($problems !== []) {
            throw new InvalidFrontendSettings($problems);
        }

        $routes = array_map(fn ($row) => FrontendRoute::fromArray(is_array($row) ? $row : []), array_values($rows));
        $labels = SchemaModel::query()->whereIn('id', array_map(fn (FrontendRoute $route) => $route->modelId, $routes))->pluck('label', 'id');

        $stored = array_map(function (FrontendRoute $route) use ($labels) {
            $row = $route->toArray();
            $row['label'] = $route->label === '' ? (string) $labels[$route->modelId] : $route->label;

            return $row;
        }, $routes);

        $site = $this->site->get();
        $settings = $site->settings ?? [];
        $frontend = is_array($settings['frontend'] ?? null) ? $settings['frontend'] : [];

        $site->update(['settings' => [...$settings, 'frontend' => [...$frontend, 'routes' => $stored]]]);
    }

    /**
     * The saved rows, as arrays.
     *
     * @return list<array<array-key, mixed>>
     */
    private function stored(): array
    {
        $rows = $this->site->get()->settings['frontend']['routes'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }

    /**
     * Every field of these models, by model id and then field uuid.
     *
     * @param  list<int>  $modelIds
     * @return array<int, array<string, SchemaField>>
     */
    private function fieldsByModel(array $modelIds): array
    {
        $fields = [];

        foreach ($modelIds === [] ? [] : SchemaField::query()->whereIn('model_id', $modelIds)->get() as $field) {
            $fields[$field->model_id][$field->id] = $field;
        }

        return $fields;
    }

    /**
     * @param  array<string, int>  $taken  Addresses used by earlier rows, by address and then row position. Updated.
     * @return list<string>
     */
    private function pathProblems(FrontendRoute $route, array &$taken, int $position): array
    {
        $path = $route->path;
        $problems = [];

        if ($path !== '' && (strlen($path) > self::MOST_PATH || ! preg_match(self::ADDRESS_PATTERN, $path))) {
            $problems[] = 'An address is made of lowercase letters, digits and single hyphens, up to '.self::MOST_PATH.' characters.';
        } elseif ($this->conflicts->uses($path)) {
            $problems[] = 'This address is used by StarSystem. Pick another.';
        }

        if (isset($taken[$path])) {
            $problems[] = $path === '' ? 'Only one model can be at the root address.' : 'Another row already uses this address.';
        }

        $taken[$path] ??= $position;

        return $problems;
    }

    /**
     * @param  array<string, SchemaField>  $fields
     * @return list<string>
     */
    private function slugFieldProblems(FrontendRoute $route, array $fields, ?ModelDescription $description): array
    {
        $field = $fields[$route->slugField] ?? null;

        if ($field === null) {
            return ["Pick the field that holds each entry's address."];
        }

        if ($field->type !== 'text') {
            return ["\"{$field->label}\" can't hold an address. Pick a text field."];
        }

        if (! $field->filterable) {
            return ["Make \"{$field->label}\" filterable in the builder so entries can be found by it."];
        }

        if (! $field->required) {
            return ["Make \"{$field->label}\" required so every entry has an address."];
        }

        return $this->indexed($field, $description) ? [] : [$this->stillIndexing($field)];
    }

    /**
     * @param  array<string, SchemaField>  $fields
     * @return list<string>
     */
    private function titleFieldProblems(FrontendRoute $route, array $fields): array
    {
        $field = $fields[$route->titleField] ?? null;

        if ($field === null) {
            return ['Pick the field that names each entry.'];
        }

        return in_array($field->type, self::TITLE_TYPES, true)
            ? []
            : ["\"{$field->label}\" can't name entries. Pick a text, text area, email or URL field."];
    }

    /**
     * @param  array<string, SchemaField>  $fields
     * @return list<string>
     */
    private function publishedFieldProblems(FrontendRoute $route, array $fields, ?ModelDescription $description): array
    {
        if ($route->publishedField === null) {
            return [];
        }

        $field = $fields[$route->publishedField] ?? null;

        if ($field === null) {
            return ["The field that marks an entry as published wasn't found. Pick another, or none."];
        }

        if ($field->type !== 'checkbox') {
            return ["\"{$field->label}\" isn't a checkbox. Pick a checkbox field, or none."];
        }

        if (! $field->filterable) {
            return ["Make \"{$field->label}\" filterable in the builder so unpublished entries can be left out."];
        }

        return $this->indexed($field, $description) ? [] : [$this->stillIndexing($field)];
    }

    /**
     * @return list<string>
     */
    private function pageProblems(FrontendRoute $route): array
    {
        $problems = [];

        if ($route->list && $route->path === '') {
            $problems[] = 'A list needs an address.';
        }

        if ($route->homeSlug !== null && $route->path !== '') {
            $problems[] = 'Only the model at the root address has a home page.';
        } elseif ($route->homeSlug !== null && (strlen($route->homeSlug) > 120 || ! preg_match(self::ADDRESS_PATTERN, $route->homeSlug))) {
            $problems[] = 'The home page address is made of lowercase letters, digits and single hyphens.';
        }

        if (mb_strlen($route->label) > self::MOST_LABEL) {
            $problems[] = 'The menu label can be up to '.self::MOST_LABEL.' characters.';
        }

        return $problems;
    }

    private function indexed(SchemaField $field, ?ModelDescription $description): bool
    {
        return $description?->field($field->key)->isIndexed ?? false;
    }

    private function stillIndexing(SchemaField $field): string
    {
        return "\"{$field->label}\" is still being indexed. Try again in a minute, once the background work has run.";
    }
}

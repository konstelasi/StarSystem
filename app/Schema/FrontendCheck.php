<?php

namespace App\Schema;

use App\Frontend\FrontendRoute;
use App\Frontend\FrontendSettings;
use App\Models\SchemaField;
use App\Models\SchemaModel;

/**
 * What the builder may not do to a model the site publishes, checked when a
 * model is saved or deleted. The address table names a model and three of
 * its fields by id, and the public pages rest on them: the address field
 * finds an entry, the title field names it, and the published switch keeps
 * drafts out. Break one and a page disappears, or worse, a draft appears.
 *
 * Like RelationCheck this reads other tables, so SchemaManager adds its
 * errors to the pure diff's.
 */
final class FrontendCheck
{
    public function __construct(private readonly FrontendSettings $settings) {}

    /**
     * @param  SchemaModel|null  $current  The model as it is saved, or null for one being created.
     * @return list<array{field_uuid: ?string, message: string}>
     */
    public function errors(?SchemaModel $current, ModelSchema $desired): array
    {
        $route = $current === null ? null : $this->route($current);

        if ($current === null || $route === null) {
            return [];
        }

        $errors = [];

        foreach ($this->uses($route) as $uuid => $use) {
            /** @var SchemaField|null $saved */
            $saved = $current->fields->firstWhere('id', $uuid);
            $now = $desired->field($uuid);

            if ($saved === null) {
                continue;
            }

            $message = match (true) {
                $now === null => "\"{$saved->label}\" is used as {$use['role']} on the Site page, so it can't be removed.",
                $now->type !== $saved->type => "\"{$saved->label}\" is used as {$use['role']} on the Site page, so its type can't change.",
                $use['filterable'] && ! $now->filterable => "\"{$saved->label}\" is used as {$use['role']} on the Site page, so it must stay filterable.",
                $use['required'] && ! $now->required => "\"{$saved->label}\" is used as {$use['role']} on the Site page, so it must stay required.",
                default => null,
            };

            if ($message !== null) {
                $errors[] = ['field_uuid' => $uuid, 'message' => $message];
            }
        }

        return $errors;
    }

    /**
     * Why the model can't be deleted, or null when the site doesn't publish it.
     */
    public function deleteProblem(SchemaModel $model): ?string
    {
        $route = $this->route($model);

        if ($route === null) {
            return null;
        }

        $where = $route->isRoot() ? 'at its root address' : "at /{$route->path}";

        return "The site publishes this model {$where}. Remove it from the Site page first.";
    }

    private function route(SchemaModel $model): ?FrontendRoute
    {
        foreach ($this->settings->routes() as $route) {
            if ($route->modelId === $model->id) {
                return $route;
            }
        }

        return null;
    }

    /**
     * The fields the row depends on, by uuid, with how each is used and what
     * it must stay. A field used twice must stay what either use needs.
     *
     * @return array<string, array{role: string, filterable: bool, required: bool}>
     */
    private function uses(FrontendRoute $route): array
    {
        $uses = [];

        foreach ([
            [$route->slugField, 'the address of its entries', true, true],
            [$route->titleField, 'the title of its entries', false, false],
            [$route->publishedField, 'the switch that publishes an entry', true, false],
        ] as [$uuid, $role, $filterable, $required]) {
            if ($uuid !== null && $uuid !== '') {
                $uses[$uuid] = [
                    'role' => $uses[$uuid]['role'] ?? $role,
                    'filterable' => ($uses[$uuid]['filterable'] ?? false) || $filterable,
                    'required' => ($uses[$uuid]['required'] ?? false) || $required,
                ];
            }
        }

        return $uses;
    }
}

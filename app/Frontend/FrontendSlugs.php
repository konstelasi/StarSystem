<?php

namespace App\Frontend;

use App\Models\SchemaField;
use App\Models\SchemaModel;
use StarDust\Read\Entry;

/**
 * Checks the address of an entry when it is saved. A model the site
 * publishes holds an address in its slug field, and that address must be
 * written the way an address is, must not be one StarSystem or another model
 * uses, and must belong to one entry at a time, or two pages would fight
 * over one address.
 *
 * A model that is not published has no rule: any text is fine as a slug.
 */
class FrontendSlugs
{
    /** The longest slug, in characters. */
    public const MOST = 120;

    public function __construct(
        private readonly FrontendSettings $settings,
        private readonly PublicEntries $public,
        private readonly AddressConflicts $conflicts,
    ) {}

    /**
     * What is wrong with the slug in these values, in sentences for the
     * admin, under the key of the form field they belong to.
     *
     * @param  array<string, mixed>  $fields  The values to be saved, keyed by field key.
     * @param  Entry|null  $stored  The entry being saved, or null for a new one.
     * @return array<string, list<string>>
     */
    public function problems(SchemaModel $model, array $fields, ?Entry $stored): array
    {
        $routes = $this->settings->routes();
        $route = null;

        foreach ($routes as $candidate) {
            if ($candidate->modelId === $model->id) {
                $route = $candidate;

                break;
            }
        }

        /** @var SchemaField|null $field */
        $field = $route === null ? null : $model->fields->firstWhere('id', $route->slugField);
        $slug = $field === null ? null : ($fields[$field->key] ?? null);

        // An empty slug is the required rule's to report.
        if ($route === null || $field === null || ! is_string($slug) || $slug === '') {
            return [];
        }

        $key = 'fields.'.$field->key;

        if (strlen($slug) > self::MOST || ! preg_match(FrontendSettings::ADDRESS_PATTERN, $slug)) {
            return [$key => ['Use lowercase letters, digits and single hyphens.']];
        }

        if ($route->isRoot()) {
            if ($this->conflicts->uses($slug)) {
                return [$key => ['This address is used by StarSystem. Pick another.']];
            }

            foreach ($routes as $other) {
                if ($other !== $route && $other->path === $slug) {
                    return [$key => ['This address is used by another model on the site. Pick another.']];
                }
            }
        }

        if ($this->public->slugTaken($model, $route, $slug, $stored?->id)) {
            return [$key => ['Another entry already uses this address.']];
        }

        return [];
    }
}

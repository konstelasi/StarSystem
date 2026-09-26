<?php

namespace Tests\Concerns;

use App\Models\SchemaModel;
use App\Models\Site;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use Illuminate\Support\Str;

/**
 * A model with two plain fields, and the site-scoped URL builder every
 * models-admin feature test needs to hit a route on the right site's
 * domain. Assumes UsesStarDust's `$this->site` for the default domain.
 */
trait ManagesTestModels
{
    private function create(string $slug, string $label): SchemaModel
    {
        $result = $this->manager()->createModel(new ModelSchema($slug, $label, fields: [
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
            new FieldSpec((string) Str::uuid(), 'body', 'Body', 'textarea'),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());

        return $result->model;
    }

    private function manager(): SchemaManager
    {
        return app(SchemaManager::class);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $route, array $parameters = [], ?Site $site = null): string
    {
        return 'http://'.($site ?? $this->site)->domains[0].route($route, $parameters, absolute: false);
    }
}

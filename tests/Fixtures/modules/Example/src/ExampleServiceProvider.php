<?php

namespace Modules\Example;

use App\Hooks\Hook;
use App\Models\Site;
use App\Modules\ModuleServiceProvider;

/**
 * The example module: one action, one filter and one view slot.
 */
class ExampleServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // A filter: adds a field type to the schema's registry of types.
        Hook::addFilter('schema.field_types', fn (array $types) => [
            ...$types,
            'example_rating' => ['label' => 'Rating (example)', 'component' => 'example::RatingInput'],
        ]);
    }

    public function boot(): void
    {
        // An action: remembers which site each saved entry belonged to.
        Hook::addAction('backend.entries:saved', function (int $entryId, ?Site $site) {
            Activity::$saved[] = ['entry' => $entryId, 'site' => $site?->id];
        });

        // A view slot: a notice on the modules page, from dist/components.js.
        $this->addToSlot('backend.view:modules:index', 'ExampleNotice', fn (array $context, ?Site $site) => [
            'message' => "Example {$this->module->version} is running on {$site?->name}.",
        ]);
    }
}

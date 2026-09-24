<?php

namespace Modules\Faulty;

use App\Hooks\Hook;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Adds a filter, then throws while registering or booting, as set by the
 * `faulty.fail_in` config key. A plain ServiceProvider, to show modules
 * don't have to extend ModuleServiceProvider.
 */
class FaultyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Hook::addFilter('schema.field_types', fn (array $types) => [...$types, 'faulty' => []]);

        if (config('faulty.fail_in') === 'register') {
            throw new RuntimeException('Faulty broke while registering.');
        }
    }

    public function boot(): void
    {
        if (config('faulty.fail_in') === 'boot') {
            throw new RuntimeException('Faulty broke while booting.');
        }
    }
}

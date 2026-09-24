<?php

namespace App\Hooks;

use Illuminate\Support\Facades\Facade;

/**
 * Static access to the hook registry, for modules and controllers.
 *
 * @method static void addAction(string $name, callable $callback, int $priority = 10)
 * @method static void addFilter(string $name, callable $callback, int $priority = 10)
 * @method static void doAction(string $name, mixed ...$args)
 * @method static mixed applyFilters(string $name, mixed $value, mixed ...$args)
 * @method static bool removeAction(string $name, callable $callback, ?int $priority = null)
 * @method static bool removeFilter(string $name, callable $callback, ?int $priority = null)
 * @method static bool hasAction(string $name)
 * @method static bool hasFilter(string $name)
 *
 * @see HookRegistry
 */
class Hook extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HookRegistry::class;
    }
}

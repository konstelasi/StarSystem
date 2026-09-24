<?php

namespace App\Modules;

use App\Hooks\Hook;
use App\Models\Site;
use Closure;
use Illuminate\Support\ServiceProvider;

/**
 * An optional base for a module's provider. Any ServiceProvider works;
 * this one also knows its module's manifest, and adds the module's own
 * front-end components to view slots.
 *
 * Add hooks in register() or boot(); the loader attributes them to the
 * module. Module providers can't be deferred.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    protected Manifest $module;

    public function setManifest(Manifest $manifest): void
    {
        $this->module = $manifest;
    }

    /**
     * The public URL of a file in the module's assets folder.
     */
    protected function asset(string $path): string
    {
        return url('_modules/'.$this->module->slug.'/'.ltrim($path, '/')).'?v='.rawurlencode($this->module->version);
    }

    /**
     * Adds one of the module's components to a view slot. $export is a
     * named export of $script, a prebuilt ES module in the assets folder.
     *
     * @param  array<string, mixed>|Closure(array<string, mixed>, Site|null): (array<string, mixed>|null)  $props
     */
    protected function addToSlot(string $slot, string $export, array|Closure $props = [], int $priority = 10, string $script = 'components.js'): void
    {
        Hook::addToSlot($slot, $this->module->slug.'::'.$export, $props, $priority, $this->asset($script));
    }
}

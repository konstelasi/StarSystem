<?php

namespace App\Hooks;

use App\Models\Site;
use App\Sites\CurrentSite;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;
use ReflectionFunction;
use UnexpectedValueException;

/**
 * StarSystem's hooks, kept from HyperCMS: actions notify, filters transform.
 *
 * - `doAction($name, ...$args)` tells listeners something happened. Actions
 *   go through Laravel's event dispatcher, so `Event::listen($name, …)`,
 *   wildcard listeners and `Event::fake()` all work on them.
 * - `applyFilters($name, $value, ...$args)` passes $value through every
 *   callback in turn and returns what the last one returned.
 *
 * Every callback gets the hook's arguments followed by the current Site, or
 * null outside a request that resolved one (a cron tick, the installer).
 * Hooks run for the site being served, and a module that stores anything
 * must key it by that site or one site's data shows up on another.
 * Callbacks that don't need the site simply leave the parameter out.
 *
 * Lower priorities run first (default 10). Callbacks with the same priority
 * run in the order they were added. Laravel listeners added with
 * Event::listen() run at the point they were added, and all registry
 * callbacks for a name run together where the first of them was added.
 *
 * Naming: `<area>.<layer>:<subject>[:<step>][:<what>]`, all lowercase.
 * - area: `backend`, `frontend`, `schema`, `core`, or a module's slug for
 *   hooks a module offers to others (`paging.controller:editor:index:data`).
 * - layer: where the hook fires, `controller`, `view`, `model`, `modules`…
 * - a trailing `:data` marks a filter over the props a controller hands
 *   to its page, e.g. `backend.controller:entries:edit:data`.
 * - `<area>.view:<page>[:<place>]` names a view slot, e.g.
 *   `backend.view:entries:edit`. See viewSlot().
 * - Registries that modules extend are filters over a keyed array, e.g.
 *   `schema.field_types`.
 *
 * Callbacks are remembered with the module that added them (see
 * asOwner()), so a failing module's callbacks can be dropped and hooks can
 * later be switched on or off per site.
 */
class HookRegistry
{
    /** @var array<string, array<int, list<array{callback: callable, owner: string|null, arity: int}>>> */
    private array $actions = [];

    /** @var array<string, array<int, list<array{callback: callable, owner: string|null, arity: int}>>> */
    private array $filters = [];

    /** @var array<string, true> Action names that already have a dispatcher listener. */
    private array $bridged = [];

    private ?string $owner = null;

    public function __construct(private readonly Container $container) {}

    public function addAction(string $name, callable $callback, int $priority = 10): void
    {
        $this->add($this->actions, $name, $callback, $priority);

        if (! isset($this->bridged[$name])) {
            $this->bridged[$name] = true;

            // One dispatcher listener per name runs the registry's callbacks
            // in priority order, which Laravel's dispatcher can't do itself.
            // Returning null keeps later listeners running.
            $this->events()->listen($name, function (mixed ...$payload) use ($name) {
                $this->run($this->actions[$name] ?? [], array_values($payload));

                return null;
            });
        }
    }

    public function addFilter(string $name, callable $callback, int $priority = 10): void
    {
        $this->add($this->filters, $name, $callback, $priority);
    }

    public function doAction(string $name, mixed ...$args): void
    {
        $this->events()->dispatch($name, [...array_values($args), $this->site()]);
    }

    public function applyFilters(string $name, mixed $value, mixed ...$args): mixed
    {
        if (empty($this->filters[$name])) {
            return $value;
        }

        $args = [...array_values($args), $this->site()];

        foreach ($this->ordered($this->filters[$name]) as $entry) {
            $value = ($entry['callback'])(...array_slice([$value, ...$args], 0, $entry['arity']));
        }

        return $value;
    }

    /**
     * Removes a callback. Without a priority, removes it at every priority.
     */
    public function removeAction(string $name, callable $callback, ?int $priority = null): bool
    {
        return $this->remove($this->actions, $name, $callback, $priority);
    }

    public function removeFilter(string $name, callable $callback, ?int $priority = null): bool
    {
        return $this->remove($this->filters, $name, $callback, $priority);
    }

    public function hasAction(string $name): bool
    {
        if (! empty($this->actions[$name])) {
            return true;
        }

        $events = $this->events();

        if (! $events instanceof EventDispatcher) {
            return $events->hasListeners($name);
        }

        // Our own dispatcher listener stays behind after its callbacks are
        // removed, so it doesn't count.
        return count($events->getListeners($name)) > (isset($this->bridged[$name]) ? 1 : 0);
    }

    public function hasFilter(string $name): bool
    {
        return ! empty($this->filters[$name]);
    }

    /**
     * The components modules add to a place on an admin page, for the
     * page's `<HookSlot name="…">` to render. A view slot is a filter over
     * a list of `{component, props, src}` descriptors, so modules add to it
     * with addToSlot() or addFilter(), and it gets the page's $context.
     *
     * Controllers pass the result as the `hookSlots` prop, keyed by slot
     * name. `component` is a name the front end's hook component registry
     * knows, or `slug::Export` for a module component loaded from `src`.
     *
     * @param  array<string, mixed>  $context
     * @return list<array{component: string, props: array<mixed>, src: string|null}>
     */
    public function viewSlot(string $name, array $context = []): array
    {
        $items = $this->applyFilters($name, [], $context);

        if (! is_array($items)) {
            throw new UnexpectedValueException("View slot [{$name}] must be a list of components, a filter returned ".get_debug_type($items).'.');
        }

        return array_map(fn (mixed $item) => $this->slotItem($name, $item), array_values($items));
    }

    /**
     * Adds a component to a view slot. $props can be a closure taking the
     * slot's context and the site; returning null leaves the component out,
     * e.g. for pages the module doesn't apply to.
     *
     * @param  array<string, mixed>|(Closure(array<string, mixed>, Site|null): (array<string, mixed>|null))  $props
     */
    public function addToSlot(string $name, string $component, array|Closure $props = [], int $priority = 10, ?string $src = null): void
    {
        $this->addFilter($name, function (array $items, array $context, ?Site $site) use ($component, $props, $src) {
            $props = $props instanceof Closure ? $props($context, $site) : $props;

            return $props === null ? $items : [...$items, ['component' => $component, 'props' => $props, 'src' => $src]];
        }, $priority);
    }

    /**
     * @return array{component: string, props: array<mixed>, src: string|null}
     */
    private function slotItem(string $slot, mixed $item): array
    {
        if (! is_array($item) || ! is_string($item['component'] ?? null) || $item['component'] === '') {
            throw new UnexpectedValueException("View slot [{$slot}] got an item without a component name.");
        }

        $props = $item['props'] ?? [];
        $src = $item['src'] ?? null;

        if (! is_array($props) || ($src !== null && ! is_string($src))) {
            throw new UnexpectedValueException("View slot [{$slot}] got bad props or src for [{$item['component']}].");
        }

        return ['component' => $item['component'], 'props' => $props, 'src' => $src];
    }

    /**
     * Runs $callback with every hook it adds attributed to $owner, a
     * module's slug. The module loader wraps each module's provider in this.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function asOwner(string $owner, callable $callback): mixed
    {
        $previous = $this->owner;
        $this->owner = $owner;

        try {
            return $callback();
        } finally {
            $this->owner = $previous;
        }
    }

    /**
     * Drops every callback $owner added, e.g. when its module fails to
     * boot and is switched off for the rest of the request.
     */
    public function removeOwnedBy(string $owner): void
    {
        $this->actions = $this->without($this->actions, $owner);
        $this->filters = $this->without($this->filters, $owner);
    }

    /**
     * @param  array<string, array<int, list<array{callback: callable, owner: string|null, arity: int}>>>  $hooks
     * @return array<string, array<int, list<array{callback: callable, owner: string|null, arity: int}>>>
     */
    private function without(array $hooks, string $owner): array
    {
        foreach ($hooks as $name => $byPriority) {
            foreach ($byPriority as $priority => $entries) {
                $hooks[$name][$priority] = array_values(array_filter(
                    $entries,
                    fn (array $entry) => $entry['owner'] !== $owner,
                ));
            }
        }

        return $this->prune($hooks);
    }

    /**
     * Drops emptied priorities and names, so has*() stays accurate.
     *
     * @param  array<string, array<int, list<array{callback: callable, owner: string|null, arity: int}>>>  $hooks
     * @return array<string, array<int, list<array{callback: callable, owner: string|null, arity: int}>>>
     */
    private function prune(array $hooks): array
    {
        foreach ($hooks as $name => $byPriority) {
            $hooks[$name] = array_filter($byPriority);

            if ($hooks[$name] === []) {
                unset($hooks[$name]);
            }
        }

        return $hooks;
    }

    /**
     * @param  array<string, array<int, list<array{callback: callable, owner: string|null, arity: int}>>>  $hooks
     */
    private function add(array &$hooks, string $name, callable $callback, int $priority): void
    {
        $hooks[$name][$priority][] = [
            'callback' => $callback,
            'owner' => $this->owner,
            'arity' => $this->arity($callback),
        ];

        ksort($hooks[$name]);
    }

    /**
     * @param  array<string, array<int, list<array{callback: callable, owner: string|null, arity: int}>>>  $hooks
     */
    private function remove(array &$hooks, string $name, callable $callback, ?int $priority): bool
    {
        $removed = false;

        foreach ($hooks[$name] ?? [] as $at => $entries) {
            if ($priority !== null && $at !== $priority) {
                continue;
            }

            $kept = array_values(array_filter($entries, fn (array $entry) => $entry['callback'] !== $callback));
            $removed = $removed || count($kept) !== count($entries);
            $hooks[$name][$at] = $kept;
        }

        $hooks = $this->prune($hooks);

        return $removed;
    }

    /**
     * @param  array<int, list<array{callback: callable, owner: string|null, arity: int}>>  $byPriority
     * @param  array<int, mixed>  $args
     */
    private function run(array $byPriority, array $args): void
    {
        foreach ($this->ordered($byPriority) as $entry) {
            ($entry['callback'])(...array_slice($args, 0, $entry['arity']));
        }
    }

    /**
     * A snapshot, so callbacks that add or remove hooks don't change the
     * run in progress.
     *
     * @param  array<int, list<array{callback: callable, owner: string|null, arity: int}>>  $byPriority
     * @return list<array{callback: callable, owner: string|null, arity: int}>
     */
    private function ordered(array $byPriority): array
    {
        return array_merge(...array_values($byPriority));
    }

    /**
     * How many arguments to pass. PHP ignores extra arguments to userland
     * functions, but a built-in like trim() would take the site as its
     * optional second parameter, or reject it.
     */
    private function arity(callable $callback): int
    {
        $reflection = new ReflectionFunction(Closure::fromCallable($callback));

        return $reflection->isInternal() && ! $reflection->isVariadic()
            ? max(1, $reflection->getNumberOfRequiredParameters())
            : PHP_INT_MAX;
    }

    private function site(): ?Site
    {
        $current = $this->container->make(CurrentSite::class);

        return $current->has() ? $current->get() : null;
    }

    /**
     * Resolved on every call, so Event::fake() in a test sees actions
     * dispatched by a registry built before the fake.
     */
    private function events(): Dispatcher
    {
        return $this->container->make('events');
    }
}

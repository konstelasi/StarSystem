<?php

namespace App\Frontend;

use App\Models\SchemaModel;
use StarDust\Exception\InvalidCursorException;

/**
 * Turns an address on the public site into what it shows, using the
 * address table and nothing else. It decides nothing about how a page
 * looks.
 *
 * With the table
 *
 *     Pages  (no address, home page "home")      Posts  (address "blog", with a list)
 *
 * "" is the Pages entry "home", "about" is the Pages entry "about", "blog"
 * is a list of Posts and "blog/hello" is the Posts entry "hello".
 */
class FrontendRouter
{
    /** Most segments an address can have: the model's address and a slug. */
    private const MOST_SEGMENTS = 2;

    /** How many entries a list shows at once. */
    public const LIST_SIZE = 12;

    public function __construct(
        private readonly FrontendSettings $settings,
        private readonly PublicEntries $public,
    ) {}

    public function resolve(string $path, ?string $cursor = null): FrontendMatch
    {
        $routes = $this->settings->routes();

        if ($routes === []) {
            return $this->isHome($path) ? new FrontendMatch(FrontendMatch::HOME) : FrontendMatch::none();
        }

        $models = SchemaModel::query()->whereIn('id', array_map(fn (FrontendRoute $route) => $route->modelId, $routes))->get()->keyBy('id');
        $root = $this->first($routes, fn (FrontendRoute $route) => $route->isRoot());

        if ($this->isHome($path)) {
            return $this->home($root, $root === null ? null : $models[$root->modelId] ?? null);
        }

        $segments = explode('/', trim($path, '/'));

        if (count($segments) > self::MOST_SEGMENTS || array_filter($segments, $this->isSlug(...)) !== $segments) {
            return FrontendMatch::none();
        }

        if (count($segments) === 2) {
            $route = $this->first($routes, fn (FrontendRoute $route) => $route->path === $segments[0]);

            return $route === null ? FrontendMatch::none() : $this->entry($route, $models[$route->modelId] ?? null, $segments[1]);
        }

        $list = $this->first($routes, fn (FrontendRoute $route) => $route->path === $segments[0] && $route->list);

        if ($list !== null) {
            return $this->list($list, $models[$list->modelId] ?? null, $cursor);
        }

        return $root === null ? FrontendMatch::none() : $this->entry($root, $models[$root->modelId] ?? null, $segments[0]);
    }

    private function home(?FrontendRoute $root, ?SchemaModel $model): FrontendMatch
    {
        if ($root === null || $model === null) {
            return new FrontendMatch(FrontendMatch::HOME);
        }

        $entry = $root->homeSlug === null ? null : $this->public->bySlug($model, $root, $root->homeSlug);

        return new FrontendMatch(FrontendMatch::HOME, $root, $model, $entry);
    }

    private function entry(FrontendRoute $route, ?SchemaModel $model, string $slug): FrontendMatch
    {
        $entry = $model === null ? null : $this->public->bySlug($model, $route, $slug);

        return $entry === null ? FrontendMatch::none() : new FrontendMatch(FrontendMatch::ENTRY, $route, $model, $entry);
    }

    private function list(FrontendRoute $route, ?SchemaModel $model, ?string $cursor): FrontendMatch
    {
        if ($model === null) {
            return FrontendMatch::none();
        }

        try {
            $page = $this->public->page($model, $route, $cursor, self::LIST_SIZE);
        } catch (InvalidCursorException) {
            // A bookmark from before the entries changed, or a made-up one:
            // the first page is the answer.
            $page = $this->public->page($model, $route, null, self::LIST_SIZE);
        }

        return new FrontendMatch(FrontendMatch::LIST, $route, $model, page: $page, next: $page->nextCursor?->opaque);
    }

    private function isHome(string $path): bool
    {
        return trim($path, '/') === '';
    }

    /**
     * A segment of an address or an entry's slug: lowercase words joined
     * by single hyphens. Anything else can't be a slug, so StarDust is
     * never asked about it.
     */
    private function isSlug(string $segment): bool
    {
        return strlen($segment) <= 120 && preg_match(FrontendSettings::ADDRESS_PATTERN, $segment) === 1;
    }

    /**
     * @param  list<FrontendRoute>  $routes
     * @param  callable(FrontendRoute): bool  $test
     */
    private function first(array $routes, callable $test): ?FrontendRoute
    {
        foreach ($routes as $route) {
            if ($test($route)) {
                return $route;
            }
        }

        return null;
    }
}

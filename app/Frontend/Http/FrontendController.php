<?php

namespace App\Frontend\Http;

use App\Frontend\AddressConflicts;
use App\Frontend\EntryPresenter;
use App\Frontend\FrontendMatch;
use App\Frontend\FrontendRouter;
use App\Frontend\Theme;
use App\Frontend\ThemeManager;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Sites\CurrentSite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use LogicException;

/**
 * The site's public pages. It asks FrontendRouter what an address leads to,
 * turns the answer into the variables a theme's templates get and renders
 * the theme's template for it. Everything about how a page looks is the
 * theme's.
 *
 * These routes carry no session, cookies or CSRF, so a page is the same for
 * every visitor and can be kept for the next one.
 */
class FrontendController extends Controller
{
    public function __construct(
        private readonly FrontendRouter $router,
        private readonly ThemeManager $themes,
        private readonly EntryPresenter $presenter,
        private readonly AddressConflicts $conflicts,
        private readonly CurrentSite $site,
    ) {}

    public function home(Request $request): Response
    {
        return $this->respond($request, '');
    }

    /**
     * Any address no other route answers. The folders and prefixes StarSystem
     * keeps for itself get the framework's plain 404, so a mistyped admin
     * address doesn't look like a page of the public site.
     */
    public function show(Request $request): Response
    {
        $path = trim($request->path(), '/');

        abort_if($this->conflicts->reserved($path), 404);

        return $this->respond($request, $path);
    }

    private function respond(Request $request, string $path): Response
    {
        $cursor = $request->query('after');
        $match = $this->router->resolve($path, is_string($cursor) && $cursor !== '' ? $cursor : null);
        $site = $this->site->get();
        $theme = $this->themes->active($site);

        // Replaced, not added to, so a second theme in one process (a test,
        // a queue worker) never sees the first one's templates.
        View::replaceNamespace('theme', [$theme->viewsPath()]);
        View::flushFinderCache();

        [$file, $data] = $this->variables($match, $theme, $site);

        // The template is rendered from its file. The layout and the partials
        // it includes are found through the "theme" namespace above.
        $html = view()->file($file, [
            'site' => ['name' => $site->name, 'locale' => $site->locale ?: config()->string('app.locale'), 'url' => url('/')],
            'theme' => $theme,
            'menu' => $this->menu($match),
            ...$data,
        ])->render();

        return response($html, $match->found() ? 200 : 404, [
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);
    }

    /**
     * The file of the template to render and the variables that are its own.
     *
     * @return array{string, array<string, mixed>}
     */
    private function variables(FrontendMatch $match, Theme $theme, Site $site): array
    {
        $title = fn (string $page) => $page.' | '.$site->name;

        if ($match->kind === FrontendMatch::HOME) {
            $entry = $match->entry === null ? null : $this->presenter->present($match->entry, $match->model, $match->route);

            return [$this->template($theme, ['home']), ['entry' => $entry, 'page' => $this->page($site->name, url('/'))]];
        }

        if ($match->kind === FrontendMatch::ENTRY) {
            $entry = $this->presenter->present($match->entry, $match->model, $match->route);

            return [
                $this->template($theme, ['entry-'.$match->model->slug, 'entry']),
                [
                    'entry' => $entry,
                    'model' => ['slug' => $match->model->slug, 'label' => $match->model->label],
                    'page' => $this->page($title($entry->title), $entry->url),
                ],
            ];
        }

        if ($match->kind === FrontendMatch::LIST) {
            $address = url($match->route->path);

            return [
                $this->template($theme, ['list-'.$match->model->slug, 'list']),
                [
                    'entries' => array_map(fn ($row) => $this->presenter->present($row, $match->model, $match->route), $match->page->rows),
                    'model' => ['slug' => $match->model->slug, 'label' => $match->model->label],
                    'route' => ['label' => $match->route->label, 'path' => $match->route->path],
                    'next' => $match->next === null ? null : $address.'?after='.rawurlencode($match->next),
                    'page' => $this->page($title($match->route->label), $address),
                ],
            ];
        }

        return [$this->template($theme, ['404']), ['page' => $this->page($title('Page not found'), '')]];
    }

    /**
     * @return array{title: string, description: string, url: string}
     */
    private function page(string $title, string $url): array
    {
        return ['title' => $title, 'description' => '', 'url' => $url];
    }

    /**
     * The home link and a link for each published model that has a list.
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function menu(FrontendMatch $match): array
    {
        $items = [['label' => 'Home', 'url' => url('/'), 'current' => $match->kind === FrontendMatch::HOME]];

        foreach ($match->routes as $route) {
            if ($route->list && ! $route->isRoot()) {
                $items[] = [
                    'label' => $route->label,
                    'url' => url($route->path),
                    'current' => $match->kind === FrontendMatch::LIST && $match->route?->path === $route->path,
                ];
            }
        }

        return $items;
    }

    /**
     * The file of the first of the templates the theme has. A theme that
     * lacks the last one is broken, and says so in the log instead of
     * showing a blank page.
     *
     * @param  non-empty-list<string>  $candidates  Most specific first.
     */
    private function template(Theme $theme, array $candidates): string
    {
        foreach ($candidates as $name) {
            $file = $theme->templatePath($name);

            if ($file !== null) {
                return $file;
            }
        }

        throw new LogicException("The theme [{$theme->name}] has no [".end($candidates).'] template.');
    }
}

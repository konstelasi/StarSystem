<?php

namespace App\Http\Middleware;

use App\Access\SiteAccess;
use App\Health\TickStatus;
use App\Models\SchemaModel;
use App\Sites\CurrentSite;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'installOwner' => $request->user()?->is_install_owner === true,
                'role' => fn () => $this->roleName($request),
                'abilities' => fn () => $this->abilities($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'backgroundWork' => fn () => $this->backgroundWork($request),
            'navModels' => fn () => $this->navModels($request),
        ];
    }

    /**
     * The name of the role the account holds on this site, "Owner" for the
     * install owner. Null for guests and on pages without a site.
     */
    private function roleName(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null || ! app(CurrentSite::class)->has()) {
            return null;
        }

        return $user->is_install_owner ? 'Owner' : app(SiteAccess::class)->role($user)?->name;
    }

    /**
     * What the account may do on this site, for the front end to hide what it
     * can't use. The server checks every action again.
     *
     * @return list<string>
     */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        if ($user === null || ! app(CurrentSite::class)->has()) {
            return [];
        }

        return app(SiteAccess::class)->abilities($user);
    }

    /**
     * Set when StarDust's background work is late, for the banner on every
     * admin page. Null for guests, so public pages never query for it.
     *
     * @return array{minutesSinceLast: int|null}|null
     */
    private function backgroundWork(Request $request): ?array
    {
        if ($request->user() === null) {
            return null;
        }

        $ticks = app(TickStatus::class);

        return $ticks->late() ? ['minutesSinceLast' => $ticks->minutesSinceLast()] : null;
    }

    /**
     * The current site's models, for the sidebar to list under Content and
     * under each model group. Models being deleted are left out. Empty for
     * guests, so public pages never query for it.
     *
     * @return list<array{id: int, label: string, group: string|null}>
     */
    private function navModels(Request $request): array
    {
        if ($request->user() === null || ! app(CurrentSite::class)->has()) {
            return [];
        }

        $models = SchemaModel::query()
            ->where('status', SchemaModel::ACTIVE)
            ->orderBy('group')
            ->orderBy('label')
            ->get(['id', 'label', 'group']);

        $rows = [];

        foreach ($models as $model) {
            $rows[] = ['id' => $model->id, 'label' => $model->label, 'group' => $model->group];
        }

        return $rows;
    }
}

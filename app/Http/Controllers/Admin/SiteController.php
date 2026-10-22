<?php

namespace App\Http\Controllers\Admin;

use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Frontend\FrontendRoute;
use App\Frontend\FrontendSettings;
use App\Frontend\ThemeManager;
use App\Http\Controllers\Controller;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Sites\CurrentSite;
use App\StarDust\StarDustService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the site looks and what it publishes: the theme it shows and the
 * address table that says which models are public and where.
 */
class SiteController extends Controller
{
    public function __construct(
        private readonly ThemeManager $themes,
        private readonly FrontendSettings $settings,
        private readonly CurrentSite $site,
        private readonly StarDustService $stardust,
        private readonly ActivityLog $activity,
    ) {}

    public function index(Request $request): Response
    {
        $site = $this->site->get();

        return Inertia::render('admin/site/Index', [
            'themes' => collect($this->themes->installed())->map(fn ($theme, $folder) => [
                'name' => $folder,
                'title' => $theme->manifest->name,
                'version' => $theme->manifest->version,
                'description' => $theme->manifest->description,
                'author' => $theme->manifest->author,
            ])->values(),
            'broken' => collect($this->themes->problems())->map(fn ($message, $folder) => ['folder' => $folder, 'message' => $message])->values(),
            'theme' => $site->theme !== null && $this->themes->find($site->theme) !== null ? $site->theme : null,
            'defaultTheme' => config()->string('starsystem.themes.default'),
            'routes' => array_map(fn (FrontendRoute $route) => $route->toArray(), $this->settings->routes()),
            'models' => $this->models(),
            'editable' => $request->user()?->can('site.update') ?? false,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'theme' => ['nullable', 'string', 'max:64'],
            'routes' => ['present', 'array', 'max:50'],
            'routes.*' => ['array'],
        ]);

        $site = $this->site->get();
        $theme = $request->string('theme')->toString();
        $rows = $request->array('routes');
        $errors = [];

        if ($theme !== '' && $this->themes->find($theme) === null) {
            $errors['theme'] = 'Pick one of the installed themes.';
        }

        foreach ($this->settings->problems($rows) as $position => $messages) {
            $errors["routes.{$position}"] = implode(' ', $messages);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $before = ['theme' => $site->theme, 'addresses' => array_map(fn (FrontendRoute $route) => $route->toArray(), $this->settings->routes())];

        $site->update(['theme' => $theme === '' ? null : $theme]);
        $this->settings->save($rows);

        $after = array_map(fn (FrontendRoute $route) => $route->toArray(), $this->settings->routes());

        $changes = [
            ...($before['theme'] === $site->theme ? [] : ['theme' => $site->theme ?? config()->string('starsystem.themes.default')]),
            ...($before['addresses'] === $after ? [] : ['addresses' => array_map(fn (array $route) => '/'.$route['path'], $after)]),
        ];

        if ($changes !== []) {
            $this->activity->record(ActivityAction::SITE_UPDATED, null, $changes);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Site saved.']);

        return to_route('admin.site.index');
    }

    /**
     * The site's models with the fields a row can use, and whether each
     * field is indexed yet, which a row needs before it can be saved.
     *
     * @return array<int, array<string, mixed>>
     */
    private function models(): array
    {
        $models = SchemaModel::query()->where('status', SchemaModel::ACTIVE)->orderBy('label')->get();
        $fields = SchemaField::query()->whereIn('model_id', $models->pluck('id'))->orderBy('position')->get()->groupBy('model_id');

        return $models->map(function (SchemaModel $model) use ($fields) {
            $described = $this->stardust->describeModel($model->stardust_model_id);

            return [
                'id' => $model->id,
                'label' => $model->label,
                'slug' => $model->slug,
                'fields' => ($fields[$model->id] ?? collect())->map(fn (SchemaField $field) => [
                    'uuid' => $field->id,
                    'label' => $field->label,
                    'type' => $field->type,
                    'filterable' => $field->filterable,
                    'required' => $field->required,
                    'indexed' => $described?->field($field->key)->isIndexed ?? false,
                ])->values()->all(),
            ];
        })->values()->all();
    }
}

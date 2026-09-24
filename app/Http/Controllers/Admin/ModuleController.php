<?php

namespace App\Http\Controllers\Admin;

use App\Hooks\Hook;
use App\Http\Controllers\Controller;
use App\Modules\ModuleException;
use App\Modules\ModuleManager;
use App\Modules\SafeMode;
use App\Modules\ZipInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin's modules pages. Everything here can run a module's PHP, so
 * the routes need the install's owner and a recently confirmed password.
 */
class ModuleController extends Controller
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function index(SafeMode $safeMode): Response
    {
        return Inertia::render('admin/modules/Index', [
            'modules' => $this->modules->overview(),
            'safeMode' => $safeMode->reason(),
            'coreVersion' => $this->modules->coreVersion(),
            'hookSlots' => [
                'backend.view:modules:index' => Hook::viewSlot('backend.view:modules:index'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/modules/Upload', [
            'zipSupported' => ZipInstaller::supported(),
            'maxKilobytes' => config()->integer('modules.upload_max_kb'),
        ]);
    }

    public function store(Request $request, ZipInstaller $installer): RedirectResponse
    {
        $request->validate([
            'module' => ['required', 'file', 'extensions:zip', 'max:'.config()->integer('modules.upload_max_kb')],
        ]);

        try {
            ['manifest' => $manifest, 'upgradedFrom' => $from] = $installer->install((string) $request->file('module')?->getRealPath());
        } catch (ModuleException $e) {
            return back()->withErrors(['module' => $e->getMessage()]);
        }

        return $this->done($from === null
            ? "{$manifest->name} {$manifest->version} is installed. Enable it to start using it."
            : "{$manifest->name} was upgraded from {$from} to {$manifest->version}.");
    }

    public function enable(string $slug): RedirectResponse
    {
        return $this->attempt(function () use ($slug) {
            $module = $this->modules->enable($slug);

            return "{$module->name} is enabled.";
        });
    }

    public function disable(string $slug): RedirectResponse
    {
        $this->modules->disable($slug);

        return $this->done('The module is disabled.');
    }

    public function update(string $slug): RedirectResponse
    {
        return $this->attempt(function () use ($slug) {
            $this->modules->applyUpdate($slug);

            return 'The module is up to date.';
        });
    }

    public function dismiss(string $slug): RedirectResponse
    {
        $this->modules->dismissError($slug);

        return to_route('admin.modules.index');
    }

    public function destroy(Request $request, string $slug): RedirectResponse
    {
        $deleteData = $request->boolean('delete_data');

        return $this->attempt(function () use ($slug, $deleteData) {
            $this->modules->uninstall($slug, $deleteData);

            return $deleteData ? 'The module and its data were removed.' : 'The module was removed. Its data was kept.';
        });
    }

    /**
     * @param  callable(): string  $action
     */
    private function attempt(callable $action): RedirectResponse
    {
        try {
            return $this->done($action());
        } catch (ModuleException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('admin.modules.index');
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.modules.index');
    }
}

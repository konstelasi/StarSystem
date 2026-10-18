<?php

namespace Modules\Forms;

use App\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * A module that adds two abilities and guards a page with each, the way a
 * real module should.
 */
class FormsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'site.member', $this->can('view')])
            ->get('_forms-test/submissions', fn () => 'the submissions');

        Route::middleware(['web', 'auth', 'site.member', $this->can('export')])
            ->get('_forms-test/export', fn () => 'the export');
    }
}

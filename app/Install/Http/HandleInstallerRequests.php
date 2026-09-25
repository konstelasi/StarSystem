<?php

namespace App\Install\Http;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia for the installer. It shares nothing that needs the database,
 * a session or a user, none of which exist yet.
 */
class HandleInstallerRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => 'StarSystem',
            'auth' => ['user' => null],
        ];
    }
}

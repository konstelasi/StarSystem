<?php

namespace App\Install\Http;

use App\Http\Controllers\Controller;
use App\Install\DatabaseCredentials;
use App\Install\DatabaseProbe;
use App\Install\Installer;
use App\Install\Requirements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * The web installer's pages and steps.
 *
 * There is no session, so a form with errors is answered by rendering
 * its page again with the errors as props, instead of the usual redirect
 * back with flashed errors.
 */
class InstallController extends Controller
{
    public function __construct(
        private readonly Installer $installer,
        private readonly Requirements $requirements,
    ) {}

    public function requirements(): Response
    {
        return Inertia::render('install/Requirements', [
            'checks' => $this->requirements->checks(),
            'passed' => $this->requirements->pass(),
        ]);
    }

    public function database(Request $request): Response|RedirectResponse
    {
        if (! $this->requirements->pass()) {
            return redirect()->route('install');
        }

        return $this->databasePage($request);
    }

    public function saveDatabase(Request $request, DatabaseProbe $probe): Response|RedirectResponse
    {
        if (! $this->requirements->pass()) {
            return redirect()->route('install');
        }

        $validator = Validator::make($request->all(), [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ], [], [
            'host' => 'database server',
            'database' => 'database name',
            'username' => 'database username',
        ]);

        if ($validator->fails()) {
            return $this->databasePage($request, $validator->errors()->toArray());
        }

        $credentials = DatabaseCredentials::fromArray($validator->validated());

        // Once someone has saved working details, only they (or whoever
        // knows the same details) can carry on or change them.
        $saved = $this->installer->savedCredentials();

        if ($saved !== null && ! $this->ownsInstall($request) && ! $saved->sameAs($credentials)) {
            return $this->databasePage($request, ['connection' => [
                'These details don\'t match the ones saved earlier in this install. Enter the same details again, or use your host\'s file manager to delete the ".env" file and start over.',
            ]]);
        }

        $result = $probe->check($credentials);

        if (! $result['ok']) {
            return $this->databasePage($request, ['connection' => [$result['message']]]);
        }

        $token = $this->installer->saveDatabase($credentials, $request->root());

        return redirect()->route('install.setup')->withCookie($this->ownerCookie($request, $token));
    }

    public function setup(Request $request): Response|RedirectResponse
    {
        if (! $this->ownsInstall($request)) {
            return $this->reclaim();
        }

        return $this->setupPage();
    }

    public function migrate(Request $request): Response|RedirectResponse
    {
        return $this->runTask($request, 'migrate', fn () => $this->installer->migrate());
    }

    public function stardust(Request $request): Response|RedirectResponse
    {
        return $this->runTask($request, 'stardust', function () {
            if (! $this->installer->migrated()) {
                $this->installer->migrate();
            }

            $this->installer->bootstrapStarDust();
        });
    }

    public function admin(Request $request): Response|RedirectResponse
    {
        if (! $this->ownsInstall($request)) {
            return $this->reclaim();
        }

        if (! $this->installer->migrated() || ! $this->installer->starDustReady()) {
            return redirect()->route('install.setup');
        }

        return $this->adminPage($request);
    }

    public function finish(Request $request): Response|SymfonyResponse
    {
        if (! $this->ownsInstall($request)) {
            return $this->reclaim();
        }

        if (! $this->installer->migrated() || ! $this->installer->starDustReady()) {
            return redirect()->route('install.setup');
        }

        $validator = Validator::make($request->all(), [
            'site_name' => ['required', 'string', 'max:255'],
            'app_url' => ['required', 'url:http,https', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ], [], [
            'site_name' => 'site name',
            'app_url' => 'site address',
            'name' => 'your name',
        ]);

        if ($validator->fails()) {
            return $this->adminPage($request, $validator->errors()->toArray());
        }

        $input = $validator->validated();

        $done = $this->installer->finish(
            siteName: $input['site_name'],
            appUrl: $input['app_url'],
            name: $input['name'],
            email: strtolower($input['email']),
            password: $input['password'],
        );

        // Rendered here rather than redirected to: from now on /install is
        // gone, and this is the only time the tick URL's key is shown.
        $response = Inertia::render('install/Done', [
            ...$done,
            'email' => strtolower($input['email']),
        ])->toResponse($request);

        $response->headers->setCookie(Cookie::create(Installer::OWNER_COOKIE)->withExpires(1)->withPath('/install'));

        return $response;
    }

    /**
     * @param  callable(): void  $work
     */
    private function runTask(Request $request, string $task, callable $work): Response|RedirectResponse
    {
        if (! $this->ownsInstall($request)) {
            return $this->reclaim();
        }

        try {
            $work();
        } catch (Throwable $e) {
            Log::error("StarSystem install step [{$task}] failed.", ['exception' => $e]);

            return $this->setupPage(['task' => $task, 'message' => $e->getMessage()]);
        }

        return redirect()->route('install.setup');
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function databasePage(Request $request, array $errors = []): Response
    {
        $saved = $this->installer->savedCredentials();

        return Inertia::render('install/Database', [
            'defaults' => [
                'host' => $saved->host ?? 'localhost',
                'port' => $saved->port ?? 3306,
                'database' => $saved->database ?? '',
                'username' => $saved->username ?? '',
            ],
            'reclaim' => $request->boolean('reclaim'),
            'errors' => $this->firstErrors($errors),
        ]);
    }

    /**
     * @param  array{task: string, message: string}|null  $failure
     */
    private function setupPage(?array $failure = null): Response
    {
        $migrated = $this->installer->migrated();

        return Inertia::render('install/Setup', [
            'tasks' => [
                'migrate' => $migrated,
                'stardust' => $migrated && $this->installer->starDustReady(),
            ],
            'failure' => $failure,
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function adminPage(Request $request, array $errors = []): Response
    {
        return Inertia::render('install/Admin', [
            'defaults' => [
                'site_name' => 'My StarSystem site',
                'app_url' => $request->root(),
            ],
            'errors' => $this->firstErrors($errors),
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function firstErrors(array $errors): object
    {
        return (object) array_map(fn (array $messages) => $messages[0], $errors);
    }

    private function ownsInstall(Request $request): bool
    {
        return $this->installer->owns($request->cookies->get(Installer::OWNER_COOKIE));
    }

    private function reclaim(): RedirectResponse
    {
        return redirect()->route('install.database', ['reclaim' => 1]);
    }

    /**
     * Plain, not encrypted: there is no APP_KEY to encrypt with. It only
     * carries a random token whose hash is on disk, and SameSite=Strict
     * keeps other sites from riding on it.
     */
    private function ownerCookie(Request $request, string $token): Cookie
    {
        return Cookie::create(Installer::OWNER_COOKIE, $token)
            ->withPath('/install')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }
}

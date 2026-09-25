<?php

namespace Tests\Feature\Install;

use App\Install\DatabaseProbe;
use App\Install\EnvironmentFile;
use App\Install\Installation;
use App\Install\Installer;
use App\Models\Site;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\Fakes\ServerVersionPdo;
use Tests\TestCase;

/**
 * The installer over HTTP, with APP_KEY emptied and the .env file and the
 * marker redirected into a temporary folder.
 */
class InstallFlowTest extends TestCase
{
    private string $dir;

    private bool $wiped = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/starsystem-install-'.bin2hex(random_bytes(4));
        mkdir($this->dir);

        config(['app.key' => '']);

        $this->app->instance(Installation::class, new Installation("{$this->dir}/installed", "{$this->dir}/.env"));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        // The full install wipes the database; later RefreshDatabase tests
        // must migrate it again rather than trust the earlier migration.
        if ($this->wiped) {
            Artisan::call('db:wipe', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        }

        parent::tearDown();
    }

    /**
     * @return array<string, string|int>
     */
    private function credentials(array $overrides = []): array
    {
        return [
            'host' => config('database.connections.mysql.host'),
            'port' => (int) config('database.connections.mysql.port'),
            'database' => config('database.connections.mysql.database'),
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
            ...$overrides,
        ];
    }

    private function saveDatabase(): string
    {
        $response = $this->post('/install/database', $this->credentials())
            ->assertRedirect(route('install.setup'));

        $cookie = $response->getCookie(Installer::OWNER_COOKIE, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        return (string) $cookie->getValue();
    }

    private function asOwner(string $token): static
    {
        return $this->withUnencryptedCookie(Installer::OWNER_COOKIE, $token);
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        return Dotenv::parse((string) file_get_contents("{$this->dir}/.env"));
    }

    public function test_every_page_goes_to_the_installer_until_it_is_installed()
    {
        $this->get('/')->assertRedirect('/install');
        $this->get('/dashboard')->assertRedirect('/install');
        $this->get('/_system/tick')->assertRedirect('/install');
    }

    public function test_the_requirements_page_renders_without_a_session()
    {
        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/Requirements')
                ->where('passed', true)
                ->has('checks')
            )
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');
    }

    public function test_missing_fields_are_shown_on_the_form()
    {
        $this->post('/install/database', ['host' => 'localhost', 'port' => 3306])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/Database')
                ->where('errors.database', 'The database name field is required.')
                ->where('errors.username', 'The database username field is required.')
            );

        $this->assertFileDoesNotExist("{$this->dir}/.env");
    }

    public function test_a_rejected_login_is_explained_and_nothing_is_saved()
    {
        $this->post('/install/database', $this->credentials(['password' => 'wrong']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/Database')
                ->where('errors.connection', fn (string $message) => str_contains($message, 'password was not accepted'))
            );

        $this->assertFileDoesNotExist("{$this->dir}/.env");
    }

    public function test_a_database_server_that_is_too_old_is_refused()
    {
        $this->app->instance(DatabaseProbe::class, new DatabaseProbe(fn () => new ServerVersionPdo('10.6.18-MariaDB')));

        $this->post('/install/database', $this->credentials())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('errors.connection', fn (string $message) => str_contains($message, 'too old for StarSystem')
                    && str_contains($message, 'MariaDB 10.11 or newer'))
            );

        $this->assertFileDoesNotExist("{$this->dir}/.env");
    }

    public function test_saving_the_database_writes_env_with_storage_folders_for_stardust()
    {
        $this->saveDatabase();

        $env = $this->env();

        $this->assertSame(config('database.connections.mysql.database'), $env['DB_DATABASE']);
        $this->assertSame((string) config('database.connections.mysql.password'), $env['DB_PASSWORD']);
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('', $env['APP_KEY'] ?? '');
        $this->assertSame(storage_path('app/stardust/artifacts'), $env['STARDUST_ARTIFACT_DIR']);
        $this->assertSame(storage_path('app/stardust/pids'), $env['STARDUST_PID_DIR']);
        $this->assertStringStartsWith(realpath(storage_path()) ?: storage_path(), realpath($env['STARDUST_PID_DIR']) ?: '');
        $this->assertDirectoryExists($env['STARDUST_ARTIFACT_DIR']);
    }

    public function test_later_steps_belong_to_whoever_saved_the_database()
    {
        $this->saveDatabase();

        $reclaim = route('install.database', ['reclaim' => 1]);

        $this->get('/install/setup')->assertRedirect($reclaim);
        $this->post('/install/setup/migrate')->assertRedirect($reclaim);
        $this->asOwner('guessed')->get('/install/admin')->assertRedirect($reclaim);
        $this->post('/install/admin', ['email' => 'intruder@example.com'])->assertRedirect($reclaim);

        // A stranger can't point the install at another database...
        $this->post('/install/database', $this->credentials(['username' => 'someone_else']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('errors.connection', fn (string $message) => str_contains($message, "don't match the ones saved earlier"))
            );

        // ...but the owner, on another browser, gets back in with the same details.
        $token = $this->saveDatabase();
        $this->asOwner($token)->get('/install/setup')->assertOk();
    }

    public function test_a_failed_step_is_explained_and_can_be_retried()
    {
        $installer = Mockery::mock(Installer::class, [app(Installation::class), app('migrator')])->makePartial();
        $installer->shouldReceive('migrate')->once()->andThrow(new RuntimeException('Disk quota exceeded'));
        $installer->shouldReceive('migrated')->andReturn(false);
        $this->app->instance(Installer::class, $installer);

        $token = $this->saveDatabase();

        $this->asOwner($token)->post('/install/setup/migrate')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/Setup')
                ->where('failure.task', 'migrate')
                ->where('failure.message', 'Disk quota exceeded')
                ->where('tasks.migrate', false)
            );
    }

    public function test_a_full_install_into_the_test_database()
    {
        Artisan::call('db:wipe', ['--force' => true]);
        $this->wiped = true;

        $this->get('/install')->assertOk();
        $this->get('/install/database')->assertOk()->assertInertia(fn (Assert $page) => $page->component('install/Database'));

        $token = $this->saveDatabase();

        $this->asOwner($token)->get('/install/setup')
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/Setup')
                ->where('tasks', ['migrate' => false, 'stardust' => false])
            );

        // The account step waits until the database is ready.
        $this->asOwner($token)->get('/install/admin')->assertRedirect(route('install.setup'));

        $this->asOwner($token)->post('/install/setup/migrate')->assertRedirect(route('install.setup'));
        $this->assertTrue(Schema::hasTable('users'));

        $this->asOwner($token)->post('/install/setup/stardust')->assertRedirect(route('install.setup'));
        $this->asOwner($token)->get('/install/setup')
            ->assertInertia(fn (Assert $page) => $page->where('tasks', ['migrate' => true, 'stardust' => true]));

        // Each task is safe to repeat.
        $this->asOwner($token)->post('/install/setup/migrate')->assertRedirect(route('install.setup'));
        $this->asOwner($token)->post('/install/setup/stardust')->assertRedirect(route('install.setup'));

        $this->asOwner($token)->get('/install/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('install/Admin')
            ->where('defaults.app_url', rtrim((string) config('app.url'), '/'))
        );

        $this->asOwner($token)->post('/install/admin', [
            'site_name' => 'Sunrise Bakery',
            'app_url' => 'https://bakery.example',
            'name' => 'Ada Baker',
            'email' => 'Ada@Bakery.example',
            'password' => 'A-long-Passw0rd!',
            'password_confirmation' => 'A-long-Passw0rd!',
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/Done')
                ->where('cron', fn (string $cron) => str_contains($cron, base_path('artisan').' schedule:run'))
                ->where('tickUrl', fn (string $url) => str_starts_with($url, 'https://bakery.example/_system/tick?key='))
                ->where('loginUrl', 'https://bakery.example/login')
            );

        $user = User::sole();
        $this->assertSame('ada@bakery.example', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(password_verify('A-long-Passw0rd!', $user->password));

        $site = Site::findOrFail(1);
        $this->assertSame('Sunrise Bakery', $site->name);
        $this->assertSame(['bakery.example'], $site->domains);

        $env = $this->env();
        $this->assertStringStartsWith('base64:', $env['APP_KEY']);
        $this->assertSame(48, strlen($env['STARDUST_TICK_SECRET']));
        $this->assertSame('Sunrise Bakery', $env['APP_NAME']);
        $this->assertSame(config('database.connections.mysql.database'), (new EnvironmentFile("{$this->dir}/.env"))->get('DB_DATABASE'));
        $this->assertFileExists("{$this->dir}/installed");
        $this->assertFileDoesNotExist("{$this->dir}/install-owner");

        // The installer is gone for good, and the site no longer sends
        // visitors to it.
        $this->assertInstallerClosed($this->get('/install'));
        $this->assertInstallerClosed($this->asOwner($token)->post('/install/database', $this->credentials()));
        $this->assertInstallerClosed($this->asOwner($token)->post('/install/admin'));
        $this->get('/')->assertOk();
    }

    public function test_the_installer_is_gone_once_an_app_key_exists()
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertInstallerClosed($this->get('/install'));
        $this->assertInstallerClosed($this->get('/install/database'));
        $this->assertInstallerClosed($this->post('/install/setup/migrate'));
    }

    private function assertInstallerClosed(TestResponse $response): void
    {
        $response->assertNotFound();
    }
}

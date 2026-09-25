<?php

namespace Tests\Feature\Install;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A fresh upload has no .env at all. These run the real front door in a
 * separate PHP process with every setting removed, because inside the test
 * process the environment is already loaded and APP_KEY already set.
 */
class NoEnvironmentBootTest extends TestCase
{
    private string $emptyEnvDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (is_file(storage_path('app/installed'))) {
            $this->markTestSkipped('This checkout has been installed through the web installer.');
        }

        $this->emptyEnvDir = sys_get_temp_dir().'/starsystem-noenv-'.bin2hex(random_bytes(4));
        mkdir($this->emptyEnvDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->emptyEnvDir);

        parent::tearDown();
    }

    /**
     * @return array{status: int, location: ?string, body: string, encrypterResolved: bool, cookies: list<string>}
     */
    private function request(string $uri): array
    {
        // Strip every setting the test run put in the environment, so the
        // child sees only the config files' defaults.
        $env = collect(getenv())
            ->keys()
            ->filter(fn (string $key) => preg_match('/^(APP_|DB_|SESSION_|CACHE_|QUEUE_|MAIL_|LOG_|STARDUST_|STARSYSTEM_|BCRYPT_|BROADCAST_|PULSE_|TELESCOPE_|NIGHTWATCH_)/', $key) === 1)
            ->mapWithKeys(fn (string $key) => [$key => false])
            ->all();

        $process = new Process(
            [PHP_BINARY, base_path('tests/Fixtures/boot-without-env.php'), $this->emptyEnvDir, $uri],
            base_path(),
            $env,
        );
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_first_visit_is_sent_to_the_installer()
    {
        $response = $this->request('/');

        $this->assertSame(302, $response['status']);
        $this->assertStringEndsWith('/install', (string) $response['location']);
        $this->assertFalse($response['encrypterResolved']);
    }

    public function test_the_installer_renders_with_no_env_file_and_no_app_key()
    {
        $response = $this->request('/install');

        $this->assertSame(200, $response['status'], mb_substr($response['body'], 0, 2000));
        $this->assertMatchesRegularExpression('#install\\\\?/Requirements#', $response['body']);
        $this->assertFalse($response['encrypterResolved'], 'Nothing may touch the encrypter before APP_KEY exists.');
        $this->assertSame([], $response['cookies'], 'No session or XSRF cookie before APP_KEY exists.');
    }
}

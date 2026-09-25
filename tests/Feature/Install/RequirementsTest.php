<?php

namespace Tests\Feature\Install;

use App\Install\Installation;
use App\Install\Requirements;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RequirementsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/starsystem-req-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function installation(?string $envPath = null): Installation
    {
        return new Installation("{$this->dir}/installed", $envPath ?? "{$this->dir}/.env");
    }

    /**
     * @return array<string, array{key: string, label: string, ok: bool, help: ?string}>
     */
    private function byKey(Requirements $requirements): array
    {
        return collect($requirements->checks())->keyBy('key')->all();
    }

    public function test_this_machine_meets_every_requirement()
    {
        $requirements = new Requirements($this->installation());

        $failed = collect($requirements->checks())->reject(fn ($check) => $check['ok'])->pluck('label')->all();

        $this->assertSame([], $failed);
        $this->assertTrue($requirements->pass());
    }

    public function test_every_listed_extension_is_checked()
    {
        $checks = $this->byKey(new Requirements($this->installation()));

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'zip', 'tokenizer', 'ctype', 'json'] as $extension) {
            $this->assertArrayHasKey("ext-{$extension}", $checks);
        }

        $this->assertArrayHasKey('storage', $checks);
        $this->assertArrayHasKey('bootstrap-cache', $checks);
    }

    public function test_an_old_php_is_explained()
    {
        $checks = $this->byKey(new Requirements($this->installation(), phpVersion: '8.2.20'));

        $this->assertFalse($checks['php']['ok']);
        $this->assertStringContainsString('This server runs PHP 8.2.20', (string) $checks['php']['help']);
    }

    public function test_a_missing_extension_is_explained()
    {
        $requirements = new Requirements(
            $this->installation(),
            extensionLoaded: fn (string $extension) => $extension !== 'zip',
        );
        $checks = $this->byKey($requirements);

        $this->assertFalse($requirements->pass());
        $this->assertFalse($checks['ext-zip']['ok']);
        $this->assertStringContainsString('Turn on the "zip" extension', (string) $checks['ext-zip']['help']);
        $this->assertTrue($checks['ext-pdo_mysql']['ok']);
        $this->assertNull($checks['ext-pdo_mysql']['help']);
    }

    public function test_unwritable_folders_are_explained()
    {
        $requirements = new Requirements(
            $this->installation(),
            writable: fn (string $path) => ! str_contains(str_replace('\\', '/', $path), 'framework/views'),
        );
        $checks = $this->byKey($requirements);

        $this->assertFalse($checks['storage']['ok']);
        $this->assertStringContainsString('"storage" folder', (string) $checks['storage']['help']);
        $this->assertTrue($checks['bootstrap-cache']['ok']);
    }

    public function test_an_env_file_that_cannot_be_created_is_explained()
    {
        $checks = $this->byKey(new Requirements($this->installation("{$this->dir}/no-such-folder/.env")));

        $this->assertFalse($checks['env']['ok']);
        $this->assertStringContainsString('create an empty file called ".env"', (string) $checks['env']['help']);
    }
}

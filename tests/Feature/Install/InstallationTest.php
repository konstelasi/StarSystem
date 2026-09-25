<?php

namespace Tests\Feature\Install;

use App\Install\Installation;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/starsystem-inst-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_copy_with_no_key_and_no_marker_is_not_installed()
    {
        config(['app.key' => '']);

        $this->assertFalse((new Installation("{$this->dir}/installed", "{$this->dir}/.env"))->installed());
    }

    public function test_an_app_key_alone_counts_as_installed()
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertTrue((new Installation("{$this->dir}/installed", "{$this->dir}/.env"))->installed());
    }

    public function test_the_marker_alone_counts_as_installed()
    {
        config(['app.key' => '']);

        $installation = new Installation("{$this->dir}/app/installed", "{$this->dir}/.env");
        $installation->markInstalled(['version' => '1.0.0']);

        $this->assertTrue($installation->installed());
        $this->assertSame('1.0.0', json_decode((string) file_get_contents("{$this->dir}/app/installed"), true)['version']);
    }
}

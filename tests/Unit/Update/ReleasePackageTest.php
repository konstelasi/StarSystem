<?php

namespace Tests\Unit\Update;

use App\Update\ReleasePackage;
use App\Update\UpdateException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ReleasePackageTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        parent::tearDown();
    }

    public function test_a_well_formed_release_validates(): void
    {
        $package = new ReleasePackage($this->zipOf($this->requiredEntries()));

        $package->validate();

        $this->assertTrue(true);
    }

    public function test_a_release_missing_a_required_file_is_refused(): void
    {
        $entries = $this->requiredEntries();
        unset($entries['starsystem/artisan']);

        $package = new ReleasePackage($this->zipOf($entries));

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('artisan');

        $package->validate();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNames(): iterable
    {
        yield 'escapes with ..' => ['starsystem/../../etc/passwd'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'backslash' => ['starsystem\\..\\evil.php'];
        yield 'another top-level folder' => ['other/evil.php'];
        yield 'bare dot segment' => ['starsystem/./evil.php'];
        yield 'windows drive letter' => ['starsystem/c:evil.php'];
    }

    #[DataProvider('unsafeNames')]
    public function test_an_unsafe_entry_name_is_refused(string $name): void
    {
        $entries = $this->requiredEntries();
        $entries[$name] = 'x';

        $package = new ReleasePackage($this->zipOf($entries));

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage("won't unpack");

        $package->validate();
    }

    public function test_a_symlink_entry_is_refused(): void
    {
        $path = $this->tempFile('.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($this->requiredEntries() as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->addFromString('starsystem/link.php', 'target.php');
        $zip->setExternalAttributesName('starsystem/link.php', ZipArchive::OPSYS_UNIX, (0120777) << 16);
        $zip->close();

        $package = new ReleasePackage($path);

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('symbolic link');

        $package->validate();
    }

    public function test_extraction_can_be_sliced_across_calls(): void
    {
        $entries = $this->requiredEntries();

        for ($i = 0; $i < 20; $i++) {
            $entries["starsystem/app/File{$i}.php"] = "<?php // {$i}";
        }

        $package = new ReleasePackage($this->zipOf($entries));
        $target = $this->tempDir();

        $seen = 0;
        $next = 0;

        do {
            $next = $package->extract($target, $next, seconds: 0.0);
            $seen++;
        } while ($next !== null && $seen < count($entries) + 5);

        $this->assertNull($next);

        foreach ($entries as $name => $content) {
            $path = $target.'/'.$name;
            $this->assertFileExists($path);
            $this->assertSame($content, file_get_contents($path));
        }
    }

    public function test_extraction_in_one_call_yields_everything(): void
    {
        $package = new ReleasePackage($this->zipOf($this->requiredEntries()));
        $target = $this->tempDir();

        $next = $package->extract($target, 0, seconds: 30.0);

        $this->assertNull($next);
        $this->assertFileExists($target.'/starsystem/artisan');
    }

    public function test_platform_problems_reports_a_missing_extension(): void
    {
        $unpacked = $this->tempDir();
        mkdir($unpacked.'/vendor/composer', 0775, true);
        // Mirrors what Composer itself generates (vendor/composer/platform_check.php).
        file_put_contents($unpacked.'/vendor/composer/platform_check.php', <<<'PHP'
            <?php
            $issues = array();
            if (!(PHP_VERSION_ID >= 90000)) {
                $issues[] = 'Your Composer dependencies require a PHP version ">= 9.0.0".';
            }
            if (!extension_loaded('a_missing_extension_no_php_ships')) {
                $issues[] = 'a_missing_extension_no_php_ships is missing from your system.';
            }
            PHP);

        $problems = ReleasePackage::platformProblems($unpacked);

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('PHP 9.0.0', implode(' ', $problems));
        $this->assertStringContainsString('a_missing_extension_no_php_ships', implode(' ', $problems));
    }

    public function test_platform_problems_is_empty_when_there_is_no_check_file(): void
    {
        $this->assertSame([], ReleasePackage::platformProblems($this->tempDir()));
    }

    /**
     * @return array<string, string>
     */
    private function requiredEntries(): array
    {
        $entries = [];

        foreach (ReleasePackage::REQUIRED as $required) {
            $entries[ReleasePackage::ROOT.'/'.$required] = 'x';
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zipOf(array $entries): string
    {
        $path = $this->tempFile('.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }

    private function tempFile(string $suffix): string
    {
        $path = sys_get_temp_dir().'/starsystem-pkg-'.bin2hex(random_bytes(6)).$suffix;
        $this->cleanup[] = $path;

        return $path;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/starsystem-pkg-'.bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->cleanup[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

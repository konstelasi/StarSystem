<?php

namespace Tests\Unit\Frontend;

use App\Frontend\InvalidThemeManifest;
use App\Frontend\ThemeManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThemeManifestTest extends TestCase
{
    public function test_it_reads_a_valid_manifest()
    {
        $manifest = ThemeManifest::fromJson(json_encode([
            'name' => ' Default ',
            'version' => '1.2.3',
            'description' => 'A plain starter theme.',
            'author' => 'Konstelasi',
            'requires' => ['starsystem' => '^0.1'],
        ]));

        $this->assertSame('Default', $manifest->name);
        $this->assertSame('1.2.3', $manifest->version);
        $this->assertSame('A plain starter theme.', $manifest->description);
        $this->assertSame('Konstelasi', $manifest->author);
        $this->assertSame('^0.1', $manifest->requires);
    }

    public function test_the_description_and_author_are_optional()
    {
        $manifest = ThemeManifest::fromJson(json_encode($this->valid()));

        $this->assertSame('', $manifest->description);
        $this->assertSame('', $manifest->author);
    }

    public function test_a_folder_without_a_manifest_is_rejected()
    {
        $this->expectException(InvalidThemeManifest::class);
        $this->expectExceptionMessage('no theme.json');

        ThemeManifest::load(__DIR__);
    }

    public function test_broken_json_is_rejected()
    {
        $this->expectException(InvalidThemeManifest::class);
        $this->expectExceptionMessage("isn't valid JSON");

        ThemeManifest::fromJson('{"name": ');
    }

    public function test_json_that_is_not_an_object_is_rejected()
    {
        $this->expectException(InvalidThemeManifest::class);
        $this->expectExceptionMessage('must be a JSON object');

        ThemeManifest::fromJson('"Default"');
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('brokenFields')]
    public function test_a_field_that_is_wrong_is_rejected(array $change, string $message)
    {
        $this->expectException(InvalidThemeManifest::class);
        $this->expectExceptionMessage($message);

        ThemeManifest::fromJson(json_encode([...$this->valid(), ...$change]));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function brokenFields(): array
    {
        return [
            'no name' => [['name' => ''], '"name" is missing'],
            'a name that is too long' => [['name' => str_repeat('a', 101)], 'up to 100 characters'],
            'no version' => [['version' => null], '"version" is missing'],
            'a version that is not one' => [['version' => 'new'], 'must be a version like 1.2.3'],
            'no requirement' => [['requires' => null], 'requires.starsystem'],
            'a requirement that is not a constraint' => [['requires' => ['starsystem' => 'soon']], 'requires.starsystem'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return ['name' => 'Default', 'version' => '1.0.0', 'requires' => ['starsystem' => '^0.1']];
    }
}

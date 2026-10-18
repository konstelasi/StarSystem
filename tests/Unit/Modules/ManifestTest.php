<?php

namespace Tests\Unit\Modules;

use App\Modules\InvalidManifest;
use App\Modules\Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    public function test_it_reads_the_example_module()
    {
        $manifest = Manifest::load(dirname(__DIR__, 2).'/Fixtures/modules/Example');

        $this->assertSame('Example', $manifest->name);
        $this->assertSame('example', $manifest->slug);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('^0.1', $manifest->requires);
        $this->assertSame('Modules\Example', $manifest->namespace);
        $this->assertSame('Modules\Example\ExampleServiceProvider', $manifest->provider);
        $this->assertSame('dist', $manifest->assets);
    }

    public function test_abilities_are_read_with_their_labels_and_named_after_the_module()
    {
        $manifest = Manifest::fromArray([...$this->valid(), 'slug' => 'paging-system', 'abilities' => ['view' => ' See submissions ', 'export_all' => 'Export everything']], '/tmp/Example');

        $this->assertSame(['view' => 'See submissions', 'export_all' => 'Export everything'], $manifest->abilities);
        $this->assertSame(
            ['paging-system.view' => 'See submissions', 'paging-system.export_all' => 'Export everything'],
            $manifest->qualifiedAbilities(),
        );
    }

    public function test_a_module_adds_no_abilities_unless_it_says_so()
    {
        $this->assertSame([], Manifest::fromArray($this->valid(), '/tmp/Example')->abilities);
        $this->assertSame([], Manifest::fromArray($this->valid(), '/tmp/Example')->qualifiedAbilities());
        $this->assertSame([], Manifest::fromArray([...$this->valid(), 'abilities' => []], '/tmp/Example')->abilities);
        $this->assertSame([], Manifest::load(dirname(__DIR__, 2).'/Fixtures/modules/Example')->abilities);
    }

    public function test_a_core_area_name_is_fine_for_a_module_that_adds_no_abilities()
    {
        $manifest = Manifest::fromArray([...$this->valid(), 'slug' => 'users'], '/tmp/Example');

        $this->assertSame('users', $manifest->slug);
    }

    public function test_a_folder_without_a_manifest_is_rejected()
    {
        $this->expectException(InvalidManifest::class);
        $this->expectExceptionMessage('no module.json');

        Manifest::load(__DIR__);
    }

    public function test_broken_json_is_rejected()
    {
        $this->expectException(InvalidManifest::class);
        $this->expectExceptionMessage("isn't valid JSON");

        Manifest::fromJson('{"name": ', '/tmp');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'no name' => [['name' => null], '"name" is missing'],
            'name not StudlyCase' => [['name' => 'my module'], 'StudlyCase'],
            'bad slug' => [['slug' => 'Example_Module'], '"slug"'],
            'bad version' => [['version' => 'latest'], '"version"'],
            'no requires' => [['requires' => null], '"requires.starsystem"'],
            'bad requires' => [['requires' => ['starsystem' => 'soon']], '"requires.starsystem"'],
            'provider outside namespace' => [['provider' => 'App\Providers\AppServiceProvider'], 'namespace'],
            'core namespace' => [['namespace' => 'App\Modules', 'provider' => 'App\Modules\Evil'], 'belongs to StarSystem'],
            'assets outside module' => [['assets' => '../../public'], '"assets"'],
            'absolute assets' => [['assets' => 'C:/Windows'], '"assets"'],
            'abilities as a list' => [['abilities' => ['view']], 'object of short names'],
            'ability name with capitals' => [['abilities' => ['View' => 'See submissions']], 'An ability name must be'],
            'ability name too long' => [['abilities' => [str_repeat('a', 33) => 'See submissions']], 'An ability name must be'],
            'ability label empty' => [['abilities' => ['view' => '  ']], 'needs a label'],
            'ability label not text' => [['abilities' => ['view' => 5]], 'needs a label'],
            'ability label too long' => [['abilities' => ['view' => str_repeat('x', 121)]], 'needs a label'],
            'too many abilities' => [['abilities' => array_combine(array_map(fn (int $n) => "ability_{$n}", range(1, 21)), array_fill(0, 21, 'Does something'))], 'at most 20'],
            'slug is one of the core areas' => [['slug' => 'users', 'abilities' => ['view' => 'See submissions']], "StarSystem's own abilities"],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalid')]
    public function test_invalid_manifests_say_what_is_wrong(array $override, string $message)
    {
        $data = array_filter([...$this->valid(), ...$override], fn ($value) => $value !== null);

        $this->expectException(InvalidManifest::class);
        $this->expectExceptionMessage($message);

        Manifest::fromArray($data, '/tmp/Example');
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'name' => 'Example',
            'slug' => 'example',
            'version' => '1.0.0',
            'requires' => ['starsystem' => '^0.1'],
            'provider' => 'Modules\Example\ExampleServiceProvider',
        ];
    }
}

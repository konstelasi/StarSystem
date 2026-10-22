<?php

namespace Tests\Feature\Frontend;

use App\Frontend\FrontendSettings;
use App\Frontend\InvalidFrontendSettings;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CrossSite;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * Each test costs StarDust's set-up and drain, so a test checks several
 * things on one model. Only a valid table needs fields that are indexed,
 * and only the first test drains. The rest run on a model whose filterable
 * fields are still being indexed, which is also how that problem is shown.
 */
class FrontendSettingsTest extends TestCase
{
    use CrossSite, RefreshDatabase, UsesStarDust;

    /**
     * StarDust commits on its own connection, so the promotions a test
     * queues outlive its rollback. Finishing them keeps later ticks idle.
     */
    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    public function test_a_valid_table_saves_reads_back_and_leaves_other_settings_alone()
    {
        $pages = $this->pages(drain: true);
        $this->site->update(['settings' => ['two_factor_roles' => [3, 4], 'frontend' => ['other' => true]]]);

        $this->assertSame([], $this->settings()->problems([$this->row($pages, ['path' => 'blog', 'published_field' => $this->field($pages, 'published')])]));

        $this->settings()->save([$this->row($pages, ['path' => '/blog/', 'list' => true, 'published_field' => $this->field($pages, 'published'), 'label' => 'Journal'])]);

        $routes = $this->settings()->routes();

        $this->assertCount(1, $routes);
        $this->assertSame($pages->id, $routes[0]->modelId);
        $this->assertSame('blog', $routes[0]->path);
        $this->assertSame($this->field($pages, 'slug'), $routes[0]->slugField);
        $this->assertSame($this->field($pages, 'title'), $routes[0]->titleField);
        $this->assertSame($this->field($pages, 'published'), $routes[0]->publishedField);
        $this->assertTrue($routes[0]->list);
        $this->assertNull($routes[0]->homeSlug);
        $this->assertSame('Journal', $routes[0]->label);

        $settings = $this->site->refresh()->settings;
        $this->assertSame([3, 4], $settings['two_factor_roles']);
        $this->assertTrue($settings['frontend']['other']);

        // A blank label becomes the model's own, and the root model names its home page.
        $this->settings()->save([$this->row($pages, ['path' => '', 'home_slug' => 'home', 'label' => ''])]);

        $route = $this->settings()->routes()[0];
        $this->assertTrue($route->isRoot());
        $this->assertSame('home', $route->homeSlug);
        $this->assertSame('Pages', $route->label);

        $this->settings()->save([]);
        $this->assertSame([], $this->settings()->routes());
    }

    public function test_a_table_with_problems_is_not_saved_and_names_the_row()
    {
        $pages = $this->pages();

        try {
            $this->settings()->save([$this->row($pages, ['path' => 'blog']), $this->row($pages, ['path' => 'login'])]);
            $this->fail('The table should not have saved.');
        } catch (InvalidFrontendSettings $e) {
            $this->assertSame([0, 1], array_keys($e->problems));
            $this->assertContains('This address is used by StarSystem. Pick another.', $e->problems[1]);
            $this->assertStringContainsString('used by StarSystem', $e->getMessage());
        }

        $this->assertSame([], $this->settings()->routes());
    }

    public function test_every_row_needs_a_model_that_exists_on_this_site()
    {
        $pages = $this->pages();
        $missing = "This model doesn't exist or is being deleted.";

        $this->assertProblem($this->row(null), 'Pick a model for each address.');
        $this->assertProblem(['path' => 'blog'], 'Pick a model for each address.');
        $this->assertProblem($this->row(null, ['model_id' => 999999]), $missing);

        $row = $this->row($pages);
        $pages->update(['status' => SchemaModel::DELETING]);
        $this->assertProblem($row, $missing);
        $pages->update(['status' => SchemaModel::ACTIVE]);

        $this->asSite($this->makeSite(Str::lower(Str::random(12)).'.test'));
        $this->assertProblem($row, $missing);
    }

    public function test_an_address_is_lowercase_words_that_starsystem_and_no_other_row_uses()
    {
        $pages = $this->pages();
        $posts = $this->pages('posts', 'Posts');
        $shape = 'An address is made of lowercase letters, digits and single hyphens, up to 64 characters.';

        foreach (['Blog', 'my blog', 'a--b', '-blog', 'blog-', 'a/b', "caf\u{e9}", str_repeat('a', 65)] as $path) {
            $this->assertProblem($this->row($pages, ['path' => $path]), $shape);
        }

        foreach (['blog', 'a', 'my-blog-2', str_repeat('a', 64), '/blog/'] as $path) {
            $this->assertNotContains($shape, $this->settings()->problems([$this->row($pages, ['path' => $path])])[0] ?? [], "{$path} should be fine");
        }

        foreach (['admin', 'login', 'invitations', 'files'] as $path) {
            $this->assertProblem($this->row($pages, ['path' => $path]), 'This address is used by StarSystem. Pick another.');
        }

        $this->assertContains(
            'Another row already uses this address.',
            $this->settings()->problems([$this->row($pages, ['path' => 'blog']), $this->row($posts, ['path' => 'blog'])])[1],
        );

        $this->assertContains(
            'This model is already published at another address.',
            $this->settings()->problems([$this->row($pages, ['path' => 'blog']), $this->row($pages, ['path' => 'news'])])[1],
        );

        $this->assertContains(
            'Only one model can be at the root address.',
            $this->settings()->problems([$this->row($pages, ['path' => '']), $this->row($posts, ['path' => ''])])[1],
        );
    }

    public function test_the_address_field_must_be_a_required_filterable_text_field_that_is_indexed()
    {
        $pages = $this->pages();

        $this->assertProblem($this->row($pages, ['slug_field' => '']), "Pick the field that holds each entry's address.");
        $this->assertProblem($this->row($pages, ['slug_field' => (string) Str::uuid()]), "Pick the field that holds each entry's address.");
        $this->assertProblem($this->row($pages, ['slug_field' => $this->field($pages, 'summary')]), "\"Summary\" can't hold an address. Pick a text field.");
        $this->assertProblem($this->row($pages, ['slug_field' => $this->field($pages, 'plain')]), 'Make "Plain" filterable in the builder so entries can be found by it.');
        $this->assertProblem($this->row($pages, ['slug_field' => $this->field($pages, 'loose')]), 'Make "Loose" required so every entry has an address.');
        $this->assertProblem($this->row($pages), '"Slug" is still being indexed. Try again in a minute, once the background work has run.');
    }

    public function test_the_title_field_must_name_an_entry_and_the_published_field_must_be_a_usable_checkbox()
    {
        $pages = $this->pages();

        $this->assertProblem($this->row($pages, ['title_field' => '']), 'Pick the field that names each entry.');
        $this->assertProblem($this->row($pages, ['title_field' => $this->field($pages, 'body')]), "\"Body\" can't name entries. Pick a text, text area, email or URL field.");
        $this->assertNotContains('Pick the field that names each entry.', $this->settings()->problems([$this->row($pages, ['title_field' => $this->field($pages, 'summary')])])[0]);

        $this->assertProblem($this->row($pages, ['published_field' => (string) Str::uuid()]), "The field that marks an entry as published wasn't found. Pick another, or none.");
        $this->assertProblem($this->row($pages, ['published_field' => $this->field($pages, 'slug')]), "\"Slug\" isn't a checkbox. Pick a checkbox field, or none.");
        $this->assertProblem($this->row($pages, ['published_field' => $this->field($pages, 'draft')]), 'Make "Draft" filterable in the builder so unpublished entries can be left out.');
        $this->assertProblem($this->row($pages, ['published_field' => $this->field($pages, 'published')]), '"Published" is still being indexed. Try again in a minute, once the background work has run.');
    }

    public function test_a_list_and_a_home_page_need_the_right_address()
    {
        $pages = $this->pages();

        $this->assertProblem($this->row($pages, ['path' => '', 'list' => true]), 'A list needs an address.');
        $this->assertProblem($this->row($pages, ['path' => 'blog', 'home_slug' => 'home']), 'Only the model at the root address has a home page.');
        $this->assertProblem($this->row($pages, ['path' => '', 'home_slug' => 'Home Page']), 'The home page address is made of lowercase letters, digits and single hyphens.');
        $this->assertProblem($this->row($pages, ['label' => str_repeat('a', 101)]), 'The menu label can be up to 100 characters.');
    }

    public function test_a_row_whose_model_or_field_has_gone_is_left_out_when_read()
    {
        $pages = $this->pages();
        $posts = $this->pages('posts', 'Posts');
        $news = $this->pages('news', 'News');

        $this->store([
            $this->row($pages, ['path' => 'about']),
            $this->row($posts, ['path' => 'blog']),
            $this->row($news, ['path' => 'news', 'published_field' => $this->field($news, 'published')]),
            $this->row($news, ['path' => 'notes', 'title_field' => $this->field($news, 'summary')]),
            $this->row($news, ['path' => 'all']),
        ]);

        $this->assertCount(5, $this->settings()->routes());

        $pages->delete();
        $posts->update(['status' => SchemaModel::DELETING]);
        // Without its published field a row would show every draft, so it goes.
        SchemaField::query()->where('id', $this->field($news, 'published'))->delete();
        SchemaField::query()->where('id', $this->field($news, 'summary'))->delete();

        $this->assertSame(['all'], array_map(fn ($route) => $route->path, $this->settings()->routes()));
    }

    public function test_another_site_does_not_read_this_sites_table()
    {
        $pages = $this->pages();
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->assertInvisibleAcrossSites(
            $this->site,
            $other,
            fn () => $this->store([$this->row($pages, ['path' => 'blog'])]),
            fn () => $this->settings()->routes(),
        );
    }

    private function settings(): FrontendSettings
    {
        return $this->app->make(FrontendSettings::class);
    }

    /**
     * Writes rows as they are, without the checks save() makes, so a test
     * of reading doesn't wait for fields to be indexed.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(array $rows): void
    {
        $site = app(CurrentSite::class)->get();
        $site->update(['settings' => [...($site->settings ?? []), 'frontend' => ['routes' => $rows]]]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertProblem(array $row, string $message): void
    {
        $this->assertContains($message, $this->settings()->problems([$row])[0] ?? [], "Expected the problem: {$message}");
    }

    /**
     * A valid row for the model, with changes. Without a model the row
     * names fields that don't exist.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function row(?SchemaModel $model, array $changes = []): array
    {
        return [
            'model_id' => $model?->id ?? 0,
            'path' => 'blog',
            'slug_field' => $model ? $this->field($model, 'slug') : (string) Str::uuid(),
            'title_field' => $model ? $this->field($model, 'title') : (string) Str::uuid(),
            'published_field' => null,
            'list' => false,
            'home_slug' => null,
            'label' => 'Pages',
            ...$changes,
        ];
    }

    /**
     * The uuid of a model's field, by key.
     */
    private function field(SchemaModel $model, string $key): string
    {
        return SchemaField::query()->where('model_id', $model->id)->where('key', $key)->firstOrFail()->id;
    }

    /**
     * A model with slug (text, required, filterable), title, published
     * (checkbox, filterable), summary (text area), plain (text, required),
     * loose (text, filterable), draft (checkbox) and body (rich text).
     *
     * Not drained unless asked, so its filterable fields are still being
     * indexed when it returns.
     */
    private function pages(string $slug = 'pages', string $label = 'Pages', bool $drain = false): SchemaModel
    {
        $result = app(SchemaManager::class)->createModel(new ModelSchema($slug, $label, fields: [
            new FieldSpec((string) Str::uuid(), 'slug', 'Slug', 'text', required: true, filterable: true),
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
            new FieldSpec((string) Str::uuid(), 'published', 'Published', 'checkbox', filterable: true),
            new FieldSpec((string) Str::uuid(), 'summary', 'Summary', 'textarea'),
            new FieldSpec((string) Str::uuid(), 'plain', 'Plain', 'text', required: true),
            new FieldSpec((string) Str::uuid(), 'loose', 'Loose', 'text', filterable: true),
            new FieldSpec((string) Str::uuid(), 'draft', 'Draft', 'checkbox'),
            new FieldSpec((string) Str::uuid(), 'body', 'Body', 'rich_text'),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());

        if ($drain) {
            $this->drain();
        }

        return $result->model;
    }
}

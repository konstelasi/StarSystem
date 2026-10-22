<?php

namespace Tests\Feature\Frontend;

use App\Entries\EntryManager;
use App\Frontend\FrontendRoute;
use App\Frontend\FrontendSlugs;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Models\User;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CrossSite;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * The address of an entry, checked when it is saved. One scenario, because
 * a StarDust test costs set-up and a drain however small. Requests go
 * through the test site's own domain, as in EntryControllerTest.
 */
class FrontendSlugsTest extends TestCase
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

    public function test_a_published_models_slugs_are_checked_when_entries_are_saved()
    {
        config(['starsystem.multisite' => true]);

        $pages = $this->model('pages');
        $posts = $this->model('posts');
        $notes = $this->model('notes');
        $entries = app(EntryManager::class);

        $entries->create($pages, ['slug' => 'about', 'title' => 'About']);
        $hello = $entries->create($posts, ['slug' => 'hello', 'title' => 'Hello']);
        $old = $entries->create($posts, ['slug' => 'old', 'title' => 'Old']);

        $this->drain();

        $entries->trash($posts, $entries->get($posts, $old));

        $this->store([
            $this->row($pages, ['path' => '']),
            $this->row($posts, ['path' => 'blog']),
        ]);

        $slugs = app(FrontendSlugs::class);
        $problems = fn (SchemaModel $model, string $slug, ?int $entry = null) => $slugs->problems($model, ['slug' => $slug], $entry === null ? null : $entries->get($model, $entry));

        // The shape of an address.
        $shape = ['fields.slug' => ['Use lowercase letters, digits and single hyphens.']];

        foreach (['Hello World', 'HELLO', 'a--b', '-a', 'a-', 'a/b', "caf\u{e9}", str_repeat('a', 121)] as $slug) {
            $this->assertSame($shape, $problems($posts, $slug), "{$slug} should be refused");
        }

        $this->assertSame([], $problems($posts, 'fresh-post-2'));
        $this->assertSame([], $problems($posts, str_repeat('a', 120)));

        // One entry to an address, among those not in the trash.
        $taken = ['fields.slug' => ['Another entry already uses this address.']];

        $this->assertSame($taken, $problems($posts, 'hello'));
        $this->assertSame([], $problems($posts, 'hello', $hello), 'An entry may keep its own address.');
        $this->assertSame($taken, $problems($posts, 'hello', $old), 'Another entry may not take it.');
        $this->assertSame([], $problems($posts, 'old'), 'The address of an entry in the trash can be used again.');
        $this->assertSame($taken, $problems($pages, 'about'));

        // The root address is shared with StarSystem's own pages and with the other models' addresses.
        $this->assertSame(['fields.slug' => ['This address is used by StarSystem. Pick another.']], $problems($pages, 'login'));
        $this->assertSame(['fields.slug' => ['This address is used by StarSystem. Pick another.']], $problems($pages, 'admin'));
        $this->assertSame(['fields.slug' => ['This address is used by another model on the site. Pick another.']], $problems($pages, 'blog'));
        $this->assertSame([], $problems($posts, 'login'), 'Under an address of its own, a slug cannot clash with a page.');

        // A model that is not published has no rule.
        $this->assertSame([], $problems($notes, 'Any Thing Goes!'));
        $this->assertSame([], $slugs->problems($posts, ['title' => 'No slug here'], null), 'An empty slug is for the required rule.');

        // The same rules apply when an entry is saved in the admin.
        $user = User::factory()->create();
        $store = fn (SchemaModel $model, array $fields) => $this->actingAs($user)->post($this->url('admin.models.entries.store', ['model' => $model->id]), ['fields' => $fields]);

        $store($posts, ['slug' => 'hello', 'title' => 'Again'])->assertSessionHasErrors(['fields.slug' => 'Another entry already uses this address.']);
        $store($posts, ['slug' => 'Not Allowed', 'title' => 'x'])->assertSessionHasErrors(['fields.slug' => 'Use lowercase letters, digits and single hyphens.']);
        $store($pages, ['slug' => 'login', 'title' => 'x'])->assertSessionHasErrors(['fields.slug' => 'This address is used by StarSystem. Pick another.']);
        $this->assertCount(1, $entries->page($posts)->rows, 'None of the refused entries was saved.');

        $store($posts, ['slug' => 'fresh', 'title' => 'Fresh'])->assertSessionHasNoErrors();
        $store($notes, ['slug' => 'Whatever You Like', 'title' => 'Free'])->assertSessionHasNoErrors();
        $this->assertCount(2, $entries->page($posts)->rows);

        $update = fn (int $entry, array $fields) => $this->actingAs($user)->put($this->url('admin.models.entries.update', ['model' => $posts->id, 'entry' => $entry]), ['fields' => $fields]);

        $update($hello, ['slug' => 'hello', 'title' => 'Hello, changed'])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Entry saved.');
        $this->assertSame('Hello, changed', $entries->get($posts, $hello)->fields['title']);
        $update($hello, ['slug' => 'fresh', 'title' => 'Hello'])->assertSessionHasErrors(['fields.slug' => 'Another entry already uses this address.']);
        $this->assertSame('hello', $entries->get($posts, $hello)->fields['slug']);

        // An entry in the trash cannot come back to an address another entry has since taken.
        $entries->create($posts, ['slug' => 'old', 'title' => 'The new old']);

        $this->actingAs($user)
            ->post($this->url('admin.models.entries.restore', ['model' => $posts->id, 'entry' => $old]))
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', "Can't restore it. Another entry already uses this address.");
        $this->assertTrue($entries->isTrashed($entries->get($posts, $old)));
    }

    /**
     * Writes rows into the current site's settings as they are.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(array $rows): void
    {
        $this->site->update(['settings' => [...($this->site->settings ?? []), 'frontend' => ['routes' => $rows]]]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function row(SchemaModel $model, array $changes = []): array
    {
        return [
            ...(new FrontendRoute($model->id, 'blog', $this->field($model, 'slug'), $this->field($model, 'title'), null, false, null, $model->label))->toArray(),
            ...$changes,
        ];
    }

    private function field(SchemaModel $model, string $key): string
    {
        return SchemaField::query()->where('model_id', $model->id)->where('key', $key)->firstOrFail()->id;
    }

    /**
     * A model with slug (text, required, filterable) and title. Not drained.
     */
    private function model(string $slug): SchemaModel
    {
        $result = app(SchemaManager::class)->createModel(new ModelSchema($slug, Str::headline($slug), fields: [
            new FieldSpec((string) Str::uuid(), 'slug', 'Slug', 'text', required: true, filterable: true),
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());

        return $result->model;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $route, array $parameters = []): string
    {
        return 'http://'.$this->site->domains[0].route($route, $parameters, absolute: false);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Models\Site;
use App\Models\User;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * The Site page's address table, which needs models whose fields StarDust
 * has indexed. One scenario, because a StarDust test costs set-up and a drain
 * however small.
 */
class SiteTableTest extends TestCase
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

    public function test_the_address_table_is_saved_or_refused_as_a_whole()
    {
        config(['starsystem.multisite' => true]);

        $result = app(SchemaManager::class)->createModel(new ModelSchema('posts', 'Posts', fields: [
            new FieldSpec((string) Str::uuid(), 'slug', 'Slug', 'text', required: true, filterable: true),
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
            new FieldSpec((string) Str::uuid(), 'published', 'Published', 'checkbox', filterable: true),
            new FieldSpec((string) Str::uuid(), 'body', 'Body', 'rich_text'),
        ]));
        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());
        $posts = $result->model;

        $this->drain();

        $slug = $this->field($posts, 'slug');
        $title = $this->field($posts, 'title');
        $published = $this->field($posts, 'published');
        $row = ['model_id' => $posts->id, 'path' => 'blog', 'slug_field' => $slug, 'title_field' => $title, 'published_field' => $published, 'list' => true, 'home_slug' => null, 'label' => 'Journal'];

        $admin = User::factory()->installOwner()->create();
        $this->actingAs($admin);

        // The page offers the model and says which fields are ready.
        $this->get($this->url('admin.site.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('models', 1)
                ->where('models.0.label', 'Posts')
                ->where('models.0.fields.0', ['uuid' => $slug, 'label' => 'Slug', 'type' => 'text', 'filterable' => true, 'required' => true, 'indexed' => true])
                ->where('models.0.fields.2.indexed', true)
                ->where('models.0.fields.3.filterable', false)
                ->where('models.0.fields.3.indexed', false)
                ->where('routes', [])
            );

        // A table with a problem is refused, row by row, and nothing of it is saved, the theme included.
        $this->put($this->url('admin.site.update'), ['theme' => 'default', 'routes' => [$row, [...$row, 'path' => 'login', 'model_id' => 999999]]])
            ->assertSessionHasErrors(['routes.1' => "This model doesn't exist or is being deleted."]);

        $this->put($this->url('admin.site.update'), ['theme' => '', 'routes' => [[...$row, 'path' => 'login']]])
            ->assertSessionHasErrors(['routes.0' => 'This address is used by StarSystem. Pick another.']);

        $this->assertNull($this->site->refresh()->settings['frontend'] ?? null);

        // A good table is saved, and the change is logged with the addresses.
        $this->put($this->url('admin.site.update'), ['theme' => '', 'routes' => [$row]])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Site saved.');

        // MySQL 8 re-sorts the keys of a JSON column, so compare without key order.
        $this->assertEquals([$row], $this->site->refresh()->settings['frontend']['routes']);
        $this->assertSame(['addresses' => ['/blog']], Activity::query()->where('action', ActivityAction::SITE_UPDATED)->latest('id')->firstOrFail()->properties);

        $this->get($this->url('admin.site.index'))->assertInertia(fn (Assert $page) => $page->where('routes.0.path', 'blog')->where('routes.0.label', 'Journal'));

        // Clearing it stops publishing the model.
        $this->put($this->url('admin.site.update'), ['theme' => '', 'routes' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $this->site->refresh()->settings['frontend']['routes']);
        $this->assertSame(['addresses' => []], Activity::query()->where('action', ActivityAction::SITE_UPDATED)->latest('id')->firstOrFail()->properties);

        // Another site does not see this site's models or table.
        $this->put($this->url('admin.site.update'), ['theme' => '', 'routes' => [$row]])->assertSessionHasNoErrors();
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test', 'Other site');

        $this->get($this->url('admin.site.index', $other))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('routes', [])->where('models', []));

        $this->put($this->url('admin.site.update', $other), ['theme' => '', 'routes' => [$row]])
            ->assertSessionHasErrors(['routes.0' => "This model doesn't exist or is being deleted."]);
    }

    private function field(SchemaModel $model, string $key): string
    {
        return SchemaField::query()->where('model_id', $model->id)->where('key', $key)->firstOrFail()->id;
    }

    private function url(string $route, ?Site $site = null): string
    {
        return 'http://'.($site ?? $this->site)->domains[0].route($route, absolute: false);
    }
}

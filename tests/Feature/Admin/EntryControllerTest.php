<?php

namespace Tests\Feature\Admin;

use App\Entries\EntryManager;
use App\Models\SchemaModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\Concerns\ManagesTestModels;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * Requests go through the test site's own domain, as in ModelBuilderTest:
 * multisite is on, so ResolveSite picks UsesStarDust's tenant from the Host.
 */
class EntryControllerTest extends TestCase
{
    use CrossSite, ManagesTestModels, RefreshDatabase, UsesStarDust;

    protected function setUp(): void
    {
        parent::setUp();

        config(['starsystem.multisite' => true]);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $model = $this->create('articles', 'Articles');

        $this->get($this->url('admin.models.entries.index', ['model' => $model->id]))->assertRedirect(route('login'));
    }

    public function test_the_newest_entry_comes_first()
    {
        $model = $this->create('articles', 'Articles');
        $first = $this->writeEntry($model->stardust_model_id, ['title' => 'First']);
        $second = $this->writeEntry($model->stardust_model_id, ['title' => 'Second']);

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/entries/Index')
                ->where('entries.0.id', $second)
                ->where('entries.0.values.title', 'Second')
                ->where('entries.1.id', $first)
                ->where('nextCursor', null)
            );
    }

    public function test_entries_come_one_page_at_a_time()
    {
        $model = $this->create('articles', 'Articles');
        $ids = $this->writeEntries($model, EntryManager::PAGE_SIZE + 1);

        $user = User::factory()->create();

        $cursor = $this->actingAs($user)
            ->get($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', EntryManager::PAGE_SIZE)
                ->where('entries.0.id', end($ids))
                ->whereType('nextCursor', 'string')
            )
            ->inertiaProps('nextCursor');

        $this->actingAs($user)
            ->get($this->url('admin.models.entries.index', ['model' => $model->id, 'cursor' => $cursor]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.id', $ids[0])
                ->where('nextCursor', null)
            );
    }

    public function test_a_cursor_stardust_did_not_issue_is_not_found()
    {
        $model = $this->create('articles', 'Articles');

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $model->id, 'cursor' => 'not-a-cursor']))
            ->assertNotFound();
    }

    public function test_another_sites_model_id_is_not_found()
    {
        $model = $this->create('articles', 'Articles');
        $other = $this->makeSite(Str::lower(Str::random(12)).'.test');

        $this->actingAs(User::factory()->create())
            ->get($this->url('admin.models.entries.index', ['model' => $model->id], $other))
            ->assertNotFound();
    }

    public function test_storing_an_entry_creates_it_and_returns_to_the_list()
    {
        $model = $this->create('articles', 'Articles');

        $this->actingAs(User::factory()->create())
            ->post($this->url('admin.models.entries.store', ['model' => $model->id]), ['fields' => ['title' => 'Hello']])
            ->assertRedirect($this->url('admin.models.entries.index', ['model' => $model->id]));

        $rows = app(EntryManager::class)->page($model)->rows;
        $this->assertCount(1, $rows);
        $this->assertSame('Hello', $rows[0]->fields['title']);
    }

    /**
     * @return list<int> The entry ids, oldest first.
     */
    private function writeEntries(SchemaModel $model, int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->writeEntry($model->stardust_model_id, ['title' => "Entry {$i}"]);
        }

        return $ids;
    }
}

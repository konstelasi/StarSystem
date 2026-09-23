<?php

namespace Tests\Feature\Schema;

use App\Models\SchemaChange;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class SchemaTablesTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    private static int $stardustId = 1000;

    public function test_models_are_invisible_across_sites()
    {
        $this->assertInvisibleAcrossSites(
            owner: $this->makeSite('owner.example.com'),
            other: $this->defaultSite(),
            write: fn () => $this->makeModel('articles'),
            read: fn () => SchemaModel::query()->count(),
        );
    }

    public function test_fields_are_invisible_across_sites()
    {
        $this->assertInvisibleAcrossSites(
            owner: $this->makeSite('owner.example.com'),
            other: $this->defaultSite(),
            write: fn () => $this->makeField($this->makeModel('articles'), 'title'),
            read: fn () => SchemaField::query()->count(),
        );
    }

    public function test_a_field_is_not_reachable_through_another_sites_model_id()
    {
        $owner = $this->makeSite('owner.example.com');
        $this->asSite($owner);
        $model = $this->makeModel('articles');
        $this->makeField($model, 'title');

        $this->asSite($this->defaultSite());

        $this->assertSame(0, SchemaField::query()->where('model_id', $model->id)->count());
        $this->assertNull(SchemaModel::find($model->id));
    }

    public function test_schema_change_batches_are_invisible_across_sites()
    {
        $batch = (string) Str::uuid();

        $this->assertInvisibleAcrossSites(
            owner: $this->makeSite('owner.example.com'),
            other: $this->defaultSite(),
            write: fn () => SchemaChange::create(['batch' => $batch, 'op' => 'add', 'payload' => ['key' => 'title']]),
            read: fn () => SchemaChange::query()->where('batch', $batch)->count(),
        );
    }

    public function test_two_sites_can_use_the_same_model_slug()
    {
        $this->asSite($this->makeSite('owner.example.com'));
        $this->makeModel('articles');

        $this->asSite($this->defaultSite());
        $this->makeModel('articles');

        $this->assertSame(1, SchemaModel::query()->where('slug', 'articles')->count());
    }

    public function test_new_rows_get_the_current_site_and_sensible_defaults()
    {
        $site = $this->makeSite('owner.example.com');
        $this->asSite($site);

        $model = $this->makeModel('articles');
        $field = $this->makeField($model, 'title');
        $change = SchemaChange::create(['op' => 'add']);

        $this->assertSame($site->id, $model->tenant_id);
        $this->assertSame($site->id, $field->tenant_id);
        $this->assertSame(SchemaModel::ACTIVE, $model->fresh()?->status);
        $this->assertSame(0, $model->fresh()?->schema_rev);
        $this->assertSame(SchemaChange::PENDING, $change->fresh()?->status);
        $this->assertSame(0, $change->fresh()?->attempts);
    }

    private function makeModel(string $slug): SchemaModel
    {
        return SchemaModel::create([
            'stardust_model_id' => self::$stardustId++,
            'slug' => $slug,
            'label' => Str::headline($slug),
        ]);
    }

    private function makeField(SchemaModel $model, string $key): SchemaField
    {
        return SchemaField::create([
            'id' => (string) Str::uuid(),
            'model_id' => $model->id,
            'key' => $key,
            'label' => Str::headline($key),
            'type' => 'text',
            'settings' => [],
        ]);
    }
}

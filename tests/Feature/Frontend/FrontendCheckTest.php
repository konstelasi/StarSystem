<?php

namespace Tests\Feature\Frontend;

use App\Frontend\FrontendRoute;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\SaveResult;
use App\Schema\SchemaException;
use App\Schema\SchemaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * What the builder may not do to a model the site publishes. One scenario,
 * because a StarDust test costs set-up and a drain however small.
 */
class FrontendCheckTest extends TestCase
{
    use RefreshDatabase, UsesStarDust;

    /**
     * StarDust commits on its own connection, so the work a test queues
     * outlives its rollback. Finishing it keeps later ticks idle.
     */
    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    public function test_the_builder_cannot_break_a_model_the_site_publishes()
    {
        $posts = $this->model('posts');
        $notes = $this->model('notes');

        // Drained once, so every field is ready and a refusal below is ours and not StarDust's.
        $this->drain();

        $this->site->update(['settings' => ['frontend' => ['routes' => [
            (new FrontendRoute($posts->id, 'blog', $this->field($posts, 'slug'), $this->field($posts, 'title'), $this->field($posts, 'published'), true, null, 'Blog'))->toArray(),
        ]]]]);

        $manager = app(SchemaManager::class);

        // The address field, the title field and the published switch cannot be removed.
        foreach (['slug' => 'the address of its entries', 'title' => 'the title of its entries', 'published' => 'the switch that publishes an entry'] as $key => $role) {
            $this->assertRefused($manager, $posts, $key, fn (array $fields) => array_values(array_filter($fields, fn (FieldSpec $f) => $f->key !== $key)), "is used as {$role} on the Site page, so it can't be removed.");
        }

        // Nor can their type change.
        $this->assertRefused($manager, $posts, 'slug', fn (array $fields) => $this->change($fields, 'slug', ['type' => 'textarea']), "so its type can't change.");
        $this->assertRefused($manager, $posts, 'title', fn (array $fields) => $this->change($fields, 'title', ['type' => 'textarea']), "so its type can't change.");
        $this->assertRefused($manager, $posts, 'published', fn (array $fields) => $this->change($fields, 'published', ['type' => 'text']), "so its type can't change.");

        // The address and the published switch stay filterable, and the address stays required.
        $this->assertRefused($manager, $posts, 'slug', fn (array $fields) => $this->change($fields, 'slug', ['filterable' => false]), 'so it must stay filterable.');
        $this->assertRefused($manager, $posts, 'published', fn (array $fields) => $this->change($fields, 'published', ['filterable' => false]), 'so it must stay filterable.');
        $this->assertRefused($manager, $posts, 'slug', fn (array $fields) => $this->change($fields, 'slug', ['required' => false]), 'so it must stay required.');

        // The model cannot be deleted while the site publishes it.
        try {
            $manager->deleteModel($posts);
            $this->fail('The model should not have been deleted.');
        } catch (SchemaException $e) {
            $this->assertSame('The site publishes this model at /blog. Remove it from the Site page first.', $e->getMessage());
        }

        $this->assertSame(SchemaModel::ACTIVE, $posts->refresh()->status);

        // Everything else still saves: another field, a label, a new field, and a new key for the address field.
        $schema = ModelSchema::fromModel($posts->refresh());
        $edited = $schema->withFields([
            ...array_map(fn (FieldSpec $f) => match ($f->key) {
                'summary' => $f->with(['label' => 'Short summary', 'required' => true]),
                'slug' => $f->with(['label' => 'Address', 'key' => 'address']),
                'title' => $f->with(['label' => 'Headline']),
                default => $f,
            }, $schema->fields),
            new FieldSpec((string) Str::uuid(), 'extra', 'Extra', 'text'),
        ]);

        $result = $manager->save($posts, $edited);

        $this->assertSame(SaveResult::DONE, $result->status, json_encode($result->preview['errors'] ?? []));
        $this->assertSame('address', SchemaField::query()->whereKey($this->field($posts, 'address'))->firstOrFail()->key);

        // A model that is not published has no such rules.
        $manager->deleteModel($notes);
        $this->assertSame(SchemaModel::DELETING, $notes->refresh()->status);
    }

    /**
     * Saves the model with its fields changed and checks the save was
     * refused, with a message for the field that holds $message.
     *
     * @param  callable(list<FieldSpec>): list<FieldSpec>  $change
     */
    private function assertRefused(SchemaManager $manager, SchemaModel $model, string $key, callable $change, string $message): void
    {
        $schema = ModelSchema::fromModel($model->refresh());
        $uuid = $this->field($model, $key);

        $result = $manager->save($model, $schema->withFields($change($schema->fields)));

        $this->assertSame(SaveResult::INVALID, $result->status, "Changing {$key} should be refused.");

        $errors = array_filter($result->preview['errors'], fn (array $error) => $error['field_uuid'] === $uuid);
        $this->assertNotEmpty($errors, "No error names {$key}.");
        $this->assertTrue(
            collect($errors)->contains(fn (array $error) => str_contains($error['message'], $message)),
            "No error for {$key} says: {$message}",
        );
    }

    /**
     * @param  list<FieldSpec>  $fields
     * @param  array<string, mixed>  $changes
     * @return list<FieldSpec>
     */
    private function change(array $fields, string $key, array $changes): array
    {
        return array_map(fn (FieldSpec $f) => $f->key === $key ? $f->with($changes) : $f, $fields);
    }

    private function field(SchemaModel $model, string $key): string
    {
        return SchemaField::query()->where('model_id', $model->id)->where('key', $key)->firstOrFail()->id;
    }

    /**
     * A model with slug (text, required, filterable), title, published
     * (checkbox, filterable) and summary (text area).
     */
    private function model(string $slug): SchemaModel
    {
        $result = app(SchemaManager::class)->createModel(new ModelSchema($slug, Str::headline($slug), fields: [
            new FieldSpec((string) Str::uuid(), 'slug', 'Slug', 'text', required: true, filterable: true),
            new FieldSpec((string) Str::uuid(), 'title', 'Title', 'text'),
            new FieldSpec((string) Str::uuid(), 'published', 'Published', 'checkbox', filterable: true),
            new FieldSpec((string) Str::uuid(), 'summary', 'Summary', 'textarea'),
        ]));

        $this->assertSame(SaveResult::DONE, $result->status, (string) $result->error());

        return $result->model;
    }
}

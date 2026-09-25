<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The drag-and-drop model builder.
 *
 * Until the schema layer lands, the field types, the schema and the field
 * states come from JSON fixtures in resources/fixtures/builder. Swapping in
 * the real SchemaManager only touches the three loader methods below, plus
 * `diffPreview()` and `save()`, which stand in for the real preview/apply.
 */
class ModelBuilderController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('admin/models/Builder', [
            'fieldTypes' => $this->fieldTypes(),
            'schema' => $this->schema(),
            'states' => $this->states(),
        ]);
    }

    /**
     * The two-step save, step one: what saving the posted schema would do,
     * diffed against the fixture's schema. A partial Inertia reload, so it
     * only touches the `preview` prop and leaves the client's in-progress
     * schema alone.
     */
    public function preview(Request $request): Response
    {
        $incoming = $request->validate(['schema' => ['required', 'array']])['schema'];

        return Inertia::render('admin/models/Builder', [
            'preview' => $this->diffPreview($incoming),
        ]);
    }

    /**
     * The two-step save, step two. Stand-in: validates the shape but doesn't
     * persist anything, since there's no schema layer yet to persist it to.
     */
    public function save(Request $request): Response
    {
        $request->validate(['schema' => ['required', 'array']]);

        return Inertia::render('admin/models/Builder', [
            'saved' => true,
        ]);
    }

    /**
     * Field-type descriptors for the palette and inspector.
     */
    private function fieldTypes(): mixed
    {
        return $this->fixture('field-types');
    }

    /**
     * The model schema in its JSON export format.
     *
     * @return object{fields: list<object{uuid: string, key: string, type: string, filterable: bool}>, model: object{label?: string, group?: string, icon?: string}}
     */
    private function schema(): object
    {
        return $this->fixture('schema');
    }

    /**
     * Busy state per field uuid. Fields that aren't listed are ready.
     */
    private function states(): mixed
    {
        return $this->fixture('states');
    }

    /**
     * Decodes a fixture into objects rather than arrays, so empty objects
     * such as `"settings": {}` reach the page as {} and not [].
     */
    private function fixture(string $name): mixed
    {
        $json = file_get_contents(resource_path("fixtures/builder/{$name}.json"));

        return json_decode((string) $json, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Diffs the posted schema against the fixture schema and describes what
     * saving it would do, in the shared `SavePreview` shape.
     *
     * @param  array{fields?: list<array<string, mixed>>, model?: array<string, mixed>}  $incoming
     * @return array{operations: list<array<string, mixed>>, errors: list<array{field_uuid: string|null, message: string}>}
     */
    private function diffPreview(array $incoming): array
    {
        $original = $this->schema();
        $before = collect($original->fields)->keyBy('uuid');
        $after = collect($incoming['fields'] ?? [])->keyBy('uuid');

        $operations = [];
        $errors = [];
        $seenKeys = [];

        foreach ($after as $uuid => $field) {
            $key = (string) ($field['key'] ?? '');
            $label = (string) ($field['label'] ?? '');

            if ($label === '') {
                $errors[] = ['field_uuid' => $uuid, 'message' => 'Give this field a label.'];
            }

            if ($problem = $this->keyProblem($key, $seenKeys)) {
                $errors[] = ['field_uuid' => $uuid, 'message' => $problem];
            }

            $seenKeys[$key] = true;

            $wasField = $before->get($uuid);

            if ($wasField === null) {
                $operations[] = [
                    'op' => 'add',
                    'field_uuid' => $uuid,
                    'summary' => "Add \"{$label}\"",
                    'affects_existing_data' => false,
                    'destructive' => false,
                ];

                continue;
            }

            if ($wasField->key !== $key) {
                $operations[] = [
                    'op' => 'rename',
                    'field_uuid' => $uuid,
                    'summary' => "Rename \"{$wasField->key}\" to \"{$key}\"",
                    'affects_existing_data' => true,
                    'destructive' => false,
                ];
            }

            $type = (string) ($field['type'] ?? $wasField->type);

            if ($wasField->type !== $type) {
                $operations[] = [
                    'op' => 'retype',
                    'field_uuid' => $uuid,
                    'summary' => "Change \"{$key}\" from {$wasField->type} to {$type}",
                    'affects_existing_data' => true,
                    'destructive' => true,
                ];
            }

            $wasFilterable = (bool) $wasField->filterable;
            $isFilterable = (bool) ($field['filterable'] ?? $wasFilterable);

            if (! $wasFilterable && $isFilterable) {
                $operations[] = [
                    'op' => 'promote',
                    'field_uuid' => $uuid,
                    'summary' => "Index \"{$key}\" for filtering",
                    'affects_existing_data' => false,
                    'destructive' => false,
                ];
            } elseif ($wasFilterable && ! $isFilterable) {
                $operations[] = [
                    'op' => 'demote',
                    'field_uuid' => $uuid,
                    'summary' => "Stop indexing \"{$key}\"",
                    'affects_existing_data' => false,
                    'destructive' => false,
                ];
            }
        }

        foreach ($before as $uuid => $wasField) {
            if (! $after->has($uuid)) {
                $operations[] = [
                    'op' => 'delete',
                    'field_uuid' => $uuid,
                    'summary' => "Delete \"{$wasField->key}\"",
                    'affects_existing_data' => true,
                    'destructive' => true,
                ];
            }
        }

        $incomingModel = (array) ($incoming['model'] ?? []);
        $originalModel = (array) $original->model;

        $modelChanged = ($incomingModel['label'] ?? null) !== ($originalModel['label'] ?? null)
            || ($incomingModel['group'] ?? null) !== ($originalModel['group'] ?? null)
            || ($incomingModel['icon'] ?? null) !== ($originalModel['icon'] ?? null);

        if ($modelChanged) {
            $operations[] = [
                'op' => 'metadata',
                'field_uuid' => null,
                'summary' => 'Update model details',
                'affects_existing_data' => false,
                'destructive' => false,
            ];
        }

        return ['operations' => $operations, 'errors' => $errors];
    }

    /**
     * A plain-language problem with a key, or null when it's fine. Mirrors
     * resources/js/lib/modelSchema.ts's `keyProblem()`.
     *
     * @param  array<string, true>  $seenKeys  Keys already seen this diff, keyed by key.
     */
    private function keyProblem(string $key, array $seenKeys): ?string
    {
        if ($key === '') {
            return 'Give this field a key.';
        }

        if (str_starts_with($key, '_')) {
            return 'Keys starting with "_" are reserved.';
        }

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)) {
            return 'Use letters, numbers and underscores, starting with a letter.';
        }

        if (strlen($key) > 128) {
            return 'Keep the key under 128 characters.';
        }

        if (isset($seenKeys[$key])) {
            return 'Another field already uses this key.';
        }

        return null;
    }
}

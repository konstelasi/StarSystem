<?php

namespace App\Http\Controllers\Admin;

use App\Entries\EntryManager;
use App\Http\Controllers\Controller;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Schema\SchemaManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use StarDust\Read\Entry;

/**
 * The admin's entries: a model's actual content records.
 */
class EntryController extends Controller
{
    public function __construct(
        private readonly EntryManager $entries,
        private readonly SchemaManager $schema,
    ) {}

    public function index(SchemaModel $model): Response
    {
        $columns = $this->listColumns($model);
        $keys = $columns->pluck('key')->all();

        return Inertia::render('admin/entries/Index', [
            'model' => ['id' => $model->id, 'label' => $model->label],
            'columns' => $columns->map(fn (SchemaField $field) => [
                'key' => $field->key,
                'label' => $field->label,
            ])->values(),
            'entries' => collect($this->entries->list($model))->map(fn (Entry $entry) => [
                'id' => $entry->id,
                'values' => Arr::only($entry->fields, $keys),
                'created_at' => $entry->createdAt->format(DATE_ATOM),
            ])->values(),
        ]);
    }

    public function create(SchemaModel $model): Response
    {
        return Inertia::render('admin/entries/Create', [
            'model' => ['id' => $model->id, 'label' => $model->label],
            'schema' => $this->schema->export($model),
        ]);
    }

    public function store(Request $request, SchemaModel $model): RedirectResponse
    {
        $fields = $this->validated($request, $model);

        $this->entries->create($model, $fields);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entry created.']);

        return to_route('admin.models.entries.index', $model);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, SchemaModel $model): array
    {
        $rules = [];
        foreach ($this->entries->rulesFor($model) as $key => $fieldRules) {
            $rules["fields.{$key}"] = $fieldRules;
        }

        $validated = Validator::make($request->all(), $rules)->validate();

        return $this->entries->normalizeFields($model, $validated['fields'] ?? []);
    }

    /**
     * The fields shown as list columns: the ones the builder marked, or the
     * first three when nobody has, so a model built before that flag
     * existed still shows something.
     *
     * @return Collection<int, SchemaField>
     */
    private function listColumns(SchemaModel $model): Collection
    {
        $fields = $model->fields;
        $shown = $fields->where('shown_in_list', true)->values();

        return $shown->isNotEmpty() ? $shown : $fields->take(3)->values();
    }
}

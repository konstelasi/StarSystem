<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchemaModel;
use App\Schema\SchemaManager;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The site's models list: what the builder edits are, in one place.
 */
class ModelController extends Controller
{
    public function __construct(private readonly SchemaManager $schema) {}

    public function index(): Response
    {
        $this->schema->forgetPurgedModels();

        $models = SchemaModel::query()
            ->withCount('fields')
            ->orderBy('label')
            ->get()
            ->map(fn (SchemaModel $model) => $this->row($model));

        return Inertia::render('admin/models/Index', [
            'models' => $models,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SchemaModel $model): array
    {
        return [
            'id' => $model->id,
            'slug' => $model->slug,
            'label' => $model->label,
            'group' => $model->group,
            'fields_count' => $model->fields_count,
            'status' => $model->status,
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }
}

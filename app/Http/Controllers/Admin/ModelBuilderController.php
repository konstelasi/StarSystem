<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The drag-and-drop model builder.
 *
 * Until the schema layer lands, the field types, the schema and the field
 * states come from JSON fixtures in resources/fixtures/builder. Swapping in
 * the real SchemaManager only touches the three loader methods below.
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
     * Field-type descriptors for the palette and inspector.
     */
    private function fieldTypes(): mixed
    {
        return $this->fixture('field-types');
    }

    /**
     * The model schema in its JSON export format.
     */
    private function schema(): mixed
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
}

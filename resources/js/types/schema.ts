// Shared contract between the schema layer (PHP) and the model builder UI.
// Keep these shapes in sync with the server; both sides build to them.

// Field-type descriptor: FieldTypeRegistry::toArray(), sent to the builder palette and inspector
export type FieldTypeDescriptor = {
    key: string; // 'text', 'select', …
    label: string;
    icon: string; // lucide icon name
    category:
        | 'basic'
        | 'choice'
        | 'number'
        | 'date'
        | 'rich'
        | 'media'
        | 'advanced';
    settings: SettingDescriptor[]; // drives the inspector
    storage: {
        declaredType: 'string' | 'int' | 'numeric' | 'datetime';
        canFilter: boolean;
    }; // may depend on settings; server recomputes
};
export type SettingDescriptor = {
    key: string;
    label: string;
    help?: string;
    input:
        | 'text'
        | 'number'
        | 'boolean'
        | 'select'
        | 'options_source'
        | 'string_list';
    default: unknown;
    choices?: { value: string; label: string }[];
};
export type OptionsSource =
    | { kind: 'static'; options: { value: string; label: string }[] }
    | { kind: 'model'; model: string; label_field: string }
    | { kind: 'hook'; name: string };

// Model schema: ModelSchema::toJson(), also the JSON tab and the export format
export type ModelSchemaJson = {
    version: 1;
    model: { slug: string; label: string; icon?: string; group?: string };
    layout: LayoutBlock[]; // metadata only
    fields: FieldSpecJson[]; // array order = position
};
export type LayoutBlock = {
    id: string;
    kind: 'section' | 'tabs' | 'columns';
    label?: string;
    slots: { id: string; label?: string }[];
};
export type FieldSpecJson = {
    uuid: string;
    key: string;
    label: string;
    type: string;
    helper?: string;
    required: boolean;
    filterable: boolean;
    layout_slot: string | null; // LayoutBlock slot id, or null = top level
    settings: Record<string, unknown>;
};

// Busy state per field, for badges: SchemaManager::states()
export type FieldState =
    | 'ready'
    | 'indexing'
    | 'renaming'
    | 'retyping'
    | 'deleting'
    | 'waiting';

// SchemaManager::preview(): the two-step save
export type SavePreview = {
    operations: {
        op:
            | 'metadata'
            | 'add'
            | 'rename'
            | 'retype'
            | 'promote'
            | 'demote'
            | 'delete';
        field_uuid: string | null;
        summary: string;
        affects_existing_data: boolean;
        destructive: boolean;
    }[];
    errors: { field_uuid: string | null; message: string }[];
};

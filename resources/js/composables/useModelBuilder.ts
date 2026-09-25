import { computed, ref, toValue } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import {
    clone,
    keyFromLabel,
    newBlock,
    newSlot,
    settingDefaults,
    slotChoices,
    uniqueKey,
    uuid,
} from '@/lib/modelSchema';
import type {
    FieldSpecJson,
    FieldTypeDescriptor,
    LayoutBlock,
    ModelSchemaJson,
} from '@/types/schema';

export type BuilderSelection =
    | { kind: 'field'; uuid: string }
    | { kind: 'block'; id: string }
    | null;

export type FieldPatch = Partial<Omit<FieldSpecJson, 'uuid'>>;

/** Where a field sits among the fields of its slot, for announcements. */
export type FieldPosition = { index: number; total: number };

/** A deep copy that tolerates PHP encoding an empty `settings` as []. */
function normalize(value: ModelSchemaJson): ModelSchemaJson {
    const next = clone(value);

    for (const field of next.fields) {
        if (
            typeof field.settings !== 'object' ||
            field.settings === null ||
            Array.isArray(field.settings)
        ) {
            field.settings = {};
        }
    }

    return next;
}

/**
 * State for the model builder. It holds one `ModelSchemaJson` and changes it
 * in place. Fields are identified by uuid, never by key: renaming a key keeps
 * the uuid, so the server sees a rename rather than a delete and an add.
 */
export function useModelBuilder(
    initial: ModelSchemaJson,
    fieldTypes: MaybeRefOrGetter<FieldTypeDescriptor[]>,
) {
    const schema = ref<ModelSchemaJson>(normalize(initial));
    const saved = ref<ModelSchemaJson>(normalize(initial));
    const selection = ref<BuilderSelection>(null);

    /**
     * New fields whose key still follows their label. Typing a key by hand,
     * or saving, stops that.
     */
    const autoKeys = ref(new Set<string>());

    const typesByKey = computed(
        () => new Map(toValue(fieldTypes).map((type) => [type.key, type])),
    );
    const savedByUuid = computed(
        () => new Map(saved.value.fields.map((field) => [field.uuid, field])),
    );

    const isDirty = computed(
        () => JSON.stringify(schema.value) !== JSON.stringify(saved.value),
    );

    const selectedField = computed(() => {
        const current = selection.value;

        return current?.kind === 'field' ? findField(current.uuid) : undefined;
    });

    const selectedBlock = computed(() => {
        const current = selection.value;

        return current?.kind === 'block' ? findBlock(current.id) : undefined;
    });

    const slots = computed(() => slotChoices(schema.value.layout));

    function typeOf(key: string): FieldTypeDescriptor | undefined {
        return typesByKey.value.get(key);
    }

    function findField(fieldUuid: string): FieldSpecJson | undefined {
        return schema.value.fields.find((field) => field.uuid === fieldUuid);
    }

    function findBlock(id: string): LayoutBlock | undefined {
        return schema.value.layout.find((block) => block.id === id);
    }

    function savedField(fieldUuid: string): FieldSpecJson | undefined {
        return savedByUuid.value.get(fieldUuid);
    }

    function isNew(fieldUuid: string): boolean {
        return !savedByUuid.value.has(fieldUuid);
    }

    function fieldsIn(slotId: string | null): FieldSpecJson[] {
        return schema.value.fields.filter(
            (field) => field.layout_slot === slotId,
        );
    }

    function positionOf(fieldUuid: string): FieldPosition | null {
        const field = findField(fieldUuid);

        if (field === undefined) {
            return null;
        }

        const siblings = fieldsIn(field.layout_slot);

        return {
            index: siblings.findIndex((item) => item.uuid === fieldUuid),
            total: siblings.length,
        };
    }

    function otherKeys(exceptUuid?: string): string[] {
        return schema.value.fields
            .filter((field) => field.uuid !== exceptUuid)
            .map((field) => field.key);
    }

    /** Whether a slot id names the main area or a slot of a layout block. */
    function slotExists(slotId: string | null): boolean {
        return (
            slotId === null ||
            schema.value.layout.some((block) =>
                block.slots.some((slot) => slot.id === slotId),
            )
        );
    }

    function select(next: BuilderSelection) {
        selection.value = next;
    }

    /**
     * Puts a field into a slot at the given position among that slot's
     * fields. The fields array order is the position, so this finds the
     * right place in the whole list.
     */
    function place(field: FieldSpecJson, slotId: string | null, index: number) {
        const fields = schema.value.fields;
        field.layout_slot = slotId;

        const siblings = fields.filter(
            (item) => item.layout_slot === slotId && item !== field,
        );
        const clamped = Math.max(0, Math.min(index, siblings.length));

        if (clamped < siblings.length) {
            fields.splice(fields.indexOf(siblings[clamped]), 0, field);
        } else if (siblings.length > 0) {
            fields.splice(fields.indexOf(siblings.at(-1)!) + 1, 0, field);
        } else {
            fields.push(field);
        }
    }

    function addField(
        typeKey: string,
        slotId: string | null = null,
        index = Number.POSITIVE_INFINITY,
    ): FieldSpecJson | undefined {
        const type = typeOf(typeKey);

        if (type === undefined || !slotExists(slotId)) {
            return undefined;
        }

        const field: FieldSpecJson = {
            uuid: uuid(),
            key: uniqueKey(keyFromLabel(type.label), otherKeys()),
            label: type.label,
            type: type.key,
            required: false,
            filterable: false,
            layout_slot: slotId,
            settings: settingDefaults(type),
        };

        place(field, slotId, index);
        autoKeys.value.add(field.uuid);
        select({ kind: 'field', uuid: field.uuid });

        // Hand back the reactive copy, not the plain object.
        return findField(field.uuid);
    }

    function duplicateField(fieldUuid: string): FieldSpecJson | undefined {
        const source = findField(fieldUuid);

        if (source === undefined) {
            return undefined;
        }

        const label = `${source.label} (copy)`;
        const copy: FieldSpecJson = {
            ...clone(source),
            uuid: uuid(),
            label,
            key: uniqueKey(keyFromLabel(label), otherKeys()),
        };
        const position = positionOf(fieldUuid);

        place(copy, source.layout_slot, (position?.index ?? 0) + 1);
        autoKeys.value.add(copy.uuid);
        select({ kind: 'field', uuid: copy.uuid });

        return findField(copy.uuid);
    }

    function updateField(fieldUuid: string, patch: FieldPatch) {
        const field = findField(fieldUuid);

        if (field === undefined) {
            return;
        }

        if (patch.key !== undefined) {
            autoKeys.value.delete(fieldUuid);
        }

        Object.assign(field, patch);

        if (patch.label !== undefined && autoKeys.value.has(fieldUuid)) {
            field.key = uniqueKey(
                keyFromLabel(patch.label),
                otherKeys(fieldUuid),
            );
        }
    }

    function updateSetting(fieldUuid: string, key: string, value: unknown) {
        const field = findField(fieldUuid);

        if (field !== undefined) {
            field.settings[key] = value;
        }
    }

    /**
     * Switches a field to another type. Settings the new type shares with the
     * old one keep their values; the rest start from the new type's defaults.
     */
    function changeType(fieldUuid: string, typeKey: string) {
        const field = findField(fieldUuid);
        const type = typeOf(typeKey);

        if (
            field === undefined ||
            type === undefined ||
            field.type === typeKey
        ) {
            return;
        }

        const settings = settingDefaults(type);

        for (const key of Object.keys(settings)) {
            if (key in field.settings) {
                settings[key] = field.settings[key];
            }
        }

        field.type = type.key;
        field.settings = settings;

        if (!type.storage.canFilter) {
            field.filterable = false;
        }
    }

    function removeField(fieldUuid: string) {
        const index = schema.value.fields.findIndex(
            (field) => field.uuid === fieldUuid,
        );

        if (index === -1) {
            return;
        }

        schema.value.fields.splice(index, 1);
        autoKeys.value.delete(fieldUuid);

        if (
            selection.value?.kind === 'field' &&
            selection.value.uuid === fieldUuid
        ) {
            selection.value = null;
        }
    }

    function moveField(
        fieldUuid: string,
        slotId: string | null,
        index: number,
    ) {
        const fields = schema.value.fields;
        const from = fields.findIndex((field) => field.uuid === fieldUuid);

        if (from === -1 || !slotExists(slotId)) {
            return;
        }

        const [field] = fields.splice(from, 1);
        place(field, slotId, index);
    }

    /** Moves a field up (-1) or down (+1) within its slot. */
    function moveFieldBy(
        fieldUuid: string,
        delta: number,
    ): FieldPosition | null {
        const field = findField(fieldUuid);
        const position = positionOf(fieldUuid);

        if (field === undefined || position === null) {
            return null;
        }

        const target = position.index + delta;

        if (target < 0 || target >= position.total) {
            return position;
        }

        moveField(fieldUuid, field.layout_slot, target);

        return positionOf(fieldUuid);
    }

    function addBlock(
        kind: LayoutBlock['kind'],
        index = Number.POSITIVE_INFINITY,
    ): LayoutBlock {
        const block = newBlock(kind);
        const layout = schema.value.layout;
        layout.splice(Math.max(0, Math.min(index, layout.length)), 0, block);
        select({ kind: 'block', id: block.id });

        return findBlock(block.id)!;
    }

    function updateBlock(id: string, patch: Partial<Omit<LayoutBlock, 'id'>>) {
        const block = findBlock(id);

        if (block !== undefined) {
            Object.assign(block, patch);
        }
    }

    function moveBlock(id: string, index: number) {
        const layout = schema.value.layout;
        const from = layout.findIndex((block) => block.id === id);

        if (from === -1) {
            return;
        }

        const [block] = layout.splice(from, 1);
        layout.splice(Math.max(0, Math.min(index, layout.length)), 0, block);
    }

    function moveBlockBy(id: string, delta: number): FieldPosition | null {
        const layout = schema.value.layout;
        const index = layout.findIndex((block) => block.id === id);

        if (index === -1) {
            return null;
        }

        moveBlock(id, index + delta);

        return {
            index: layout.findIndex((block) => block.id === id),
            total: layout.length,
        };
    }

    function addSlot(blockId: string) {
        const block = findBlock(blockId);

        if (block !== undefined) {
            block.slots.push(newSlot(block));
        }
    }

    function renameSlot(blockId: string, slotId: string, label: string) {
        const slot = findBlock(blockId)?.slots.find(
            (item) => item.id === slotId,
        );

        if (slot !== undefined) {
            slot.label = label;
        }
    }

    /** Removes a slot. Its fields move to the block's first remaining slot. */
    function removeSlot(blockId: string, slotId: string) {
        const block = findBlock(blockId);

        if (block === undefined || block.slots.length <= 1) {
            return;
        }

        block.slots = block.slots.filter((slot) => slot.id !== slotId);
        const fallback = block.slots[0].id;

        for (const field of schema.value.fields) {
            if (field.layout_slot === slotId) {
                field.layout_slot = fallback;
            }
        }
    }

    /** Removes a layout block. Its fields move to the main area. */
    function removeBlock(id: string) {
        const block = findBlock(id);

        if (block === undefined) {
            return;
        }

        const slotIds = new Set(block.slots.map((slot) => slot.id));

        for (const field of schema.value.fields) {
            if (field.layout_slot !== null && slotIds.has(field.layout_slot)) {
                field.layout_slot = null;
            }
        }

        schema.value.layout = schema.value.layout.filter(
            (item) => item.id !== id,
        );

        if (selection.value?.kind === 'block' && selection.value.id === id) {
            selection.value = null;
        }
    }

    /** Replaces the whole schema, e.g. after an edit in the JSON tab. */
    function replaceSchema(next: ModelSchemaJson) {
        schema.value = normalize(next);

        const uuids = new Set(schema.value.fields.map((field) => field.uuid));

        for (const fieldUuid of autoKeys.value) {
            if (!uuids.has(fieldUuid)) {
                autoKeys.value.delete(fieldUuid);
            }
        }

        if (
            (selection.value?.kind === 'field' && !selectedField.value) ||
            (selection.value?.kind === 'block' && !selectedBlock.value)
        ) {
            selection.value = null;
        }
    }

    /** Records the schema the server now holds. */
    function markSaved(next: ModelSchemaJson) {
        saved.value = normalize(next);
        schema.value = normalize(next);
        autoKeys.value.clear();
    }

    return {
        schema,
        saved,
        selection,
        selectedField,
        selectedBlock,
        slots,
        isDirty,
        typeOf,
        findField,
        findBlock,
        savedField,
        isNew,
        fieldsIn,
        positionOf,
        otherKeys,
        select,
        addField,
        duplicateField,
        updateField,
        updateSetting,
        changeType,
        removeField,
        moveField,
        moveFieldBy,
        addBlock,
        updateBlock,
        moveBlock,
        moveBlockBy,
        addSlot,
        renameSlot,
        removeSlot,
        removeBlock,
        replaceSchema,
        markSaved,
    };
}

export type ModelBuilder = ReturnType<typeof useModelBuilder>;

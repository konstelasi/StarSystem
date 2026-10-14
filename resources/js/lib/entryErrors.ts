import type { FieldSpecJson, ModelSchemaJson } from '@/types/schema';

/** Where a field sits in the form: inside which block's slot, if any. */
export type FieldPlace = {
    field: FieldSpecJson;
    blockId: string | null;
    slotId: string | null;
};

/**
 * Every field in the order the entry form shows them: fields outside any
 * block first, then each block's slots in turn.
 */
export function fieldsInFormOrder(schema: ModelSchemaJson): FieldPlace[] {
    const places: FieldPlace[] = schema.fields
        .filter((field) => field.layout_slot === null)
        .map((field) => ({ field, blockId: null, slotId: null }));

    for (const block of schema.layout) {
        for (const slot of block.slots) {
            for (const field of schema.fields) {
                if (field.layout_slot === slot.id) {
                    places.push({ field, blockId: block.id, slotId: slot.id });
                }
            }
        }
    }

    return places;
}

/**
 * The first field, in form order, that the server reported an error for,
 * so the form can open its tab and focus it.
 */
export function firstError(
    schema: ModelSchemaJson,
    errors: Record<string, string>,
): FieldPlace | null {
    return (
        fieldsInFormOrder(schema).find(
            (place) => errors[place.field.key] !== undefined,
        ) ?? null
    );
}

/** How many errors each slot holds, for marking tabs. */
export function errorsPerSlot(
    schema: ModelSchemaJson,
    errors: Record<string, string>,
): Record<string, number> {
    const counts: Record<string, number> = {};

    for (const place of fieldsInFormOrder(schema)) {
        if (place.slotId !== null && errors[place.field.key] !== undefined) {
            counts[place.slotId] = (counts[place.slotId] ?? 0) + 1;
        }
    }

    return counts;
}

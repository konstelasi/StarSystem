import { expect, test } from 'vite-plus/test';
import { errorsPerSlot, firstError } from '@/lib/entryErrors';
import type { FieldSpecJson, ModelSchemaJson } from '@/types/schema';

function field(key: string, slot: string | null): FieldSpecJson {
    return {
        uuid: `uuid-${key}`,
        key,
        label: key,
        type: 'text',
        required: true,
        filterable: false,
        shown_in_list: false,
        layout_slot: slot,
        settings: {},
    } as FieldSpecJson;
}

// title sits outside any block; a tabs block holds "summary" on its first
// tab and "body" and "byline" on its second.
const schema: ModelSchemaJson = {
    version: 1,
    model: { slug: 'articles', label: 'Articles' },
    layout: [
        {
            id: 'tabs-1',
            kind: 'tabs',
            slots: [{ id: 'tab-a' }, { id: 'tab-b' }],
        },
    ],
    fields: [
        field('body', 'tab-b'),
        field('title', null),
        field('byline', 'tab-b'),
        field('summary', 'tab-a'),
    ],
};

test('the first error is found in form order, not schema order', () => {
    expect(firstError(schema, { body: 'x', summary: 'x' })?.field.key).toBe(
        'summary',
    );
    expect(firstError(schema, { body: 'x', title: 'x' })?.field.key).toBe(
        'title',
    );
});

test('the first error knows its tab', () => {
    const place = firstError(schema, { byline: 'x' });

    expect(place?.blockId).toBe('tabs-1');
    expect(place?.slotId).toBe('tab-b');
});

test('no errors means nothing to open', () => {
    expect(firstError(schema, {})).toBeNull();
});

test('errors are counted per tab', () => {
    expect(
        errorsPerSlot(schema, { body: 'x', byline: 'x', title: 'x' }),
    ).toEqual({ 'tab-b': 2 });
});

import { expect, test } from 'vite-plus/test';
import { useModelBuilder } from '@/composables/useModelBuilder';
import {
    keyFromLabel,
    parseSchemaJson,
    uniqueKey,
    uuid,
} from '@/lib/modelSchema';
import type { FieldTypeDescriptor, ModelSchemaJson } from '@/types/schema';

const types: FieldTypeDescriptor[] = [
    {
        key: 'text',
        label: 'Text',
        icon: 'type',
        category: 'basic',
        settings: [
            { key: 'placeholder', label: 'P', input: 'text', default: '' },
        ],
        storage: { declaredType: 'string', canFilter: true },
    },
    {
        key: 'number',
        label: 'Number',
        icon: 'hash',
        category: 'number',
        settings: [
            { key: 'whole', label: 'W', input: 'boolean', default: false },
            { key: 'placeholder', label: 'P', input: 'text', default: 'x' },
        ],
        storage: { declaredType: 'numeric', canFilter: true },
    },
    {
        key: 'textarea',
        label: 'Long text',
        icon: 'x',
        category: 'basic',
        settings: [],
        storage: { declaredType: 'string', canFilter: false },
    },
];

const base: ModelSchemaJson = {
    version: 1,
    model: { slug: 'a', label: 'A' },
    layout: [{ id: 'sec', kind: 'section', label: 'S', slots: [{ id: 's1' }] }],
    fields: [
        {
            uuid: 'u1',
            key: 'title',
            label: 'Title',
            type: 'text',
            required: true,
            filterable: false,
            shown_in_list: false,
            layout_slot: null,
            settings: {},
        },
        {
            uuid: 'u2',
            key: 'body',
            label: 'Body',
            type: 'textarea',
            required: false,
            filterable: false,
            shown_in_list: false,
            layout_slot: 's1',
            settings: {},
        },
        {
            uuid: 'u3',
            key: 'price',
            label: 'Price',
            type: 'number',
            required: false,
            filterable: true,
            shown_in_list: false,
            layout_slot: null,
            settings: {},
        },
    ],
};

test('keys', () => {
    expect(keyFromLabel('Date of birth')).toBe('date_of_birth');
    expect(keyFromLabel('  Café Crème! ')).toBe('cafe_creme');
    expect(keyFromLabel('2nd line')).toBe('field_2nd_line');
    expect(keyFromLabel('!!!')).toBe('field');
    expect(uniqueKey('text', ['text', 'text_2'])).toBe('text_3');
    expect(uuid()).toMatch(
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
});

test('add, auto key, rename keeps uuid', () => {
    const b = useModelBuilder(base, types);
    const f = b.addField('text')!;
    expect(f.key).toBe('text');
    expect(b.schema.value.fields.map((x) => x.key)).toEqual([
        'title',
        'body',
        'price',
        'text',
    ]);
    b.updateField(f.uuid, { label: 'Title' });
    expect(b.findField(f.uuid)!.key).toBe('title_2');
    b.updateField(f.uuid, { key: 'subtitle' });
    b.updateField(f.uuid, { label: 'Other' });
    expect(b.findField(f.uuid)!.key).toBe('subtitle');
    // existing field: label change doesn't touch key
    b.updateField('u1', { label: 'Headline' });
    expect(b.findField('u1')!.key).toBe('title');
    b.updateField('u1', { key: 'headline' });
    expect(b.findField('u1')!.uuid).toBe('u1');
    expect(b.isDirty.value).toBe(true);
});

test('move between slots and within', () => {
    const b = useModelBuilder(base, types);
    b.moveField('u3', 's1', 0);
    expect(b.fieldsIn('s1').map((x) => x.uuid)).toEqual(['u3', 'u2']);
    expect(b.fieldsIn(null).map((x) => x.uuid)).toEqual(['u1']);
    b.moveField('u3', 's1', 5);
    expect(b.fieldsIn('s1').map((x) => x.uuid)).toEqual(['u2', 'u3']);
    expect(b.moveFieldBy('u3', -1)).toEqual({ index: 0, total: 2 });
    expect(b.fieldsIn('s1').map((x) => x.uuid)).toEqual(['u3', 'u2']);
    expect(b.moveFieldBy('u3', -1)).toEqual({ index: 0, total: 2 });
    b.addField('text', 's1', 1);
    expect(b.fieldsIn('s1').map((x) => x.key)).toEqual([
        'price',
        'text',
        'body',
    ]);
    b.removeBlock('sec');
    expect(b.fieldsIn(null).length).toBe(4);
    expect(b.schema.value.layout.length).toBe(0);
});

test('unknown slots are ignored', () => {
    const b = useModelBuilder(base, types);
    b.moveField('u1', 'layout', 0);
    expect(b.findField('u1')!.layout_slot).toBe(null);
    expect(b.addField('text', 'nope')).toBeUndefined();
    const empty = useModelBuilder({ ...base, layout: [], fields: [] }, types);
    expect(empty.addField('text')?.key).toBe('text');
});

test('retype keeps shared settings, clears filterable', () => {
    const b = useModelBuilder(base, types);
    b.updateSetting('u1', 'placeholder', 'Hello');
    b.changeType('u1', 'number');
    expect(b.findField('u1')!.settings).toEqual({
        whole: false,
        placeholder: 'Hello',
    });
    b.changeType('u3', 'textarea');
    expect(b.findField('u3')!.filterable).toBe(false);
});

test('duplicate and blocks', () => {
    const b = useModelBuilder(base, types);
    const c = b.duplicateField('u1')!;
    expect(c.uuid).not.toBe('u1');
    expect(c.key).toBe('title_copy');
    expect(b.fieldsIn(null).map((x) => x.uuid)).toEqual(['u1', c.uuid, 'u3']);
    const t = b.addBlock('tabs', 0);
    expect(b.schema.value.layout[0].id).toBe(t.id);
    b.addSlot(t.id);
    expect(t.slots.length).toBe(3);
    b.moveField('u1', t.slots[2].id, 0);
    b.removeSlot(t.id, t.slots[2].id);
    expect(b.findField('u1')!.layout_slot).toBe(t.slots[0].id);
    expect(b.moveBlockBy(t.id, 1)).toEqual({ index: 1, total: 2 });
    b.markSaved(b.schema.value);
    expect(b.isDirty.value).toBe(false);
});

test('parseSchemaJson', () => {
    expect(parseSchemaJson('not json').ok).toBe(false);
    expect(parseSchemaJson('[]').ok).toBe(false);
    expect(parseSchemaJson(JSON.stringify({ ...base, version: 2 })).ok).toBe(
        false,
    );
    expect(
        parseSchemaJson(JSON.stringify({ ...base, model: { slug: 'a' } })).ok,
    ).toBe(false);
    expect(
        parseSchemaJson(JSON.stringify({ ...base, layout: 'nope' })).ok,
    ).toBe(false);
    expect(
        parseSchemaJson(JSON.stringify({ ...base, fields: [{ uuid: 'only' }] }))
            .ok,
    ).toBe(false);

    const result = parseSchemaJson(JSON.stringify(base));
    expect(result.ok).toBe(true);
    if (result.ok) {
        expect(result.value).toEqual(base);
    }
});

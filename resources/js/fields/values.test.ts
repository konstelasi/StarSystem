import { expect, test } from 'vite-plus/test';
import { formValue } from '@/fields/values';
import type { FieldSpecJson } from '@/types/schema';

function field(
    type: string,
    settings: Record<string, unknown> = {},
): FieldSpecJson {
    return {
        uuid: '10000000-0000-4000-8000-000000000001',
        key: 'value',
        label: 'Value',
        type,
        required: false,
        filterable: false,
        layout_slot: null,
        settings,
    } as FieldSpecJson;
}

test('a stored checkbox becomes a boolean', () => {
    expect(formValue(field('checkbox'), 1)).toBe(true);
    expect(formValue(field('checkbox'), 0)).toBe(false);
});

test('a stored datetime is read as UTC', () => {
    expect(formValue(field('datetime'), '2026-10-14 05:30:00')).toBe(
        '2026-10-14T05:30:00Z',
    );
});

test('a stored date-only value keeps just the date', () => {
    expect(
        formValue(
            field('datetime', { date_only: true }),
            '2026-10-14 00:00:00',
        ),
    ).toBe('2026-10-14');
});

test('a missing value starts empty, as in a new entry', () => {
    expect(formValue(field('text'), undefined)).toBe('');
    expect(formValue(field('checkboxes'), null)).toEqual([]);
});

test('other values pass through unchanged', () => {
    expect(formValue(field('text'), 'Hello')).toBe('Hello');
    expect(formValue(field('number'), 42)).toBe(42);
    expect(formValue(field('checkboxes'), ['a', 'b'])).toEqual(['a', 'b']);
});

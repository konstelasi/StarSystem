import { expect, test } from 'vite-plus/test';
import { CELL_MAX_LENGTH, cellText, EMPTY_CELL } from '@/fields/display';
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

const utc = { locale: 'en-GB', timeZone: 'UTC' };

test('empty values show a dash', () => {
    expect(cellText(field('text'), null)).toBe(EMPTY_CELL);
    expect(cellText(field('text'), '')).toBe(EMPTY_CELL);
    expect(cellText(field('checkboxes'), [])).toBe(EMPTY_CELL);
});

test('a checkbox reads Yes or No', () => {
    expect(cellText(field('checkbox'), 1)).toBe('Yes');
    expect(cellText(field('checkbox'), 0)).toBe('No');
});

test('a datetime is shown from its stored UTC time', () => {
    expect(cellText(field('datetime'), '2026-10-14 05:30:00', {}, utc)).toBe(
        '14/10/2026, 05:30:00',
    );
});

test('a date-only value keeps its day', () => {
    expect(
        cellText(
            field('datetime', { date_only: true }),
            '2026-10-14 00:00:00',
            {},
            { locale: 'en-GB' },
        ),
    ).toBe('14/10/2026');
});

test('options show their labels', () => {
    const options = {
        options: {
            kind: 'static',
            options: [
                { value: 'id', label: 'Indonesia' },
                { value: 'my', label: 'Malaysia' },
            ],
        },
    };

    expect(cellText(field('select', options), 'id')).toBe('Indonesia');
    expect(cellText(field('checkboxes', options), ['id', 'my'])).toBe(
        'Indonesia, Malaysia',
    );
    // A value no option has any more still shows.
    expect(cellText(field('radio', options), 'sg')).toBe('sg');
});

test('files show their names', () => {
    const names = { 'aaaa-1111': 'team-photo.jpg' };

    expect(
        cellText(
            field('file', { multiple: true }),
            ['AAAA-1111', 'bbbb'],
            names,
        ),
    ).toBe('team-photo.jpg, Missing file');
});

test('rich text loses its tags', () => {
    expect(
        cellText(
            field('rich_text'),
            '<p>Hello <b>world</b></p><p>Tom &amp; Jerry</p>',
        ),
    ).toBe('Hello world Tom & Jerry');
});

test('long text is cut', () => {
    const shown = cellText(field('textarea'), 'word '.repeat(40));

    expect(shown.length).toBeLessThanOrEqual(CELL_MAX_LENGTH);
    expect(shown.endsWith('…')).toBe(true);
});

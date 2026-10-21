import { expect, test } from 'vite-plus/test';
import { CELL_MAX_LENGTH, cellText, EMPTY_CELL } from '@/fields/display';
import type { FieldSpecJson, LinkLabel } from '@/types/schema';

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

test('empty values show the word None', () => {
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
        cellText(field('file', { multiple: true }), ['AAAA-1111', 'bbbb'], {
            files: names,
        }),
    ).toBe('team-photo.jpg, Missing file');
});

const links: Record<string, LinkLabel> = {
    '3': { label: 'Ada', state: 'ok' },
    '7': { label: 'Grace (in trash)', state: 'trashed' },
    '9': { label: 'Deleted entry', state: 'missing' },
};

test('a linked entry shows the text that names it', () => {
    expect(cellText(field('relation'), 3, { links })).toBe('Ada');
    expect(cellText(field('relation'), '3', { links })).toBe('Ada');
    expect(
        cellText(field('relation', { multiple: true }), [3, 7, 9], { links }),
    ).toBe('Ada, Grace (in trash), Deleted entry');
});

test('a linked entry nobody named shows its id', () => {
    expect(cellText(field('relation'), 12, { links })).toBe('Entry 12');
    expect(cellText(field('relation'), 12)).toBe('Entry 12');
});

test('a cell names five linked entries and counts the rest', () => {
    const many = Object.fromEntries(
        [1, 2, 3, 4, 5].map((id) => [
            String(id),
            { label: `Author ${id}`, state: 'ok' } as LinkLabel,
        ]),
    );

    expect(
        cellText(field('relation', { multiple: true }), [1, 2, 3, 4, 5, 6, 7], {
            links: many,
        }),
    ).toBe('Author 1, Author 2, Author 3, Author 4, Author 5 +2 more');
});

test('a lone id and repeats in a list read as one entry each', () => {
    expect(
        cellText(field('relation', { multiple: true }), [3, 3, '3'], { links }),
    ).toBe('Ada');
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

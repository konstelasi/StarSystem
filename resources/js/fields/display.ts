import {
    asStringList,
    booleanSetting,
    optionsSourceSetting,
    staticOptions,
} from '@/fields/support';
import { formValue } from '@/fields/values';
import type { FieldSpecJson } from '@/types/schema';

/** Longest text a list cell shows before cutting it with "…". */
export const CELL_MAX_LENGTH = 80;

/** What a list cell shows for a value that was never filled in. */
export const EMPTY_CELL = 'None';

type CellOptions = {
    /** For tests: a fixed language and time zone instead of the browser's. */
    locale?: string;
    timeZone?: string;
};

/**
 * A stored value as one short line of text for the entries list: a
 * checkbox as Yes or No, a date in the reader's own time, an option by its
 * label, a file by its name, rich text without its tags.
 *
 * `fileNames` maps file uuids to their names; the server sends the names
 * of the files each row points at.
 */
export function cellText(
    field: FieldSpecJson,
    value: unknown,
    fileNames: Record<string, string> = {},
    options: CellOptions = {},
): string {
    if (isEmpty(value)) {
        return EMPTY_CELL;
    }

    return truncate(format(field, value, fileNames, options));
}

function format(
    field: FieldSpecJson,
    value: unknown,
    fileNames: Record<string, string>,
    { locale, timeZone }: CellOptions,
): string {
    switch (field.type) {
        case 'checkbox':
            return formValue(field, value) === true ? 'Yes' : 'No';

        case 'datetime': {
            const local = formValue(field, value);

            if (typeof local !== 'string' || local === '') {
                return String(value);
            }

            if (booleanSetting(field, 'date_only')) {
                // Built from the parts, so no time zone shifts the day.
                const [year, month, day] = local.split('-').map(Number);

                return new Date(year, month - 1, day).toLocaleDateString(
                    locale,
                );
            }

            const date = new Date(local);

            return Number.isNaN(date.getTime())
                ? String(value)
                : date.toLocaleString(locale, { timeZone });
        }

        case 'select':
        case 'radio':
        case 'checkboxes': {
            const labels = new Map(
                (staticOptions(optionsSourceSetting(field)) ?? []).map(
                    (option) => [option.value, option.label],
                ),
            );

            return asStringList(value)
                .map((item) => labels.get(item) ?? item)
                .join(', ');
        }

        case 'file':
            return asStringList(value)
                .map((uuid) => fileNames[uuid.toLowerCase()] ?? 'Missing file')
                .join(', ');

        case 'rich_text':
            return plainText(String(value));

        default:
            return Array.isArray(value)
                ? value.map(String).join(', ')
                : String(value).replace(/\s+/g, ' ').trim();
    }
}

function isEmpty(value: unknown): boolean {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

const ENTITIES: Record<string, string> = {
    '&amp;': '&',
    '&lt;': '<',
    '&gt;': '>',
    '&quot;': '"',
    '&#39;': "'",
    '&nbsp;': ' ',
};

/**
 * Rich text's words without its markup. Only for display: the result is
 * still rendered as text, never as HTML.
 */
function plainText(html: string): string {
    return html
        .replace(/<(br|\/p|\/li|\/h\d)\s*\/?>/gi, ' ')
        .replace(/<[^>]*>/g, '')
        .replace(/&(amp|lt|gt|quot|#39|nbsp);/g, (entity) => ENTITIES[entity])
        .replace(/\s+/g, ' ')
        .trim();
}

function truncate(text: string): string {
    return text.length > CELL_MAX_LENGTH
        ? `${text.slice(0, CELL_MAX_LENGTH - 1).trimEnd()}…`
        : text;
}

import {
    asNumber,
    asStringList,
    booleanSetting,
    optionsSourceSetting,
    staticOptions,
} from '@/fields/support';
import { formValue } from '@/fields/values';
import type { FieldSpecJson, LinkLabel } from '@/types/schema';

/** Longest text a list cell shows before cutting it with "…". */
export const CELL_MAX_LENGTH = 80;

/** What a list cell shows for a value that was never filled in. */
export const EMPTY_CELL = 'None';

/**
 * Most linked entries a list cell names. The server names no more than this
 * for a cell (EntryLinks::PER_CELL), so change both together.
 */
export const CELL_LINKS = 5;

type CellOptions = {
    /** For tests: a fixed language and time zone instead of the browser's. */
    locale?: string;
    timeZone?: string;
};

/**
 * What the server sends for a row to name what its values point at.
 * `files` maps file uuids to their names, `links` maps linked entry ids
 * to how they read.
 */
export type CellNames = {
    files?: Record<string, string>;
    links?: Record<string, LinkLabel>;
};

/**
 * A stored value as one short line of text for the entries list: a
 * checkbox as Yes or No, a date in the reader's own time, an option by its
 * label, a file by its name, a linked entry by the text that names it,
 * rich text without its tags.
 */
export function cellText(
    field: FieldSpecJson,
    value: unknown,
    names: CellNames = {},
    options: CellOptions = {},
): string {
    if (isEmpty(value)) {
        return EMPTY_CELL;
    }

    return truncate(format(field, value, names, options));
}

function format(
    field: FieldSpecJson,
    value: unknown,
    names: CellNames,
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
                .map(
                    (uuid) =>
                        names.files?.[uuid.toLowerCase()] ?? 'Missing file',
                )
                .join(', ');

        case 'relation': {
            const ids = linkIds(value);
            const named = ids
                .slice(0, CELL_LINKS)
                .map((id) => names.links?.[String(id)]?.label ?? `Entry ${id}`);
            const more = ids.length - named.length;

            return more > 0
                ? `${named.join(', ')} +${more} more`
                : named.join(', ');
        }

        case 'rich_text':
            return plainText(String(value));

        default:
            return Array.isArray(value)
                ? value.map(String).join(', ')
                : String(value).replace(/\s+/g, ' ').trim();
    }
}

/** The entry ids a relation holds, whether stored as one id or a list. */
function linkIds(value: unknown): number[] {
    const found = (Array.isArray(value) ? value : [value])
        .map(asNumber)
        .filter(
            (id): id is number => id !== null && Number.isInteger(id) && id > 0,
        );

    return [...new Set(found)];
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

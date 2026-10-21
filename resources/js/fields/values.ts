import { booleanSetting } from '@/fields/support';
import type { FieldSpecJson } from '@/types/schema';

/**
 * The value a new, empty entry starts with: the field's `default` setting
 * where the type has one, otherwise the type's empty value.
 */
export function initialValue(field: FieldSpecJson): unknown {
    const fallback = field.settings.default;

    switch (field.type) {
        case 'checkbox':
            return fallback === true;
        case 'checkboxes':
            return [];
        case 'select':
        case 'file':
        case 'relation':
            return booleanSetting(field, 'multiple') ? [] : null;
        case 'radio':
        case 'number':
        case 'range':
        case 'datetime':
            return null;
        default:
            return typeof fallback === 'string' ? fallback : '';
    }
}

/**
 * A stored value in the shape the field's input expects, for editing an
 * existing entry. A missing value starts as a new entry's would.
 *
 * Three types are stored differently from how their inputs speak:
 * - checkbox: stored `1` or `0`, the input wants `true` or `false`.
 * - datetime: stored `Y-m-d H:i:s` in UTC with no zone, which a browser
 *   would read as local time; date-only fields store `Y-m-d 00:00:00`,
 *   which would fail the `Y-m-d` check when saved unchanged.
 * - relation: one id stored before "several" was switched on is a lone
 *   number, and the input wants a list.
 */
export function formValue(field: FieldSpecJson, stored: unknown): unknown {
    if (stored === null || stored === undefined) {
        return initialValue(field);
    }

    switch (field.type) {
        case 'checkbox':
            return stored === true || stored === 1 || stored === '1';
        case 'datetime': {
            if (typeof stored !== 'string') {
                return null;
            }

            if (booleanSetting(field, 'date_only')) {
                return stored.slice(0, 10);
            }

            return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(stored)
                ? `${stored.replace(' ', 'T')}Z`
                : stored;
        }
        case 'relation':
            return booleanSetting(field, 'multiple') &&
                typeof stored === 'number'
                ? [stored]
                : stored;
        default:
            return stored;
    }
}

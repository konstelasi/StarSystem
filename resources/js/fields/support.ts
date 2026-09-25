import { computed } from 'vue';
import type { FieldSpecJson, OptionsSource } from '@/types/schema';

/**
 * Props every field renderer takes. The builder preview and the entry forms
 * render fields through the same components, so this shape is shared.
 */
export type FieldRendererProps = {
    field: FieldSpecJson;
    modelValue: unknown;
    error?: string;
    disabled?: boolean;
};

export type Option = { value: string; label: string };

/** Stable DOM ids for a field's control, label, helper text and error. */
export function useFieldIds(props: { field: FieldSpecJson; error?: string }) {
    const id = computed(() => `field-${props.field.uuid}`);
    const labelId = computed(() => `${id.value}-label`);
    const helperId = computed(() => `${id.value}-helper`);
    const errorId = computed(() => `${id.value}-error`);
    const describedBy = computed(() => {
        const ids = [
            props.field.helper ? helperId.value : null,
            props.error ? errorId.value : null,
        ].filter((value): value is string => value !== null);

        return ids.length > 0 ? ids.join(' ') : undefined;
    });

    return { id, labelId, helperId, errorId, describedBy };
}

export function stringSetting(
    field: FieldSpecJson,
    key: string,
): string | null {
    const value = field.settings[key];

    return typeof value === 'string' && value !== '' ? value : null;
}

export function numberSetting(
    field: FieldSpecJson,
    key: string,
): number | null {
    const value = field.settings[key];

    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string' && value.trim() !== '') {
        const parsed = Number(value);

        return Number.isFinite(parsed) ? parsed : null;
    }

    return null;
}

export function booleanSetting(field: FieldSpecJson, key: string): boolean {
    return field.settings[key] === true;
}

export function stringListSetting(field: FieldSpecJson, key: string): string[] {
    const value = field.settings[key];

    return Array.isArray(value)
        ? value.filter(
              (item): item is string => typeof item === 'string' && item !== '',
          )
        : [];
}

/** Reads an options source setting, tolerating half-edited values. */
export function optionsSourceSetting(
    field: FieldSpecJson,
    key = 'options',
): OptionsSource {
    return toOptionsSource(field.settings[key]);
}

export function toOptionsSource(value: unknown): OptionsSource {
    if (typeof value !== 'object' || value === null) {
        return { kind: 'static', options: [] };
    }

    const source = value as Record<string, unknown>;

    if (source.kind === 'model') {
        return {
            kind: 'model',
            model: typeof source.model === 'string' ? source.model : '',
            label_field:
                typeof source.label_field === 'string'
                    ? source.label_field
                    : '',
        };
    }

    if (source.kind === 'hook') {
        return {
            kind: 'hook',
            name: typeof source.name === 'string' ? source.name : '',
        };
    }

    const options = Array.isArray(source.options) ? source.options : [];

    return {
        kind: 'static',
        options: options
            .filter(
                (option): option is Record<string, unknown> =>
                    typeof option === 'object' && option !== null,
            )
            .map((option) => {
                const optionValue = asString(option.value);
                const label = asString(option.label);

                return { value: optionValue, label: label || optionValue };
            }),
    };
}

/**
 * The options a renderer can show right away. Options that come from another
 * model or from a module are only known at runtime, so they return null.
 */
export function staticOptions(source: OptionsSource): Option[] | null {
    if (source.kind !== 'static') {
        return null;
    }

    // Choice controls can't hold an empty value, so skip unfinished rows.
    return source.options.filter((option) => option.value !== '');
}

export function asString(value: unknown): string {
    if (typeof value === 'string') {
        return value;
    }

    return typeof value === 'number' ? String(value) : '';
}

export function asStringList(value: unknown): string[] {
    if (Array.isArray(value)) {
        return value.filter((item): item is string => typeof item === 'string');
    }

    return typeof value === 'string' && value !== '' ? [value] : [];
}

export function asNumber(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string' && value.trim() !== '') {
        const parsed = Number(value);

        return Number.isFinite(parsed) ? parsed : null;
    }

    return null;
}

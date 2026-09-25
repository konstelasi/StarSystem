import type { Component } from 'vue';
import CheckboxField from '@/fields/checkbox.vue';
import CheckboxesField from '@/fields/checkboxes.vue';
import CodeField from '@/fields/code.vue';
import ColorField from '@/fields/color.vue';
import DatetimeField from '@/fields/datetime.vue';
import EmailField from '@/fields/email.vue';
import FileField from '@/fields/file.vue';
import HiddenField from '@/fields/hidden.vue';
import NumberField from '@/fields/number.vue';
import UnknownField from '@/fields/parts/UnknownField.vue';
import RadioField from '@/fields/radio.vue';
import RangeField from '@/fields/range.vue';
import RichTextField from '@/fields/rich_text.vue';
import SelectField from '@/fields/select.vue';
import { booleanSetting } from '@/fields/support';
import TextField from '@/fields/text.vue';
import TextareaField from '@/fields/textarea.vue';
import UrlField from '@/fields/url.vue';
import type { FieldSpecJson } from '@/types/schema';

export type { FieldRendererProps } from '@/fields/support';

/**
 * Field type key → renderer. The builder preview and the entry forms both
 * render through this map. Every renderer takes
 * `{ field, modelValue, error?, disabled? }` and emits `update:modelValue`.
 */
export const fieldRenderers: Record<string, Component> = {
    text: TextField,
    email: EmailField,
    url: UrlField,
    color: ColorField,
    hidden: HiddenField,
    radio: RadioField,
    select: SelectField,
    number: NumberField,
    range: RangeField,
    checkbox: CheckboxField,
    datetime: DatetimeField,
    textarea: TextareaField,
    rich_text: RichTextField,
    code: CodeField,
    checkboxes: CheckboxesField,
    file: FileField,
};

export function rendererFor(type: string): Component {
    return fieldRenderers[type] ?? UnknownField;
}

export function hasRenderer(type: string): boolean {
    return type in fieldRenderers;
}

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
            return booleanSetting(field, 'multiple') ? [] : null;
        case 'file':
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

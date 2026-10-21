import { expect, test } from 'vite-plus/test';
import {
    canLabel,
    defaultLabelField,
    labelFieldChoices,
    modelSlugFromLabel,
    modelSlugProblem,
    toModelLink,
} from '@/lib/modelSchema';

const field = (
    uuid: string,
    type: string,
    settings: Record<string, unknown> = {},
) => ({ uuid, key: uuid, label: uuid, type, settings });

test('modelSlugFromLabel slugifies a label', () => {
    expect(modelSlugFromLabel('News Article')).toBe('news_article');
});

test('modelSlugFromLabel falls back to "model" for an empty label', () => {
    expect(modelSlugFromLabel('!!!')).toBe('model');
});

test('modelSlugFromLabel prefixes a slug that would start with a digit', () => {
    expect(modelSlugFromLabel('2026 Report')).toBe('model_2026_report');
});

test('modelSlugFromLabel truncates to the max length', () => {
    const label = 'a'.repeat(200);

    expect(modelSlugFromLabel(label).length).toBe(128);
});

test('modelSlugProblem rejects an empty address name', () => {
    expect(modelSlugProblem('', [])).not.toBeNull();
});

test('modelSlugProblem rejects a pattern violation', () => {
    expect(modelSlugProblem('Not Valid', [])).not.toBeNull();
    expect(modelSlugProblem('1starts_with_digit', [])).not.toBeNull();
});

test('modelSlugProblem rejects a slug already in use', () => {
    expect(modelSlugProblem('articles', ['articles', 'pages'])).not.toBeNull();
});

test('modelSlugProblem accepts a free, valid slug', () => {
    expect(modelSlugProblem('articles', ['pages'])).toBeNull();
    expect(modelSlugProblem('news-article', [])).toBeNull();
});

test('canLabel accepts the types that can name an entry', () => {
    for (const type of [
        'text',
        'textarea',
        'email',
        'url',
        'hidden',
        'number',
        'radio',
    ]) {
        expect(canLabel(field('a', type))).toBe(true);
    }
});

test('canLabel refuses types that hold no single readable value', () => {
    for (const type of [
        'rich_text',
        'file',
        'checkboxes',
        'datetime',
        'relation',
    ]) {
        expect(canLabel(field('a', type))).toBe(false);
    }
});

test('canLabel accepts a select only with a single choice', () => {
    expect(canLabel(field('a', 'select'))).toBe(true);
    expect(canLabel(field('a', 'select', { multiple: false }))).toBe(true);
    expect(canLabel(field('a', 'select', { multiple: true }))).toBe(false);
});

test('labelFieldChoices keeps the fields that can name an entry, in order', () => {
    const fields = [
        field('body', 'rich_text'),
        field('title', 'text'),
        field('tags', 'select', { multiple: true }),
        field('slug', 'text'),
    ];

    expect(labelFieldChoices(fields).map((item) => item.uuid)).toEqual([
        'title',
        'slug',
    ]);
});

test('defaultLabelField is the first choice, or null when there is none', () => {
    expect(
        defaultLabelField([field('body', 'rich_text'), field('title', 'text')]),
    ).toBe('title');
    expect(defaultLabelField([field('body', 'rich_text')])).toBeNull();
    expect(defaultLabelField([])).toBeNull();
});

test('toModelLink reads a complete link', () => {
    expect(toModelLink({ model: 7, label_field: 'abc' })).toEqual({
        model: 7,
        label_field: 'abc',
    });
});

test('toModelLink reads a model sent as a string by a select', () => {
    expect(toModelLink({ model: '7', label_field: 'abc' }).model).toBe(7);
});

test('toModelLink reads a half-edited or odd value as nothing chosen', () => {
    const empty = { model: null, label_field: null };

    expect(toModelLink(null)).toEqual(empty);
    expect(toModelLink(undefined)).toEqual(empty);
    expect(toModelLink('7')).toEqual(empty);
    expect(toModelLink([7])).toEqual(empty);
    expect(toModelLink({ model: '', label_field: '' })).toEqual(empty);
    expect(toModelLink({ model: 0, label_field: 3 })).toEqual(empty);
    expect(toModelLink({ model: 1.5 })).toEqual(empty);
    expect(toModelLink({ model: 'seven' })).toEqual(empty);
});

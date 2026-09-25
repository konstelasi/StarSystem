import { expect, test } from 'vite-plus/test';
import { modelSlugFromLabel, modelSlugProblem } from '@/lib/modelSchema';

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

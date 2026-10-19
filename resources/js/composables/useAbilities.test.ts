import { expect, test } from 'vite-plus/test';
import { holds } from '@/composables/useAbilities';

const everywhere = ['media.view', 'entries.view'];
const byModel = { 7: ['entries.update'] };

test('an ability held for every model is held for each model', () => {
    expect(holds(everywhere, byModel, 'entries.view')).toBe(true);
    expect(holds(everywhere, byModel, 'entries.view', 7)).toBe(true);
    expect(holds(everywhere, byModel, 'entries.view', 8)).toBe(true);
});

test('an ability held for one model is held for that model only', () => {
    expect(holds(everywhere, byModel, 'entries.update', 7)).toBe(true);
    expect(holds(everywhere, byModel, 'entries.update', 8)).toBe(false);
    expect(holds(everywhere, byModel, 'entries.update')).toBe(false);
});

test('a page with no model grants still answers', () => {
    expect(holds(everywhere, undefined, 'entries.update', 7)).toBe(false);
    expect(holds(everywhere, undefined, 'media.view')).toBe(true);
});

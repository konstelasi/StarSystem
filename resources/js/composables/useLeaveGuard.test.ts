import { expect, test } from 'vite-plus/test';
import { leavesPage } from '@/composables/useLeaveGuard';

const visit = {
    method: 'get',
    only: [] as string[],
    except: [] as string[],
    prefetch: false,
};

test('a plain link to another page leaves it', () => {
    expect(leavesPage(visit)).toBe(true);
});

test('saving or submitting stays on the page', () => {
    expect(leavesPage({ ...visit, method: 'post' })).toBe(false);
    expect(leavesPage({ ...visit, method: 'delete' })).toBe(false);
});

test('a partial reload, such as the badge poll, stays on the page', () => {
    expect(leavesPage({ ...visit, only: ['states'] })).toBe(false);
    expect(leavesPage({ ...visit, except: ['schema'] })).toBe(false);
});

test('a hover prefetch never asks', () => {
    expect(leavesPage({ ...visit, prefetch: true })).toBe(false);
});

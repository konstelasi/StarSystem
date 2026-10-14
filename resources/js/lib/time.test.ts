import { expect, test } from 'vite-plus/test';
import { relativeTime } from '@/lib/time';

const now = Date.parse('2026-10-14T12:00:00Z');

test('under a minute reads as just now', () => {
    expect(relativeTime('2026-10-14T11:59:30Z', now, 'en')).toBe('just now');
});

test('the largest whole unit is used', () => {
    expect(relativeTime('2026-10-14T09:00:00Z', now, 'en')).toBe('3 hours ago');
    expect(relativeTime('2026-10-11T12:00:00Z', now, 'en')).toBe('3 days ago');
});

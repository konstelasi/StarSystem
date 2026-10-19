import { expect, test } from 'vite-plus/test';
import { reachOf, withReach, withTicked } from '@/lib/abilityReach';
import type { RoleAbility } from '@/types/roles';

const update: RoleAbility = {
    key: 'entries.update',
    label: 'Edit entries',
    held: true,
    own: { key: 'entries.update_own', label: 'Edit own entries', held: true },
};

const plain: RoleAbility = { key: 'entries.view', label: 'See', held: true };

test('an ability held in full reads as all, even beside its own form', () => {
    expect(reachOf(['entries.update'], update)).toBe('all');
    expect(reachOf(['entries.update', 'entries.update_own'], update)).toBe(
        'all',
    );
});

test('only the own form reads as own, and neither reads as none', () => {
    expect(reachOf(['entries.update_own'], update)).toBe('own');
    expect(reachOf(['entries.view'], update)).toBe('none');
});

test('choosing a reach swaps the two forms and keeps everything else', () => {
    const start = ['entries.view', 'entries.update'];

    expect(withReach(start, update, 'own')).toEqual([
        'entries.view',
        'entries.update_own',
    ]);
    expect(withReach(start, update, 'none')).toEqual(['entries.view']);
    expect(withReach(['entries.update_own'], update, 'all')).toEqual([
        'entries.update',
    ]);
});

test('an ability without an own form ignores a reach', () => {
    expect(withReach(['entries.view'], plain, 'none')).toEqual([
        'entries.view',
    ]);
});

test('ticking adds once and unticking removes', () => {
    expect(withTicked([], 'entries.view', true)).toEqual(['entries.view']);
    expect(withTicked(['entries.view'], 'entries.view', true)).toEqual([
        'entries.view',
    ]);
    expect(
        withTicked(['entries.view', 'media.view'], 'entries.view', false),
    ).toEqual(['media.view']);
});

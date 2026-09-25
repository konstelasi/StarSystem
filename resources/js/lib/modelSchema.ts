import type {
    FieldState,
    FieldTypeDescriptor,
    LayoutBlock,
} from '@/types/schema';

/** Busy state per field uuid. Fields that aren't listed are ready. */
export type FieldStates = Record<string, FieldState>;

export const KEY_MAX_LENGTH = 128;
const KEY_PATTERN = /^[A-Za-z][A-Za-z0-9_]*$/;

/**
 * A random v4 uuid. `crypto.randomUUID()` only exists on HTTPS or localhost,
 * and shared hosts often serve the admin over plain HTTP, so fall back to
 * `getRandomValues()`, which works everywhere.
 */
export function uuid(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');

    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-');
}

/** A short id for layout blocks and slots, e.g. `tabs_3f9a1c2e`. */
export function shortId(prefix: string): string {
    return `${prefix}_${uuid().replace(/-/g, '').slice(0, 8)}`;
}

export function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

/** Lowercase letters, digits and underscores: "Crème brûlée!" → "creme_brulee". */
export function slugify(text: string): string {
    return text
        .normalize('NFKD')
        .replace(/\p{M}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

/** Turns a label into a key: "Date of birth" becomes "date_of_birth". */
export function keyFromLabel(label: string): string {
    const key = slugify(label).slice(0, 64).replace(/_+$/, '');

    if (key === '') {
        return 'field';
    }

    return /^[a-z]/.test(key) ? key : `field_${key}`;
}

/** The first of `base`, `base_2`, `base_3` and so on that isn't taken. */
export function uniqueKey(base: string, taken: Iterable<string>): string {
    const used = new Set(taken);

    if (!used.has(base)) {
        return base;
    }

    for (let n = 2; ; n++) {
        const candidate = `${base}_${n}`;

        if (!used.has(candidate)) {
            return candidate;
        }
    }
}

/** A plain-language problem with a key, or null when it's fine. */
export function keyProblem(
    key: string,
    otherKeys: Iterable<string>,
): string | null {
    if (key === '') {
        return 'Give this field a key.';
    }

    if (key.startsWith('_')) {
        return 'Keys starting with “_” are reserved.';
    }

    if (!KEY_PATTERN.test(key)) {
        return 'Use letters, numbers and underscores, starting with a letter.';
    }

    if (key.length > KEY_MAX_LENGTH) {
        return `Keep the key under ${KEY_MAX_LENGTH} characters.`;
    }

    for (const other of otherKeys) {
        if (other === key) {
            return 'Another field already uses this key.';
        }
    }

    return null;
}

export function settingDefaults(
    type: FieldTypeDescriptor | undefined,
): Record<string, unknown> {
    const settings: Record<string, unknown> = {};

    for (const setting of type?.settings ?? []) {
        settings[setting.key] = clone(setting.default ?? null);
    }

    return settings;
}

const BLOCK_KIND_LABELS: Record<LayoutBlock['kind'], string> = {
    section: 'Section',
    tabs: 'Tabs',
    columns: 'Columns',
};

export function blockKindLabel(kind: LayoutBlock['kind']): string {
    return BLOCK_KIND_LABELS[kind];
}

export function blockName(block: LayoutBlock): string {
    return block.label || blockKindLabel(block.kind);
}

export function slotName(block: LayoutBlock, index: number): string {
    const fallback =
        block.kind === 'tabs' ? `Tab ${index + 1}` : `Column ${index + 1}`;

    return block.slots[index]?.label || fallback;
}

export function newSlot(block: LayoutBlock): LayoutBlock['slots'][number] {
    const index = block.slots.length;

    if (block.kind === 'tabs') {
        return { id: shortId('tab'), label: `Tab ${index + 1}` };
    }

    return { id: shortId('col'), label: `Column ${index + 1}` };
}

export function newBlock(kind: LayoutBlock['kind']): LayoutBlock {
    switch (kind) {
        case 'section':
            return {
                id: shortId('section'),
                kind,
                label: 'New section',
                slots: [{ id: shortId('slot') }],
            };
        case 'tabs':
            return {
                id: shortId('tabs'),
                kind,
                slots: [
                    { id: shortId('tab'), label: 'Tab 1' },
                    { id: shortId('tab'), label: 'Tab 2' },
                ],
            };
        case 'columns':
            return {
                id: shortId('columns'),
                kind,
                slots: [
                    { id: shortId('col'), label: 'Left' },
                    { id: shortId('col'), label: 'Right' },
                ],
            };
    }
}

export type SlotChoice = { id: string | null; label: string };

/** Every place a field can live, for "move to" menus. */
export function slotChoices(layout: LayoutBlock[]): SlotChoice[] {
    const choices: SlotChoice[] = [{ id: null, label: 'Main area' }];

    for (const block of layout) {
        block.slots.forEach((slot, index) => {
            const label =
                block.slots.length > 1
                    ? `${blockName(block)} › ${slotName(block, index)}`
                    : blockName(block);
            choices.push({ id: slot.id, label });
        });
    }

    return choices;
}

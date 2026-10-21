import { lookup } from '@/routes/admin/models/entries';

export type LookupEntry = { id: number; label: string };

export type LookupPage = {
    data: LookupEntry[];
    nextCursor: string | null;
    /** Whether `q` narrows the page, which it does only for indexed text. */
    searchable: boolean;
};

/**
 * One page of a model's entries, newest first, each named by the field
 * `label` (a field uuid). This feeds the entry picker of a relation field.
 * A failed answer throws, whatever the reason, since the picker only needs
 * to know that it failed.
 */
export async function lookupEntries(
    modelId: number,
    query: { label: string; q?: string; cursor?: string | null },
): Promise<LookupPage> {
    const route = lookup(modelId, {
        query: {
            label: query.label,
            q: query.q || undefined,
            cursor: query.cursor || undefined,
        },
    });

    const response = await fetch(route.url, {
        method: route.method.toUpperCase(),
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(
            `The entries could not be loaded (${response.status}).`,
        );
    }

    return (await response.json()) as LookupPage;
}

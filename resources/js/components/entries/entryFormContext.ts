import { inject, provide } from 'vue';
import type { InjectionKey, Ref } from 'vue';
import type { LinkLabel } from '@/types/schema';

export type EntryFormContext = {
    /**
     * How the server named each entry the form links to, by entry id as a
     * string. Ids the person picks in this session aren't in it.
     */
    links: Ref<Record<string, LinkLabel>>;
};

export const entryFormKey: InjectionKey<EntryFormContext> =
    Symbol('entry-form');

export function provideEntryForm(links: Ref<Record<string, LinkLabel>>) {
    provide(entryFormKey, { links });
}

/**
 * The context of the entry form this field sits in, or null anywhere else,
 * such as the model builder's preview, which has no entry to name.
 */
export function useEntryFormContext(): EntryFormContext | null {
    return inject<EntryFormContext | null>(entryFormKey, null);
}

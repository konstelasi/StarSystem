import { inject, provide } from 'vue';
import type { InjectionKey, Ref } from 'vue';
import type { LinkLabel } from '@/types/schema';

export type EntryFormContext = {
    /**
     * How the server named each entry the form links to, by relation field
     * key and then entry id as a string. By field, since two fields linking
     * to one model can name its entries by different fields. Ids the person
     * picks in this session aren't in it.
     */
    links: Ref<Record<string, Record<string, LinkLabel>>>;
};

export const entryFormKey: InjectionKey<EntryFormContext> =
    Symbol('entry-form');

export function provideEntryForm(
    links: Ref<Record<string, Record<string, LinkLabel>>>,
) {
    provide(entryFormKey, { links });
}

/**
 * The context of the entry form this field sits in, or null anywhere else,
 * such as the model builder's preview, which has no entry to name.
 */
export function useEntryFormContext(): EntryFormContext | null {
    return inject<EntryFormContext | null>(entryFormKey, null);
}

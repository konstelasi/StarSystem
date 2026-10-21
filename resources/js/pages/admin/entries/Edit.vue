<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import EntryForm from '@/components/entries/EntryForm.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAbilities } from '@/composables/useAbilities';
import { useEntryForm } from '@/composables/useEntryForm';
import { builder, index as models } from '@/routes/admin/models';
import { edit, index, update } from '@/routes/admin/models/entries';
import type { LinkLabel, ModelSchemaJson } from '@/types/schema';

const props = defineProps<{
    model: { id: number; label: string };
    schema: ModelSchemaJson;
    entry: {
        id: number;
        created_at: string;
        /** Who made it: a name, a former member, or unknown for an old entry. */
        author: { state: 'known' | 'former' | 'unknown'; name: string | null };
        /** Who saved it last, when that is known. */
        saved_by: string | null;
        /** Whether this account may save changes to this entry. */
        can_update: boolean;
    };
    values: Record<string, unknown>;
    /** How the entries this one links to read, by entry id. */
    links: Record<string, LinkLabel>;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Models', href: models() },
        { title: props.model.label, href: index(props.model.id) },
        {
            title: 'Edit entry',
            href: edit({ model: props.model.id, entry: props.entry.id }),
        },
    ],
});

const { can } = useAbilities();

const canUpdate = props.entry.can_update;

const { values, errors, processing, updateValue, submit } = useEntryForm(
    props.schema,
    props.values,
);

const created = new Date(props.entry.created_at).toLocaleString();

/** "Created 5 Oct, 10:20 by Ada. Last saved by Ben." */
const description = (() => {
    const { author, saved_by: savedBy } = props.entry;
    const by =
        author.state === 'known'
            ? ` by ${author.name}`
            : author.state === 'former'
              ? ' by a former member'
              : '';

    return `Created ${created}${by}.${savedBy ? ` Last saved by ${savedBy}.` : ''}`;
})();
</script>

<template>
    <Head :title="`Edit ${model.label}`" />

    <PageBody>
        <PageHeader :title="`Edit ${model.label}`" :description="description" />

        <p
            v-if="!canUpdate"
            class="rounded-lg border px-4 py-3 text-sm text-muted-foreground"
        >
            You can look at this entry but not change it. Your role lets you
            change only the entries you created.
        </p>

        <EntryForm
            :schema="schema"
            :values="values"
            :errors="errors"
            :links="links"
            :disabled="processing || !canUpdate"
            :builder-href="
                can('models.update', model.id)
                    ? builder(model.id).url
                    : undefined
            "
            @update:values="updateValue"
        />

        <div class="flex gap-2">
            <Button
                v-if="canUpdate"
                :disabled="processing"
                @click="
                    submit(
                        update({ model: model.id, entry: entry.id }).url,
                        'put',
                    )
                "
            >
                <Spinner v-if="processing" />
                <Save v-if="!processing" />
                Save
            </Button>
            <Button variant="secondary" as-child>
                <Link :href="index(model.id)">{{
                    canUpdate ? 'Cancel' : 'Back'
                }}</Link>
            </Button>
        </div>
    </PageBody>
</template>

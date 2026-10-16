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
import type { ModelSchemaJson } from '@/types/schema';

const props = defineProps<{
    model: { id: number; label: string };
    schema: ModelSchemaJson;
    entry: { id: number; created_at: string };
    values: Record<string, unknown>;
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

const { values, errors, processing, updateValue, submit } = useEntryForm(
    props.schema,
    props.values,
);

const created = new Date(props.entry.created_at).toLocaleString();
</script>

<template>
    <Head :title="`Edit ${model.label}`" />

    <PageBody>
        <PageHeader
            :title="`Edit ${model.label}`"
            :description="`Created ${created}`"
        />

        <EntryForm
            :schema="schema"
            :values="values"
            :errors="errors"
            :disabled="processing || !can('entries.update')"
            :builder-href="
                can('models.update') ? builder(model.id).url : undefined
            "
            @update:values="updateValue"
        />

        <div class="flex gap-2">
            <Button
                v-if="can('entries.update')"
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
                    can('entries.update') ? 'Cancel' : 'Back'
                }}</Link>
            </Button>
        </div>
    </PageBody>
</template>

<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import EntryForm from '@/components/entries/EntryForm.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useEntryForm } from '@/composables/useEntryForm';
import { builder, index as models } from '@/routes/admin/models';
import { create, index, store } from '@/routes/admin/models/entries';
import type { ModelSchemaJson } from '@/types/schema';

const props = defineProps<{
    model: { id: number; label: string };
    schema: ModelSchemaJson;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Models', href: models() },
        { title: props.model.label, href: index(props.model.id) },
        { title: 'New entry', href: create(props.model.id) },
    ],
});

const { values, errors, processing, updateValue, submit } = useEntryForm(
    props.schema,
);
</script>

<template>
    <Head :title="`New ${model.label}`" />

    <PageBody width="text">
        <PageHeader :title="`New ${model.label}`" />

        <EntryForm
            :schema="schema"
            :values="values"
            :errors="errors"
            :disabled="processing"
            :builder-href="builder(model.id).url"
            @update:values="updateValue"
        />

        <div class="flex gap-2">
            <Button
                :disabled="processing"
                @click="submit(store(model.id).url, 'post')"
            >
                <Spinner v-if="processing" />
                <Save v-if="!processing" />
                Create
            </Button>
            <Button variant="secondary" as-child>
                <Link :href="index(model.id)">Cancel</Link>
            </Button>
        </div>
    </PageBody>
</template>

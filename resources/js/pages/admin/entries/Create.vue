<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { ref } from 'vue';
import EntryForm from '@/components/entries/EntryForm.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { initialValue } from '@/fields';
import { index as models } from '@/routes/admin/models';
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

const values = ref<Record<string, unknown>>(
    Object.fromEntries(
        props.schema.fields.map((field) => [field.key, initialValue(field)]),
    ),
);
const errors = ref<Record<string, string>>({});
const processing = ref(false);

function updateValue(key: string, value: unknown) {
    values.value[key] = value;
}

function submit() {
    processing.value = true;

    router.post(
        store(props.model.id).url,
        { fields: values.value as Record<string, FormDataConvertible> },
        {
            preserveScroll: true,
            onError: (serverErrors) => {
                const mapped: Record<string, string> = {};

                for (const [key, message] of Object.entries(serverErrors)) {
                    if (key.startsWith('fields.')) {
                        mapped[key.slice('fields.'.length)] = message;
                    }
                }

                errors.value = mapped;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`New ${model.label}`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <header class="space-y-0.5">
            <h1 class="text-xl font-semibold">New {{ model.label }}</h1>
        </header>

        <EntryForm
            :schema="schema"
            :values="values"
            :errors="errors"
            :disabled="processing"
            @update:values="updateValue"
        />

        <div class="flex gap-2">
            <Button :disabled="processing" @click="submit">
                <Spinner v-if="processing" />
                <Save v-if="!processing" />
                Create
            </Button>
            <Button variant="secondary" as-child>
                <Link :href="index(model.id)">Cancel</Link>
            </Button>
        </div>
    </div>
</template>

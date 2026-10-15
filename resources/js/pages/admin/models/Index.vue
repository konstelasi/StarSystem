<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Blocks,
    Download,
    List,
    Pencil,
    PlusCircle,
    Upload,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CreateModelDialog from '@/components/models/CreateModelDialog.vue';
import DeleteModelDialog from '@/components/models/DeleteModelDialog.vue';
import DuplicateModelDialog from '@/components/models/DuplicateModelDialog.vue';
import ImportModelDialog from '@/components/models/ImportModelDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { builder, exportMethod, index } from '@/routes/admin/models';
import { index as entries } from '@/routes/admin/models/entries';

type ModelRow = {
    id: number;
    slug: string;
    label: string;
    group: string | null;
    fields_count: number;
    status: 'active' | 'deleting';
    updated_at: string | null;
};

const props = defineProps<{
    models: ModelRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Models', href: index() }],
    },
});

const formatTime = (iso: string) => new Date(iso).toLocaleString();

const createOpen = ref(false);
const importOpen = ref(false);
const existingSlugs = computed(() => props.models.map((model) => model.slug));
</script>

<template>
    <Head title="Models" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-0.5">
                <h2 class="text-xl font-semibold tracking-tight">Models</h2>
                <p class="text-sm text-muted-foreground">
                    The content models editors fill in entries for.
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="secondary" @click="importOpen = true">
                    <Upload />
                    Import
                </Button>
                <Button @click="createOpen = true">
                    <PlusCircle />
                    New model
                </Button>
            </div>
        </div>

        <Card v-if="models.length === 0">
            <CardHeader class="items-center text-center">
                <Blocks class="size-8 text-muted-foreground" />
                <CardTitle>No models yet</CardTitle>
                <CardDescription>
                    A model defines the fields an entry has, like Article or
                    Page.
                </CardDescription>
            </CardHeader>
        </Card>

        <div v-else class="data-table rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2 max-sm:hidden">Address name</th>
                        <th class="px-4 py-2 max-sm:hidden">Group</th>
                        <th class="px-4 py-2 max-sm:hidden">Fields</th>
                        <th class="px-4 py-2 max-sm:hidden">Updated</th>
                        <th class="px-4 py-2 max-sm:hidden">Status</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="model in models"
                        :key="model.id"
                        class="align-top"
                        :data-test="`model-${model.slug}`"
                    >
                        <td class="px-4 py-2 font-medium">
                            {{ model.label }}
                            <Badge
                                v-if="model.status === 'deleting'"
                                variant="secondary"
                                class="ml-2 sm:hidden"
                            >
                                Deleting…
                            </Badge>
                        </td>
                        <td class="px-4 py-2 max-sm:hidden">
                            <code class="text-xs">{{ model.slug }}</code>
                        </td>
                        <td
                            class="px-4 py-2 text-muted-foreground max-sm:hidden"
                        >
                            {{ model.group ?? 'None' }}
                        </td>
                        <td class="px-4 py-2 tabular-nums max-sm:hidden">
                            {{ model.fields_count }}
                        </td>
                        <td
                            class="px-4 py-2 whitespace-nowrap max-sm:hidden"
                            :class="{
                                'text-muted-foreground': !model.updated_at,
                            }"
                        >
                            {{
                                model.updated_at
                                    ? formatTime(model.updated_at)
                                    : 'Not yet'
                            }}
                        </td>
                        <td class="px-4 py-2 max-sm:hidden">
                            <Badge
                                v-if="model.status === 'deleting'"
                                variant="secondary"
                            >
                                Deleting…
                            </Badge>
                            <Badge v-else variant="outline">Active</Badge>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <template v-if="model.status !== 'deleting'">
                                <Button as-child variant="ghost" size="sm">
                                    <Link :href="entries(model.id)">
                                        <List />
                                        <span class="max-sm:sr-only"
                                            >Entries</span
                                        >
                                    </Link>
                                </Button>
                                <Button as-child variant="ghost" size="sm">
                                    <Link :href="builder(model.id)">
                                        <Pencil />
                                        <span class="max-sm:sr-only">Open</span>
                                    </Link>
                                </Button>
                                <Button as-child variant="ghost" size="sm">
                                    <a :href="exportMethod(model.id).url">
                                        <Download />
                                        <span class="max-sm:sr-only"
                                            >Export</span
                                        >
                                    </a>
                                </Button>
                                <DuplicateModelDialog
                                    :model="model"
                                    :existing-slugs="existingSlugs"
                                />
                                <DeleteModelDialog :model="model" />
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <CreateModelDialog
            v-model:open="createOpen"
            :existing-slugs="existingSlugs"
        />
        <ImportModelDialog v-model:open="importOpen" />
    </div>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Blocks } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { builder, index } from '@/routes/admin/models';

type ModelRow = {
    id: number;
    slug: string;
    label: string;
    group: string | null;
    fields_count: number;
    status: 'active' | 'deleting';
    updated_at: string | null;
};

defineProps<{
    models: ModelRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Models', href: index() }],
    },
});

const formatTime = (iso: string) => new Date(iso).toLocaleString();
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

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Address name</th>
                        <th class="px-4 py-2 font-medium">Group</th>
                        <th class="px-4 py-2 font-medium">Fields</th>
                        <th class="px-4 py-2 font-medium">Updated</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="model in models"
                        :key="model.id"
                        class="border-t align-top"
                        :data-test="`model-${model.slug}`"
                    >
                        <td class="px-4 py-2 font-medium">
                            {{ model.label }}
                        </td>
                        <td class="px-4 py-2">
                            <code class="text-xs">{{ model.slug }}</code>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ model.group ?? '—' }}
                        </td>
                        <td class="px-4 py-2 tabular-nums">
                            {{ model.fields_count }}
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{
                                model.updated_at
                                    ? formatTime(model.updated_at)
                                    : '—'
                            }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                v-if="model.status === 'deleting'"
                                variant="secondary"
                            >
                                Deleting…
                            </Badge>
                            <Badge v-else variant="outline">Active</Badge>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                v-if="model.status !== 'deleting'"
                                as-child
                                variant="ghost"
                                size="sm"
                            >
                                <Link :href="builder(model.id)">Open</Link>
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

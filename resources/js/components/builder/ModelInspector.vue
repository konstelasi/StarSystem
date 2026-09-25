<script setup lang="ts">
import { MousePointerClick } from '@lucide/vue';
import { computed } from 'vue';
import { useBuilderContext } from '@/components/builder/context';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';

const { builder } = useBuilderContext();

const model = computed(() => builder.schema.value.model);
const counts = computed(() => ({
    fields: builder.schema.value.fields.length,
    required: builder.schema.value.fields.filter((field) => field.required)
        .length,
    filterable: builder.schema.value.fields.filter((field) => field.filterable)
        .length,
}));
</script>

<template>
    <div class="grid gap-5">
        <div
            class="flex flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-6 text-center"
        >
            <MousePointerClick
                class="size-6 text-muted-foreground"
                aria-hidden="true"
            />
            <p class="text-sm font-medium">Select a field to change it</p>
            <p class="text-xs text-muted-foreground">
                Its label, key, settings and more appear here.
            </p>
        </div>

        <Separator />

        <section class="grid gap-4" aria-labelledby="inspect-model">
            <h2 id="inspect-model" class="text-sm font-semibold">
                About this model
            </h2>
            <div class="grid gap-1.5">
                <Label for="inspect-model-label">Name</Label>
                <Input
                    id="inspect-model-label"
                    :model-value="model.label"
                    @update:model-value="model.label = String($event)"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="inspect-model-group">Menu group</Label>
                <Input
                    id="inspect-model-group"
                    :model-value="model.group ?? ''"
                    placeholder="For example Content"
                    @update:model-value="
                        model.group = String($event) || undefined
                    "
                />
            </div>
            <dl class="grid grid-cols-[1fr_auto] gap-y-1 text-sm">
                <dt class="text-muted-foreground">Address name</dt>
                <dd>
                    <code class="text-xs">{{ model.slug }}</code>
                </dd>
                <dt class="text-muted-foreground">Fields</dt>
                <dd class="tabular-nums">{{ counts.fields }}</dd>
                <dt class="text-muted-foreground">Required</dt>
                <dd class="tabular-nums">{{ counts.required }}</dd>
                <dt class="text-muted-foreground">Filterable</dt>
                <dd class="tabular-nums">{{ counts.filterable }}</dd>
            </dl>
        </section>
    </div>
</template>

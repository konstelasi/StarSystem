<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Braces, Eye, Hammer, Save } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import BlockInspector from '@/components/builder/BlockInspector.vue';
import BuilderCanvas from '@/components/builder/BuilderCanvas.vue';
import BuilderPalette from '@/components/builder/BuilderPalette.vue';
import { provideBuilder } from '@/components/builder/context';
import FieldInspector from '@/components/builder/FieldInspector.vue';
import FormPreview from '@/components/builder/FormPreview.vue';
import JsonEditor from '@/components/builder/JsonEditor.vue';
import ModelInspector from '@/components/builder/ModelInspector.vue';
import SaveDialog from '@/components/builder/SaveDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useModelBuilder } from '@/composables/useModelBuilder';
import type { FieldStates } from '@/lib/modelSchema';
import { builder as builderPage, index } from '@/routes/admin/models';
import type {
    FieldTypeDescriptor,
    ModelSchemaJson,
    SavePreview,
} from '@/types/schema';

const props = defineProps<{
    model: { id: number };
    fieldTypes: FieldTypeDescriptor[];
    schema: ModelSchemaJson;
    states: FieldStates;
    otherSlugs: string[];
    preview?: SavePreview | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Models', href: index() },
            { title: props.schema.model.label, href: builderPage(props.model) },
        ],
    },
});

const builder = useModelBuilder(props.schema, () => props.fieldTypes);

const announcement = ref('');

async function announce(message: string) {
    // Clear first so repeating the same message is still read out.
    announcement.value = '';
    await nextTick();
    announcement.value = message;
}

provideBuilder({
    builder,
    announce: (message) => void announce(message),
    states: props.states,
    modelId: props.model.id,
    otherSlugs: props.otherSlugs,
});

const tab = ref('build');
const saveDialogOpen = ref(false);
</script>

<template>
    <Head :title="`${builder.schema.value.model.label}: fields`" />

    <div class="@container flex flex-1 flex-col gap-4 p-4">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">
                    {{ builder.schema.value.model.group ?? 'Models' }}
                </p>
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    {{ builder.schema.value.model.label }}
                    <Badge
                        v-if="builder.isDirty.value"
                        variant="secondary"
                        class="font-normal"
                    >
                        Unsaved changes
                    </Badge>
                </h1>
                <p class="text-sm text-muted-foreground">
                    Build the form editors fill in for each entry.
                </p>
            </div>
            <Button
                :disabled="!builder.isDirty.value"
                @click="saveDialogOpen = true"
            >
                <Save /> Save changes
            </Button>
        </header>

        <Tabs v-model="tab" class="gap-4">
            <TabsList>
                <TabsTrigger value="build" class="px-3">
                    <Hammer /> Build
                </TabsTrigger>
                <TabsTrigger value="preview" class="px-3">
                    <Eye /> Preview
                </TabsTrigger>
                <TabsTrigger value="json" class="px-3">
                    <Braces /> JSON
                </TabsTrigger>
            </TabsList>

            <TabsContent value="build">
                <div
                    class="grid items-start gap-4 @3xl:grid-cols-[minmax(0,1fr)_18rem] @5xl:grid-cols-[13rem_minmax(0,1fr)_20rem]"
                >
                    <aside
                        class="rounded-xl border bg-card p-3 @3xl:col-span-2 @5xl:sticky @5xl:top-4 @5xl:col-span-1 @5xl:max-h-[calc(100svh-7rem)] @5xl:overflow-y-auto"
                    >
                        <BuilderPalette :field-types="fieldTypes" />
                    </aside>

                    <section
                        aria-label="Form layout"
                        class="@container min-w-0"
                    >
                        <BuilderCanvas />
                    </section>

                    <aside
                        aria-label="Settings"
                        class="rounded-xl border bg-card p-4 @3xl:sticky @3xl:top-4 @3xl:max-h-[calc(100svh-7rem)] @3xl:overflow-y-auto"
                    >
                        <FieldInspector
                            v-if="builder.selectedField.value"
                            :key="builder.selectedField.value.uuid"
                            :field="builder.selectedField.value"
                            :field-types="fieldTypes"
                        />
                        <BlockInspector
                            v-else-if="builder.selectedBlock.value"
                            :key="builder.selectedBlock.value.id"
                            :block="builder.selectedBlock.value"
                        />
                        <ModelInspector v-else />
                    </aside>
                </div>
            </TabsContent>

            <TabsContent value="preview">
                <div class="@container rounded-xl border bg-card p-4 @xl:p-6">
                    <FormPreview :schema="builder.schema.value" />
                </div>
            </TabsContent>

            <TabsContent value="json">
                <div class="rounded-xl border bg-card p-4">
                    <JsonEditor />
                </div>
            </TabsContent>
        </Tabs>

        <SaveDialog v-model:open="saveDialogOpen" :preview="preview" />

        <div aria-live="polite" aria-atomic="true" class="sr-only">
            {{ announcement }}
        </div>
    </div>
</template>

<script setup lang="ts">
import { EyeOff, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { initialValue, rendererFor } from '@/fields';
import { blockName, slotName } from '@/lib/modelSchema';
import type { FieldSpecJson, ModelSchemaJson } from '@/types/schema';

/**
 * The form as editors will see it, rendered with the real field renderers.
 * Values typed here are only for trying the form out; they're never saved.
 */
const props = defineProps<{ schema: ModelSchemaJson }>();

const values = ref<Record<string, unknown>>({});

const valueOf = (field: FieldSpecJson) =>
    field.uuid in values.value ? values.value[field.uuid] : initialValue(field);

const fieldsIn = (slotId: string | null) =>
    props.schema.fields.filter((field) => field.layout_slot === slotId);

const topLevel = computed(() => fieldsIn(null));
const isEmpty = computed(() => props.schema.fields.length === 0);
</script>

<template>
    <div class="grid gap-6">
        <div class="flex items-center justify-between gap-4">
            <p class="text-sm text-muted-foreground">
                Try the form. Nothing you type here is saved.
            </p>
            <Button
                variant="ghost"
                size="sm"
                :disabled="Object.keys(values).length === 0"
                @click="values = {}"
            >
                <RotateCcw /> Clear
            </Button>
        </div>

        <p
            v-if="isEmpty"
            class="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground"
        >
            Add fields on the Build tab and they'll appear here.
        </p>

        <form v-else class="grid max-w-2xl gap-6" @submit.prevent>
            <template v-for="field in topLevel" :key="field.uuid">
                <p
                    v-if="field.type === 'hidden'"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <EyeOff class="size-3.5" aria-hidden="true" />
                    {{ field.label }} is hidden from editors.
                </p>
                <component
                    :is="rendererFor(field.type)"
                    v-else
                    :field="field"
                    :model-value="valueOf(field)"
                    @update:model-value="values[field.uuid] = $event"
                />
            </template>

            <template v-for="block in schema.layout" :key="block.id">
                <fieldset
                    v-if="block.kind === 'section'"
                    class="grid gap-6 rounded-xl border p-4"
                >
                    <legend class="px-1 text-sm font-semibold">
                        {{ blockName(block) }}
                    </legend>
                    <template
                        v-for="field in block.slots.flatMap((slot) =>
                            fieldsIn(slot.id),
                        )"
                        :key="field.uuid"
                    >
                        <component
                            :is="rendererFor(field.type)"
                            v-if="field.type !== 'hidden'"
                            :field="field"
                            :model-value="valueOf(field)"
                            @update:model-value="values[field.uuid] = $event"
                        />
                    </template>
                </fieldset>

                <Tabs
                    v-else-if="block.kind === 'tabs'"
                    :default-value="block.slots[0]?.id"
                    class="gap-4"
                >
                    <TabsList class="max-w-full justify-start overflow-x-auto">
                        <TabsTrigger
                            v-for="(slot, index) in block.slots"
                            :key="slot.id"
                            :value="slot.id"
                            class="flex-none"
                        >
                            {{ slotName(block, index) }}
                        </TabsTrigger>
                    </TabsList>
                    <TabsContent
                        v-for="slot in block.slots"
                        :key="slot.id"
                        :value="slot.id"
                        class="grid gap-6"
                    >
                        <p
                            v-if="fieldsIn(slot.id).length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No fields in this tab yet.
                        </p>
                        <template
                            v-for="field in fieldsIn(slot.id)"
                            :key="field.uuid"
                        >
                            <component
                                :is="rendererFor(field.type)"
                                v-if="field.type !== 'hidden'"
                                :field="field"
                                :model-value="valueOf(field)"
                                @update:model-value="
                                    values[field.uuid] = $event
                                "
                            />
                        </template>
                    </TabsContent>
                </Tabs>

                <div
                    v-else
                    class="grid gap-6 @xl:grid-cols-[repeat(auto-fit,minmax(14rem,1fr))]"
                >
                    <div
                        v-for="slot in block.slots"
                        :key="slot.id"
                        class="grid content-start gap-6"
                    >
                        <template
                            v-for="field in fieldsIn(slot.id)"
                            :key="field.uuid"
                        >
                            <component
                                :is="rendererFor(field.type)"
                                v-if="field.type !== 'hidden'"
                                :field="field"
                                :model-value="valueOf(field)"
                                @update:model-value="
                                    values[field.uuid] = $event
                                "
                            />
                        </template>
                    </div>
                </div>
            </template>
        </form>
    </div>
</template>

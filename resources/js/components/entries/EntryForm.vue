<script setup lang="ts">
import { computed } from 'vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { rendererFor } from '@/fields';
import { blockName, slotName } from '@/lib/modelSchema';
import type { FieldSpecJson, ModelSchemaJson } from '@/types/schema';

/**
 * The real form editors fill in for an entry: the same field renderers the
 * builder's preview uses, keyed by the field's key (not its uuid) since
 * that's the name StarDust stores the value under.
 */
const props = defineProps<{
    schema: ModelSchemaJson;
    values: Record<string, unknown>;
    errors: Record<string, string>;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:values': [key: string, value: unknown] }>();

const fieldsIn = (slotId: string | null) =>
    props.schema.fields.filter((field) => field.layout_slot === slotId);

const topLevel = computed(() => fieldsIn(null));
const isEmpty = computed(() => props.schema.fields.length === 0);

function update(field: FieldSpecJson, value: unknown) {
    emit('update:values', field.key, value);
}
</script>

<template>
    <p
        v-if="isEmpty"
        class="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground"
    >
        This model has no fields yet. Add some in the model builder first.
    </p>

    <div v-else class="@container grid max-w-2xl gap-6">
        <template v-for="field in topLevel" :key="field.uuid">
            <component
                :is="rendererFor(field.type)"
                :field="field"
                :model-value="values[field.key]"
                :error="errors[field.key]"
                :disabled="disabled"
                @update:model-value="update(field, $event)"
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
                        :field="field"
                        :model-value="values[field.key]"
                        :error="errors[field.key]"
                        :disabled="disabled"
                        @update:model-value="update(field, $event)"
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
                        No fields in this tab.
                    </p>
                    <template
                        v-for="field in fieldsIn(slot.id)"
                        :key="field.uuid"
                    >
                        <component
                            :is="rendererFor(field.type)"
                            :field="field"
                            :model-value="values[field.key]"
                            :error="errors[field.key]"
                            :disabled="disabled"
                            @update:model-value="update(field, $event)"
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
                            :field="field"
                            :model-value="values[field.key]"
                            :error="errors[field.key]"
                            :disabled="disabled"
                            @update:model-value="update(field, $event)"
                        />
                    </template>
                </div>
            </div>
        </template>
    </div>
</template>

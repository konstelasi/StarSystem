<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { rendererFor } from '@/fields';
import { errorsPerSlot, firstError } from '@/lib/entryErrors';
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
    /** Where to add fields, for the message a fieldless model shows. */
    builderHref?: string;
}>();

const emit = defineEmits<{ 'update:values': [key: string, value: unknown] }>();

const fieldsIn = (slotId: string | null) =>
    props.schema.fields.filter((field) => field.layout_slot === slotId);

const topLevel = computed(() => fieldsIn(null));
const isEmpty = computed(() => props.schema.fields.length === 0);

function update(field: FieldSpecJson, value: unknown) {
    emit('update:values', field.key, value);
}

/** The open tab of each tabs block, by block id. */
const openTabs = ref<Record<string, string>>(
    Object.fromEntries(
        props.schema.layout
            .filter((block) => block.kind === 'tabs' && block.slots.length > 0)
            .map((block) => [block.id, block.slots[0].id]),
    ),
);

const errorCounts = computed(() => errorsPerSlot(props.schema, props.errors));

/**
 * After a failed save, open the tab holding the first error and focus that
 * field, so an error on a hidden tab never looks like a save that did
 * nothing.
 */
watch(
    () => props.errors,
    async (errors) => {
        const place = firstError(props.schema, errors);

        if (place === null) {
            return;
        }

        if (place.blockId !== null && place.blockId in openTabs.value) {
            openTabs.value[place.blockId] = place.slotId as string;
        }

        // A tab's content mounts when it opens, so its field may need a
        // frame after the tick before it exists.
        await nextTick();
        const id = `field-${place.field.uuid}`;
        const input =
            document.getElementById(id) ??
            (await new Promise<HTMLElement | null>((resolve) =>
                requestAnimationFrame(() =>
                    resolve(document.getElementById(id)),
                ),
            ));

        input?.scrollIntoView({ block: 'center' });
        input?.focus({ preventScroll: true });
    },
);
</script>

<template>
    <p
        v-if="isEmpty"
        class="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground"
    >
        This model has no fields yet.
        <Link
            v-if="builderHref"
            :href="builderHref"
            class="font-medium text-foreground underline underline-offset-4"
        >
            Add some in the model builder first.
        </Link>
        <template v-else>Add some in the model builder first.</template>
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
                v-model="openTabs[block.id]"
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
                        <span
                            v-if="errorCounts[slot.id]"
                            class="ml-1 inline-flex items-center gap-1 text-xs font-medium text-destructive"
                        >
                            <span
                                class="size-1.5 rounded-full bg-destructive"
                                aria-hidden="true"
                            />
                            {{ errorCounts[slot.id] }}
                            <span class="sr-only">
                                {{
                                    errorCounts[slot.id] === 1
                                        ? 'error'
                                        : 'errors'
                                }}
                            </span>
                        </span>
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

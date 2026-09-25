<script setup lang="ts">
import { Plus, Trash2, X } from '@lucide/vue';
import { computed } from 'vue';
import { useBuilderContext } from '@/components/builder/context';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { blockKindLabel, blockName, slotName } from '@/lib/modelSchema';
import type { LayoutBlock } from '@/types/schema';

const props = defineProps<{ block: LayoutBlock }>();

const { builder, announce } = useBuilderContext();

const id = computed(() => `inspect-${props.block.id}`);
const slotNoun = computed(() =>
    props.block.kind === 'tabs' ? 'tab' : 'column',
);
const fieldCount = computed(() =>
    props.block.slots.reduce(
        (count, slot) => count + builder.fieldsIn(slot.id).length,
        0,
    ),
);

function removeSlot(slotId: string, index: number) {
    const name = slotName(props.block, index);
    builder.removeSlot(props.block.id, slotId);
    announce(
        `Removed ${name}. Its fields moved to ${slotName(props.block, 0)}.`,
    );
}

function remove() {
    const name = blockName(props.block);
    builder.removeBlock(props.block.id);
    announce(`Removed ${name}. Its fields moved to the main area.`);
}
</script>

<template>
    <div class="grid gap-5">
        <header class="flex items-start gap-2">
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-sm font-semibold">
                    {{ blockName(block) }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{ blockKindLabel(block.kind) }} · layout only, doesn't
                    change stored data
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon-sm"
                class="-mt-1 -mr-1 shrink-0"
                aria-label="Close layout settings"
                @click="builder.select(null)"
            >
                <X />
            </Button>
        </header>

        <div class="grid gap-1.5">
            <Label :for="`${id}-label`">Title</Label>
            <Input
                :id="`${id}-label`"
                :model-value="block.label ?? ''"
                :placeholder="blockKindLabel(block.kind)"
                @update:model-value="
                    builder.updateBlock(block.id, {
                        label: String($event) || undefined,
                    })
                "
            />
            <p class="text-xs text-muted-foreground">
                <template v-if="block.kind === 'section'">
                    Shown above the fields in this section.
                </template>
                <template v-else>
                    Only shown here in the builder, to tell blocks apart.
                </template>
            </p>
        </div>

        <section
            v-if="block.kind !== 'section'"
            class="grid gap-2"
            :aria-labelledby="`${id}-slots`"
        >
            <h3 :id="`${id}-slots`" class="text-sm font-semibold capitalize">
                {{ slotNoun }}s
            </h3>
            <div
                v-for="(slot, index) in block.slots"
                :key="slot.id"
                class="flex items-center gap-1"
            >
                <Input
                    class="h-8"
                    :model-value="slot.label ?? ''"
                    :placeholder="slotName(block, index)"
                    :aria-label="`Name of ${slotNoun} ${index + 1}`"
                    @update:model-value="
                        builder.renameSlot(block.id, slot.id, String($event))
                    "
                />
                <Button
                    variant="ghost"
                    size="icon-sm"
                    class="shrink-0"
                    :disabled="block.slots.length <= 1"
                    :aria-label="`Remove ${slotName(block, index)}`"
                    @click="removeSlot(slot.id, index)"
                >
                    <X />
                </Button>
            </div>
            <Button
                variant="outline"
                size="sm"
                class="justify-self-start"
                @click="builder.addSlot(block.id)"
            >
                <Plus /> Add a {{ slotNoun }}
            </Button>
        </section>

        <Separator />

        <div class="grid gap-2">
            <Button
                variant="outline"
                size="sm"
                class="justify-self-start text-destructive hover:text-destructive"
                @click="remove"
            >
                <Trash2 /> Remove {{ blockKindLabel(block.kind).toLowerCase() }}
            </Button>
            <p v-if="fieldCount > 0" class="text-xs text-muted-foreground">
                Its {{ fieldCount }}
                {{ fieldCount === 1 ? 'field moves' : 'fields move' }} to the
                main area. No data is lost.
            </p>
        </div>
    </div>
</template>

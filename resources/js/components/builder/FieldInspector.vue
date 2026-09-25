<script setup lang="ts">
import { ArrowDown, ArrowUp, Copy, Trash2, X } from '@lucide/vue';
import { computed } from 'vue';
import { positionText, useBuilderContext } from '@/components/builder/context';
import FieldTypeIcon from '@/components/builder/FieldTypeIcon.vue';
import SettingInput from '@/components/builder/SettingInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { keyProblem } from '@/lib/modelSchema';
import type { FieldSpecJson, FieldTypeDescriptor } from '@/types/schema';

const props = defineProps<{
    field: FieldSpecJson;
    fieldTypes: FieldTypeDescriptor[];
}>();

const { builder, announce } = useBuilderContext();

const id = computed(() => `inspect-${props.field.uuid}`);
const type = computed(() => builder.typeOf(props.field.type));
const isNew = computed(() => builder.isNew(props.field.uuid));
const position = computed(() => builder.positionOf(props.field.uuid));

const keyError = computed(() =>
    keyProblem(props.field.key, builder.otherKeys(props.field.uuid)),
);

// Select items can't have an empty value, so the main area gets a name.
const MAIN_AREA = '__main__';
const slotValue = computed(() => props.field.layout_slot ?? MAIN_AREA);

function update(patch: Parameters<typeof builder.updateField>[1]) {
    builder.updateField(props.field.uuid, patch);
}

function setSlot(value: unknown) {
    const slot =
        value === MAIN_AREA || typeof value !== 'string' ? null : value;
    builder.moveField(props.field.uuid, slot, Number.POSITIVE_INFINITY);

    const place = builder.slots.value.find((choice) => choice.id === slot);
    announce(`${props.field.label} moved to ${place?.label ?? 'the form'}.`);
}

function setType(value: unknown) {
    if (typeof value === 'string') {
        builder.changeType(props.field.uuid, value);
    }
}

function move(delta: number) {
    const next = builder.moveFieldBy(props.field.uuid, delta);

    if (next) {
        announce(`${props.field.label} moved to ${positionText(next)}.`);
    }
}

function duplicate() {
    const copy = builder.duplicateField(props.field.uuid);

    if (copy) {
        announce(`Added ${copy.label}.`);
    }
}

function remove() {
    const label = props.field.label;
    builder.removeField(props.field.uuid);
    announce(`Removed ${label}.`);
}
</script>

<template>
    <div class="grid gap-5">
        <header class="flex items-start gap-2">
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground [&_svg]:size-4"
            >
                <FieldTypeIcon :name="type?.icon" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-sm font-semibold">
                    {{ field.label || 'Untitled field' }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{ type?.label ?? field.type }} field
                    <template v-if="isNew">· not saved yet</template>
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon-sm"
                class="-mt-1 -mr-1 shrink-0"
                aria-label="Close field settings"
                @click="builder.select(null)"
            >
                <X />
            </Button>
        </header>

        <div class="grid gap-1.5">
            <Label :for="`${id}-label`">Label</Label>
            <Input
                :id="`${id}-label`"
                :model-value="field.label"
                @update:model-value="update({ label: String($event) })"
            />
        </div>

        <div class="grid gap-1.5">
            <Label :for="`${id}-key`">Key</Label>
            <Input
                :id="`${id}-key`"
                class="font-mono"
                spellcheck="false"
                autocapitalize="off"
                :model-value="field.key"
                :aria-invalid="keyError ? true : undefined"
                :aria-describedby="`${id}-key-help`"
                @update:model-value="update({ key: String($event) })"
            />
            <p
                :id="`${id}-key-help`"
                class="text-xs"
                :class="keyError ? 'text-destructive' : 'text-muted-foreground'"
            >
                <template v-if="keyError">{{ keyError }}</template>
                <template v-else-if="isNew">
                    Filled in from the label. Used in exports and templates.
                </template>
                <template v-else>
                    Changing it updates every existing entry in the background.
                </template>
            </p>
        </div>

        <div class="grid gap-1.5">
            <Label :for="`${id}-helper`">Help text</Label>
            <Textarea
                :id="`${id}-helper`"
                class="field-sizing-fixed min-h-0"
                rows="2"
                placeholder="Shown under the field to guide editors"
                :model-value="field.helper ?? ''"
                @update:model-value="
                    update({ helper: String($event) || undefined })
                "
            />
        </div>

        <div class="grid gap-1.5">
            <Label :for="`${id}-type`">Type</Label>
            <Select :model-value="field.type" @update:model-value="setType">
                <SelectTrigger
                    :id="`${id}-type`"
                    class="w-full"
                    :aria-describedby="`${id}-type-help`"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in fieldTypes"
                        :key="option.key"
                        :value="option.key"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p :id="`${id}-type-help`" class="text-xs text-muted-foreground">
                <template v-if="!isNew">
                    Existing values are converted where possible.
                </template>
                <template v-else>You can change this until you save.</template>
            </p>
        </div>

        <div class="grid gap-1.5">
            <Label :for="`${id}-slot`">Place in</Label>
            <Select :model-value="slotValue" @update:model-value="setSlot">
                <SelectTrigger :id="`${id}-slot`" class="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="choice in builder.slots.value"
                        :key="choice.id ?? MAIN_AREA"
                        :value="choice.id ?? MAIN_AREA"
                    >
                        {{ choice.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="flex items-start justify-between gap-3">
            <div class="grid gap-1">
                <Label :for="`${id}-required`">Required</Label>
                <p class="text-xs text-muted-foreground">
                    Editors must fill this in before saving an entry.
                </p>
            </div>
            <Switch
                :id="`${id}-required`"
                :model-value="field.required"
                @update:model-value="update({ required: $event === true })"
            />
        </div>

        <div
            v-if="type?.storage.canFilter"
            class="flex items-start justify-between gap-3"
        >
            <div class="grid gap-1">
                <Label :for="`${id}-filterable`">Filterable</Label>
                <p class="text-xs text-muted-foreground">
                    Lets you filter and sort entries by this field. Existing
                    entries are indexed in the background.
                </p>
            </div>
            <Switch
                :id="`${id}-filterable`"
                :model-value="field.filterable"
                @update:model-value="update({ filterable: $event === true })"
            />
        </div>

        <template v-if="type">
            <Separator />
            <section class="grid gap-4" aria-labelledby="inspect-settings">
                <h3 id="inspect-settings" class="text-sm font-semibold">
                    {{ type.label }} settings
                </h3>
                <p
                    v-if="type.settings.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    This type has no extra settings.
                </p>
                <SettingInput
                    v-for="setting in type.settings"
                    :key="setting.key"
                    :setting="setting"
                    :id-prefix="id"
                    :model-value="field.settings[setting.key]"
                    @update:model-value="
                        builder.updateSetting(field.uuid, setting.key, $event)
                    "
                />
            </section>
        </template>

        <Separator />

        <div class="flex flex-wrap gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="position?.index === 0"
                @click="move(-1)"
            >
                <ArrowUp /> Up
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="
                    position === null || position.index === position.total - 1
                "
                @click="move(1)"
            >
                <ArrowDown /> Down
            </Button>
            <Button variant="outline" size="sm" @click="duplicate">
                <Copy /> Duplicate
            </Button>
            <Button
                variant="outline"
                size="sm"
                class="text-destructive hover:text-destructive"
                @click="remove"
            >
                <Trash2 /> Remove
            </Button>
        </div>
    </div>
</template>

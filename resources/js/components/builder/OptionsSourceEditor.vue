<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, X } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
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
import { toOptionsSource } from '@/fields/support';
import { slugify } from '@/lib/modelSchema';
import type { OptionsSource } from '@/types/schema';

const props = defineProps<{ id: string; modelValue: unknown }>();
const emit = defineEmits<{ 'update:modelValue': [value: OptionsSource] }>();

const source = computed(() => toOptionsSource(props.modelValue));

const KINDS: { value: OptionsSource['kind']; label: string }[] = [
    { value: 'static', label: 'A list I type in' },
    { value: 'model', label: 'Entries of another model' },
    { value: 'hook', label: 'Provided by a module' },
];

function setKind(kind: unknown) {
    if (kind === source.value.kind) {
        return;
    }

    if (kind === 'model') {
        emit('update:modelValue', { kind, model: '', label_field: 'title' });
    } else if (kind === 'hook') {
        emit('update:modelValue', { kind, name: '' });
    } else {
        emit('update:modelValue', { kind: 'static', options: [] });
    }
}

const options = computed(() =>
    source.value.kind === 'static' ? source.value.options : [],
);

/**
 * Rows added here whose stored value still follows the label. Existing
 * choices keep their stored value, because entries already hold it.
 */
const autoValues = ref(new Set<number>());
const listEl = ref<HTMLElement | null>(null);

function emitOptions(next: { value: string; label: string }[]) {
    emit('update:modelValue', { kind: 'static', options: next });
}

function setLabel(index: number, label: string) {
    const next = options.value.map((option) => ({ ...option }));
    next[index].label = label;

    if (autoValues.value.has(index)) {
        next[index].value = slugify(label);
    }

    emitOptions(next);
}

function setValue(index: number, value: string) {
    autoValues.value.delete(index);
    const next = options.value.map((option) => ({ ...option }));
    next[index].value = value;
    emitOptions(next);
}

async function addOption() {
    autoValues.value.add(options.value.length);
    emitOptions([...options.value, { value: '', label: '' }]);
    await nextTick();
    listEl.value
        ?.querySelector<HTMLInputElement>('li:last-child input')
        ?.focus();
}

function removeOption(index: number) {
    autoValues.value = new Set(
        [...autoValues.value]
            .filter((item) => item !== index)
            .map((item) => (item > index ? item - 1 : item)),
    );
    emitOptions(options.value.filter((_, i) => i !== index));
}

function moveOption(index: number, delta: number) {
    const next = [...options.value];
    const [option] = next.splice(index, 1);
    next.splice(index + delta, 0, option);
    autoValues.value = new Set();
    emitOptions(next);
}

const duplicateValues = computed(() => {
    const seen = new Set<string>();
    const duplicates = new Set<string>();

    for (const option of options.value) {
        if (seen.has(option.value)) {
            duplicates.add(option.value);
        }

        seen.add(option.value);
    }

    return duplicates;
});

function updateModel(patch: Partial<{ model: string; label_field: string }>) {
    if (source.value.kind === 'model') {
        emit('update:modelValue', { ...source.value, ...patch });
    }
}

function updateHook(name: string) {
    emit('update:modelValue', { kind: 'hook', name });
}
</script>

<template>
    <div class="grid gap-3">
        <Select :model-value="source.kind" @update:model-value="setKind">
            <SelectTrigger :id="id" class="w-full">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="kind in KINDS"
                    :key="kind.value"
                    :value="kind.value"
                >
                    {{ kind.label }}
                </SelectItem>
            </SelectContent>
        </Select>

        <template v-if="source.kind === 'static'">
            <p
                v-if="options.length === 0"
                class="text-sm text-muted-foreground"
            >
                No choices yet.
            </p>
            <ul v-else ref="listEl" class="grid gap-2">
                <li
                    v-for="(option, index) in options"
                    :key="index"
                    class="grid gap-1 rounded-md border p-2"
                >
                    <div class="flex items-center gap-1">
                        <Input
                            class="h-8"
                            :model-value="option.label"
                            placeholder="Shown to editors"
                            :aria-label="`Choice ${index + 1} label`"
                            @update:model-value="
                                setLabel(index, String($event))
                            "
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="size-7 shrink-0"
                            :disabled="index === 0"
                            :aria-label="`Move choice ${index + 1} up`"
                            @click="moveOption(index, -1)"
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="size-7 shrink-0"
                            :disabled="index === options.length - 1"
                            :aria-label="`Move choice ${index + 1} down`"
                            @click="moveOption(index, 1)"
                        >
                            <ArrowDown />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="size-7 shrink-0"
                            :aria-label="`Remove choice ${index + 1}`"
                            @click="removeOption(index)"
                        >
                            <X />
                        </Button>
                    </div>
                    <div class="flex items-center gap-2 pl-1">
                        <span class="text-xs text-muted-foreground"
                            >Stored as</span
                        >
                        <Input
                            class="h-7 font-mono text-xs"
                            :model-value="option.value"
                            :aria-label="`Choice ${index + 1} stored value`"
                            :aria-invalid="
                                option.value === '' ||
                                duplicateValues.has(option.value)
                                    ? true
                                    : undefined
                            "
                            @update:model-value="
                                setValue(index, String($event))
                            "
                        />
                    </div>
                </li>
            </ul>
            <p v-if="duplicateValues.size > 0" class="text-sm text-destructive">
                Two choices are stored the same way. Give each one its own
                stored value.
            </p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="justify-self-start"
                @click="addOption"
            >
                <Plus /> Add a choice
            </Button>
        </template>

        <template v-else-if="source.kind === 'model'">
            <div class="grid gap-1.5">
                <Label :for="`${id}-model`">Model</Label>
                <Input
                    :id="`${id}-model`"
                    class="font-mono"
                    :model-value="source.model"
                    placeholder="page"
                    @update:model-value="updateModel({ model: String($event) })"
                />
            </div>
            <div class="grid gap-1.5">
                <Label :for="`${id}-label-field`"
                    >Show this field as the choice</Label
                >
                <Input
                    :id="`${id}-label-field`"
                    class="font-mono"
                    :model-value="source.label_field"
                    placeholder="title"
                    @update:model-value="
                        updateModel({ label_field: String($event) })
                    "
                />
            </div>
        </template>

        <div v-else class="grid gap-1.5">
            <Label :for="`${id}-hook`">Name</Label>
            <Input
                :id="`${id}-hook`"
                class="font-mono"
                :model-value="source.name"
                placeholder="module.choices"
                @update:model-value="updateHook(String($event))"
            />
            <p class="text-xs text-muted-foreground">
                The name the module gives its list of choices.
            </p>
        </div>
    </div>
</template>

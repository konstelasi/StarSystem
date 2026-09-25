<script setup lang="ts">
import { computed } from 'vue';
import OptionsSourceEditor from '@/components/builder/OptionsSourceEditor.vue';
import StringListEditor from '@/components/builder/StringListEditor.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { asNumber, asString, asStringList } from '@/fields/support';
import type { SettingDescriptor } from '@/types/schema';

const props = defineProps<{
    setting: SettingDescriptor;
    modelValue: unknown;
    /** Unique per field, so labels point at the right input. */
    idPrefix: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>();

const id = computed(() => `${props.idPrefix}-${props.setting.key}`);
const helpId = computed(() =>
    props.setting.help ? `${id.value}-help` : undefined,
);

function onNumber(raw: string | number) {
    emit('update:modelValue', asNumber(raw));
}
</script>

<template>
    <div
        v-if="setting.input === 'boolean'"
        class="flex items-start justify-between gap-3"
    >
        <div class="grid gap-1">
            <Label :for="id">{{ setting.label }}</Label>
            <p
                v-if="setting.help"
                :id="helpId"
                class="text-xs text-muted-foreground"
            >
                {{ setting.help }}
            </p>
        </div>
        <Switch
            :id="id"
            :model-value="modelValue === true"
            :aria-describedby="helpId"
            @update:model-value="emit('update:modelValue', $event === true)"
        />
    </div>

    <div v-else class="grid gap-1.5">
        <Label :for="id">{{ setting.label }}</Label>

        <Input
            v-if="setting.input === 'text'"
            :id="id"
            :model-value="asString(modelValue)"
            :aria-describedby="helpId"
            @update:model-value="emit('update:modelValue', String($event))"
        />

        <Input
            v-else-if="setting.input === 'number'"
            :id="id"
            type="number"
            step="any"
            class="tabular-nums"
            :model-value="asNumber(modelValue) ?? ''"
            :aria-describedby="helpId"
            @update:model-value="onNumber"
        />

        <Select
            v-else-if="setting.input === 'select'"
            :model-value="asString(modelValue) || undefined"
            @update:model-value="emit('update:modelValue', $event)"
        >
            <SelectTrigger :id="id" class="w-full" :aria-describedby="helpId">
                <SelectValue placeholder="Choose…" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="choice in setting.choices ?? []"
                    :key="choice.value"
                    :value="choice.value"
                >
                    {{ choice.label }}
                </SelectItem>
            </SelectContent>
        </Select>

        <OptionsSourceEditor
            v-else-if="setting.input === 'options_source'"
            :id="id"
            :model-value="modelValue"
            @update:model-value="emit('update:modelValue', $event)"
        />

        <StringListEditor
            v-else-if="setting.input === 'string_list'"
            :id="id"
            :model-value="asStringList(modelValue)"
            :described-by="helpId"
            @update:model-value="emit('update:modelValue', $event)"
        />

        <p
            v-if="setting.help"
            :id="helpId"
            class="text-xs text-muted-foreground"
        >
            {{ setting.help }}
        </p>
    </div>
</template>

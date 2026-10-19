<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { reachOf, withReach, withTicked, type Reach } from '@/lib/abilityReach';
import type { RoleAbility } from '@/types/roles';

const props = defineProps<{
    ability: RoleAbility;
    /** The keys of the abilities ticked so far, in the area or the model this row is in. */
    selected: string[];
    disabled: boolean;
}>();

const emit = defineEmits<{
    'update:selected': [selected: string[]];
}>();

const setReach = (level: unknown) =>
    emit(
        'update:selected',
        withReach(props.selected, props.ability, level as Reach),
    );
</script>

<template>
    <div
        v-if="ability.own"
        class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2"
    >
        <span class="text-sm">{{ ability.label }}</span>
        <RadioGroup
            :model-value="reachOf(selected, ability)"
            :disabled="disabled"
            class="flex flex-wrap gap-x-5 gap-y-1"
            :aria-label="ability.label"
            @update:model-value="setReach"
        >
            <Label class="flex items-center gap-2 font-normal">
                <RadioGroupItem value="none" />
                None
            </Label>
            <Label class="flex items-center gap-2 font-normal">
                <RadioGroupItem value="own" :disabled="!ability.own.held" />
                Their own entries
            </Label>
            <Label class="flex items-center gap-2 font-normal">
                <RadioGroupItem value="all" :disabled="!ability.held" />
                Any entry
            </Label>
        </RadioGroup>
    </div>
    <Label v-else class="flex items-center gap-3 font-normal">
        <Checkbox
            :model-value="selected.includes(ability.key)"
            :disabled="disabled || !ability.held"
            @update:model-value="
                (on) =>
                    emit(
                        'update:selected',
                        withTicked(selected, ability.key, on === true),
                    )
            "
        />
        {{ ability.label }}
    </Label>
</template>

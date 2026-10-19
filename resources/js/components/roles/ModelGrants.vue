<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import AbilityChoice from '@/components/roles/AbilityChoice.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { ModelAbilityRow, ModelChoice, RoleAbility } from '@/types/roles';

/**
 * What a role may do on particular models, one folding row per model. The
 * choices add to the role's areas and never take anything away.
 */
const props = defineProps<{
    models: ModelChoice[];
    rows: ModelAbilityRow[];
    /** The abilities given, by model id. A model with none is left out. */
    modelValue: Record<number, string[]>;
    disabled: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: Record<number, string[]>];
}>();

const selectedFor = (id: number) => props.modelValue[id] ?? [];

function change(id: number, selected: string[]) {
    const next = { ...props.modelValue };

    if (selected.length === 0) {
        delete next[id];
    } else {
        next[id] = selected;
    }

    emit('update:modelValue', next);
}

/** The rows as the areas have them, with what the person editing may give for this model. */
const rowsFor = (model: ModelChoice): RoleAbility[] =>
    props.rows.map((row) => ({
        key: row.key,
        label: row.label,
        held: model.held.includes(row.key),
        own: row.own
            ? { ...row.own, held: model.held.includes(row.own.key) }
            : undefined,
    }));

function summary(id: number): string {
    const count = selectedFor(id).length;

    if (count === 0) {
        return 'Nothing extra';
    }

    return count === 1 ? '1 ability' : `${count} abilities`;
}
</script>

<template>
    <div class="grid gap-2">
        <Collapsible
            v-for="model in models"
            :key="model.id"
            :default-open="selectedFor(model.id).length > 0"
            class="rounded-md border"
        >
            <CollapsibleTrigger
                class="group flex w-full items-center gap-2 px-3 py-2 text-left text-sm"
            >
                <ChevronRight
                    class="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-90"
                />
                <span class="font-medium">{{ model.label }}</span>
                <span v-if="model.group" class="text-muted-foreground">
                    {{ model.group }}
                </span>
                <span
                    class="ml-auto tabular-nums"
                    :class="{
                        'text-muted-foreground':
                            selectedFor(model.id).length === 0,
                    }"
                >
                    {{ summary(model.id) }}
                </span>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div class="grid gap-3 border-t px-3 py-3">
                    <AbilityChoice
                        v-for="ability in rowsFor(model)"
                        :key="ability.key"
                        :ability="ability"
                        :selected="selectedFor(model.id)"
                        :disabled="disabled"
                        @update:selected="(next) => change(model.id, next)"
                    />
                </div>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>

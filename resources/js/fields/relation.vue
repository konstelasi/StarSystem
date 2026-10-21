<script setup lang="ts">
import { Link2, X } from '@lucide/vue';
import { computed, reactive } from 'vue';
import { useEntryFormContext } from '@/components/entries/entryFormContext';
import EntryPicker from '@/components/entries/EntryPicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAbilities } from '@/composables/useAbilities';
import FieldShell from '@/fields/parts/FieldShell.vue';
import { asNumber, asString, booleanSetting } from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';
import type { LookupEntry } from '@/lib/entryLookup';
import type { LinkLabel, ModelLink } from '@/types/schema';

/**
 * Stores the ids of linked entries, never their names: a list when
 * `multiple` is on, otherwise one id or null. A lone id saved before
 * `multiple` was switched on reads as a list of one. A chip shows the name
 * the form's server answer gives for its id, or the one the person just
 * picked.
 */
const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{
    'update:modelValue': [value: number | number[] | null];
}>();

// The most entries one field links to, as on the server.
const MAX_LINKS = 50;

type Chip = { id: number; label: string; state: LinkLabel['state'] };

const { can } = useAbilities();

// The model builder's preview has no form around it, so no entries to name
// and nothing to fetch.
const context = useEntryFormContext();
const inForm = context !== null;

const multiple = computed(() => booleanSetting(props.field, 'multiple'));

const link = computed<ModelLink>(() => {
    const raw = props.field.settings.link;
    const setting: Record<string, unknown> =
        typeof raw === 'object' && raw !== null
            ? (raw as Record<string, unknown>)
            : {};

    return {
        model: asNumber(setting.model),
        label_field: asString(setting.label_field) || null,
    };
});

// A link still being set up lacks the model or the field naming its entries.
const target = computed(() => {
    const { model, label_field: labelField } = link.value;

    return model !== null && labelField !== null ? { model, labelField } : null;
});

const canView = computed(
    () => target.value !== null && can('entries.view', target.value.model),
);

const ids = computed(() => {
    const list: unknown[] = Array.isArray(props.modelValue)
        ? props.modelValue
        : [props.modelValue];
    const found = list
        .map(asNumber)
        .filter(
            (id): id is number => id !== null && Number.isInteger(id) && id > 0,
        );

    return [...new Set(found)];
});

// Names of entries picked here, which the form's server answer predates.
const picked = reactive(new Map<number, string>());

function chipFor(id: number): Chip {
    if (!canView.value) {
        return { id, label: `Entry ${id}`, state: 'hidden' };
    }

    const name = picked.get(id);

    if (name !== undefined) {
        return { id, label: name, state: 'ok' };
    }

    const known = context?.links.value[String(id)];

    return known ? { id, ...known } : { id, label: `Entry ${id}`, state: 'ok' };
}

const chips = computed(() => ids.value.map(chipFor));

const muted = (chip: Chip) =>
    chip.state === 'trashed' || chip.state === 'missing';

const removable = computed(() => inForm && canView.value && !props.disabled);

const chooseText = computed(() =>
    multiple.value ? 'Choose entries' : 'Choose an entry',
);

function setIds(next: number[]) {
    emit('update:modelValue', multiple.value ? next : (next[0] ?? null));
}

const remove = (id: number) => setIds(ids.value.filter((item) => item !== id));

function choose(items: LookupEntry[]) {
    for (const item of items) {
        // An empty label means the picker never loaded that entry, so the
        // name already known for it stays.
        if (item.label !== '') {
            picked.set(item.id, item.label);
        }
    }

    setIds(items.map((item) => item.id));
}
</script>

<template>
    <FieldShell
        v-slot="{ id, labelId, describedBy, invalid }"
        :field="field"
        :error="error"
    >
        <p
            v-if="target === null"
            :id="id"
            tabindex="-1"
            class="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
        >
            Choose what this links to in the field settings.
        </p>

        <div
            v-else
            class="rounded-md border border-dashed p-4"
            :class="{ 'border-destructive': invalid }"
        >
            <ul v-if="chips.length > 0" class="mb-3 flex flex-wrap gap-2">
                <li v-for="chip in chips" :key="chip.id" class="max-w-full">
                    <Badge
                        :variant="muted(chip) ? 'outline' : 'secondary'"
                        class="max-w-full overflow-visible text-sm"
                        :class="{
                            'text-muted-foreground': muted(chip),
                            'border-destructive/40': chip.state === 'missing',
                        }"
                    >
                        <span class="truncate" :title="chip.label">{{
                            chip.label
                        }}</span>
                        <Button
                            v-if="removable"
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="-mr-1.5 size-5 rounded-full"
                            :aria-label="`Remove ${chip.label}`"
                            @click="remove(chip.id)"
                        >
                            <X class="size-3" />
                        </Button>
                    </Badge>
                </li>
            </ul>

            <Button
                v-if="!inForm"
                :id="id"
                type="button"
                variant="outline"
                size="sm"
                disabled
            >
                <Link2 />
                {{ chooseText }}
            </Button>
            <p
                v-else-if="!canView"
                :id="id"
                tabindex="-1"
                class="text-sm text-muted-foreground"
            >
                Your role can't open the model this links to.
            </p>
            <EntryPicker
                v-else
                :model-id="target.model"
                :label-field="target.labelField"
                :multiple="multiple"
                :max="MAX_LINKS"
                :selected="ids"
                @select="choose"
            >
                <template #trigger>
                    <Button
                        :id="id"
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="disabled"
                        :aria-labelledby="`${labelId} ${id}`"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                    >
                        <Link2 />
                        {{ chooseText }}
                    </Button>
                </template>
            </EntryPicker>
        </div>
    </FieldShell>
</template>

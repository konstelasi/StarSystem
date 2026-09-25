<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    id: string;
    modelValue: string[];
    placeholder?: string;
    describedBy?: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

const draft = ref('');

function add() {
    const value = draft.value.trim();

    if (value !== '' && !props.modelValue.includes(value)) {
        emit('update:modelValue', [...props.modelValue, value]);
    }

    draft.value = '';
}

function remove(value: string) {
    emit(
        'update:modelValue',
        props.modelValue.filter((item) => item !== value),
    );
}
</script>

<template>
    <div class="grid gap-2">
        <ul v-if="modelValue.length > 0" class="flex flex-wrap gap-1.5">
            <li
                v-for="item in modelValue"
                :key="item"
                class="inline-flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pr-0.5 pl-2.5 font-mono text-xs"
            >
                {{ item }}
                <button
                    type="button"
                    class="flex size-5 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                    :aria-label="`Remove ${item}`"
                    @click="remove(item)"
                >
                    <X class="size-3" />
                </button>
            </li>
        </ul>
        <div class="flex gap-2">
            <Input
                :id="id"
                v-model="draft"
                class="h-8"
                :placeholder="placeholder ?? 'Type and press Enter'"
                :aria-describedby="describedBy"
                @keydown.enter.prevent="add"
            />
            <Button
                type="button"
                variant="outline"
                size="icon-sm"
                aria-label="Add"
                :disabled="draft.trim() === ''"
                @click="add"
            >
                <Plus />
            </Button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * Previous and next for a list that comes a page at a time, with a line
 * saying which rows are showing. It only reports the page asked for. The
 * page decides how to fetch it, so filters and sort stay in place.
 */
defineProps<{
    page: number;
    pages: number;
    from: number;
    to: number;
    total: number;
    /** What the rows are, for the line of text. */
    noun: string;
}>();

defineEmits<{ go: [page: number] }>();
</script>

<template>
    <div
        v-if="total > 0"
        class="flex flex-wrap items-center justify-between gap-3"
    >
        <p class="text-sm text-muted-foreground">
            Showing {{ from }} to {{ to }} of {{ total }} {{ noun }}
        </p>
        <div v-if="pages > 1" class="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="page <= 1"
                @click="$emit('go', page - 1)"
            >
                <ChevronLeft />
                Previous
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="page >= pages"
                @click="$emit('go', page + 1)"
            >
                Next
                <ChevronRight />
            </Button>
        </div>
    </div>
</template>

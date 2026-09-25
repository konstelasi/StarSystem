<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Ban,
    Loader2,
    PlusCircle,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useBuilderContext } from '@/components/builder/context';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    preview as previewRoute,
    save as saveRoute,
} from '@/routes/admin/models/builder';
import type { SavePreview } from '@/types/schema';

const props = defineProps<{
    open: boolean;
    preview: SavePreview | null | undefined;
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const { builder, announce } = useBuilderContext();

type Phase = 'loading' | 'review' | 'saving' | 'error';
const phase = ref<Phase>('loading');
const errorMessage = ref<string | null>(null);

const errors = computed(() => props.preview?.errors ?? []);
const operations = computed(() => props.preview?.operations ?? []);
const canSave = computed(
    () => phase.value === 'review' && errors.value.length === 0,
);

const OP_ICONS = {
    metadata: ArrowRight,
    add: PlusCircle,
    rename: ArrowRight,
    retype: ArrowRight,
    promote: ArrowRight,
    demote: ArrowRight,
    delete: Trash2,
} as const;

watch(
    () => props.open,
    (open) => {
        if (open) {
            requestPreview();
        }
    },
);

/**
 * A plain JSON clone: `router.post`'s data must be `FormDataConvertible`,
 * which a reactive schema with `Record<string, unknown>` settings isn't.
 */
function schemaPayload() {
    return { schema: JSON.parse(JSON.stringify(builder.schema.value)) };
}

function requestPreview() {
    phase.value = 'loading';
    errorMessage.value = null;

    router.post(previewRoute().url, schemaPayload(), {
        preserveState: true,
        preserveScroll: true,
        only: ['preview'],
        onSuccess: () => {
            phase.value = 'review';
        },
        onError: () => {
            phase.value = 'error';
            errorMessage.value = "Couldn't check these changes. Try again.";
        },
    });
}

function confirmSave() {
    phase.value = 'saving';

    router.post(saveRoute().url, schemaPayload(), {
        preserveState: true,
        preserveScroll: true,
        only: ['saved', 'preview'],
        onSuccess: (page) => {
            if (page.props.saved) {
                builder.markSaved(builder.schema.value);
                announce('Saved.');
                emit('update:open', false);
                return;
            }

            // A well-formed response that still didn't save: the diff
            // picked up new errors, or the batch failed partway. Show the
            // reason instead of the generic message below.
            phase.value = 'review';
            errorMessage.value = null;
        },
        onError: () => {
            phase.value = 'error';
            errorMessage.value = "Couldn't save. Try again.";
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Review changes</DialogTitle>
                <DialogDescription>
                    What saving now would do to the model and its data.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="phase === 'loading'"
                class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin" aria-hidden="true" />
                Checking…
            </div>

            <div
                v-else-if="phase === 'error'"
                class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
            >
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {{ errorMessage }}
            </div>

            <div v-else class="grid gap-3">
                <p
                    v-if="operations.length === 0 && errors.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No changes to save.
                </p>

                <ul v-if="operations.length > 0" class="grid gap-1.5">
                    <li
                        v-for="(operation, index) in operations"
                        :key="`${operation.field_uuid ?? 'model'}-${index}`"
                        class="flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-sm"
                        :class="
                            operation.destructive
                                ? 'border-destructive/30 bg-destructive/5'
                                : 'border-border'
                        "
                    >
                        <component
                            :is="OP_ICONS[operation.op]"
                            class="size-4 shrink-0"
                            :class="
                                operation.destructive
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">{{
                            operation.summary
                        }}</span>
                        <span
                            v-if="operation.destructive"
                            class="shrink-0 text-xs font-medium text-destructive"
                        >
                            Can't be undone
                        </span>
                    </li>
                </ul>

                <div
                    v-if="errors.length > 0"
                    class="grid gap-1.5 rounded-lg border border-destructive/30 bg-destructive/5 p-2.5"
                >
                    <p
                        class="flex items-center gap-2 text-sm font-medium text-destructive"
                    >
                        <Ban class="size-4 shrink-0" aria-hidden="true" />
                        Fix these before saving
                    </p>
                    <ul class="grid gap-1 pl-6 text-sm text-destructive">
                        <li
                            v-for="(error, index) in errors"
                            :key="index"
                            class="list-disc"
                        >
                            {{ error.message }}
                        </li>
                    </ul>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button
                    variant="secondary"
                    :disabled="phase === 'saving'"
                    @click="emit('update:open', false)"
                >
                    Cancel
                </Button>
                <Button :disabled="!canSave" @click="confirmSave">
                    <Loader2
                        v-if="phase === 'saving'"
                        class="animate-spin"
                        aria-hidden="true"
                    />
                    Save changes
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

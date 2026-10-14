<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import EntryController from '@/actions/App/Http/Controllers/Admin/EntryController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

/**
 * Deletes a trashed entry for good. Only the trash offers it, so the one
 * step that can't be undone always comes after one that can.
 */
defineProps<{
    modelId: number;
    entryId: number;
    /** What the row shows for the entry, so the dialog names it. */
    name: string | null;
}>();
</script>

<template>
    <Dialog>
        <DialogTrigger as-child>
            <Button variant="ghost" size="sm" class="text-destructive">
                Delete for good
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="
                    EntryController.destroy.form({
                        model: modelId,
                        entry: entryId,
                    })
                "
                v-slot="{ processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>
                        <template v-if="name">
                            Delete “{{ name }}” for good?
                        </template>
                        <template v-else>Delete this entry for good?</template>
                    </DialogTitle>
                    <DialogDescription>
                        The entry and its values are removed. This can't be
                        undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="mt-6 gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        Delete for good
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import ModelController from '@/actions/App/Http/Controllers/Admin/ModelController';
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

defineProps<{
    model: { id: number; label: string };
}>();
</script>

<template>
    <Dialog>
        <DialogTrigger as-child>
            <Button variant="ghost" size="sm" class="text-destructive">
                <Trash2 />
                Delete
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="ModelController.destroy.form(model.id)"
                v-slot="{ processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>Delete {{ model.label }}?</DialogTitle>
                    <DialogDescription>
                        Every entry in this model is removed in the background.
                        This can't be undone.
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
                        Delete
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
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
import { destroy } from '@/routes/admin/roles';

const props = defineProps<{
    role: { id: number; name: string };
}>();

const open = ref(false);
const processing = ref(false);

function remove() {
    router.delete(destroy(props.role.id).url, {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="ghost" size="sm" class="text-destructive">
                <Trash2 />
                <span class="max-sm:sr-only">Delete</span>
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>Delete {{ role.name }}?</DialogTitle>
                <DialogDescription>
                    Nobody has this role right now, so no one loses access. It
                    can't be undone, but you can make the role again.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="mt-6 gap-2">
                <DialogClose as-child>
                    <Button type="button" variant="secondary">Cancel</Button>
                </DialogClose>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="processing"
                    @click="remove"
                >
                    <Spinner v-if="processing" />
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

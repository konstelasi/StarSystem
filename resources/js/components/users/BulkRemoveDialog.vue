<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { UserMinus } from '@lucide/vue';
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
import { destroy } from '@/routes/admin/users/bulk';

const props = defineProps<{ ids: number[] }>();

const emit = defineEmits<{ done: [] }>();

const open = ref(false);
const processing = ref(false);

function remove() {
    router.delete(destroy().url, {
        data: { ids: props.ids },
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onSuccess: () => emit('done'),
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
            <Button variant="outline" class="text-destructive">
                <UserMinus />
                Remove
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>
                    Remove
                    {{
                        ids.length === 1 ? '1 person' : `${ids.length} people`
                    }}?
                </DialogTitle>
                <DialogDescription>
                    They can no longer sign in to this site. Their accounts and
                    anything they wrote stay, and they keep access to any other
                    site they belong to. Anyone in the selection you may not
                    remove is left as they are.
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
                    Remove
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

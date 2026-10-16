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
import { destroy } from '@/routes/admin/users';

const props = defineProps<{
    member: { id: number; name: string };
}>();

const open = ref(false);
const processing = ref(false);

function remove() {
    router.delete(destroy(props.member.id).url, {
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
                <UserMinus />
                <span class="max-sm:sr-only">Remove</span>
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>Remove {{ member.name }}?</DialogTitle>
                <DialogDescription>
                    They can no longer sign in to this site. Their account and
                    anything they wrote stay, and they keep access to any other
                    site they belong to.
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

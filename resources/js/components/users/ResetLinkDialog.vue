<script setup lang="ts">
import { Copy } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

export type ResetLink = {
    name: string;
    email: string;
    link: string;
    mailConfigured: boolean;
    minutes: number;
};

const props = defineProps<{ reset: ResetLink | null }>();

const open = defineModel<boolean>('open', { default: false });

// The link is shown once, so the dialog says plainly why it is shown at all.
const message = computed(() =>
    props.reset?.mailConfigured
        ? 'The email could not be sent. Send them this link yourself.'
        : "Email isn't set up on this install, so send them this link yourself.",
);

async function copy() {
    try {
        await navigator.clipboard.writeText(props.reset?.link ?? '');
        toast.success('Link copied.');
    } catch {
        toast.error('Your browser blocked copying. Select the link instead.');
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>Reset link for {{ reset?.name }}</DialogTitle>
                <DialogDescription>
                    {{ message }} Whoever opens it can choose a new password for
                    {{ reset?.email }}, so pass it on only to them. It works for
                    {{ reset?.minutes }} minutes and is shown only now.
                </DialogDescription>
            </DialogHeader>

            <div class="flex items-center gap-2">
                <Input
                    :model-value="reset?.link ?? ''"
                    readonly
                    aria-label="Password reset link"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button type="button" variant="secondary" @click="copy">
                    <Copy />
                    Copy
                </Button>
            </div>

            <DialogFooter class="mt-6">
                <DialogClose as-child>
                    <Button type="button">Done</Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

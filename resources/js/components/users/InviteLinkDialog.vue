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

export type InviteLink = {
    email: string;
    link: string;
    emailed: boolean;
    mailConfigured: boolean;
};

const props = defineProps<{ invite: InviteLink | null }>();

const open = defineModel<boolean>('open', { default: false });

// The link is shown once, so the dialog says plainly whether the email went.
const message = computed(() => {
    if (props.invite?.emailed) {
        return `We emailed ${props.invite.email}. You can also send them this link yourself.`;
    }

    return props.invite?.mailConfigured
        ? 'The email could not be sent. Copy this link and send it yourself.'
        : "Email isn't set up on this install, so copy this link and send it yourself.";
});

async function copy() {
    try {
        await navigator.clipboard.writeText(props.invite?.link ?? '');
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
                <DialogTitle>Invitation for {{ invite?.email }}</DialogTitle>
                <DialogDescription>
                    {{ message }} The link works for a week and only once. It is
                    shown only now, so copy it before you close this.
                </DialogDescription>
            </DialogHeader>

            <div class="flex items-center gap-2">
                <Input
                    :model-value="invite?.link"
                    readonly
                    aria-label="Invitation link"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button type="button" variant="outline" @click="copy">
                    <Copy />
                    Copy
                </Button>
            </div>

            <DialogFooter class="mt-4">
                <DialogClose as-child>
                    <Button type="button">Done</Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

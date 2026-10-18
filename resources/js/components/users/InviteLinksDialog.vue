<script setup lang="ts">
import { Copy } from '@lucide/vue';
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
import type { InviteLink } from '@/components/users/InviteLinkDialog.vue';

defineProps<{ invites: InviteLink[] }>();

const open = defineModel<boolean>('open', { default: false });

async function copy(link: string) {
    try {
        await navigator.clipboard.writeText(link);
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
                <DialogTitle>
                    {{
                        invites.length === 1
                            ? 'One invitation to send yourself'
                            : `${invites.length} invitations to send yourself`
                    }}
                </DialogTitle>
                <DialogDescription>
                    The email didn't reach these people, or email isn't set up
                    on this install. Each link works for a week and only once,
                    and is shown only now, so copy them before you close this.
                </DialogDescription>
            </DialogHeader>

            <ul class="grid max-h-80 gap-4 overflow-y-auto">
                <li
                    v-for="invite in invites"
                    :key="invite.email"
                    class="grid gap-1.5"
                >
                    <p class="text-sm font-medium">{{ invite.email }}</p>
                    <div class="flex items-center gap-2">
                        <Input
                            :model-value="invite.link"
                            readonly
                            :aria-label="`Invitation link for ${invite.email}`"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        />
                        <Button
                            type="button"
                            variant="outline"
                            @click="copy(invite.link)"
                        >
                            <Copy />
                            Copy
                        </Button>
                    </div>
                </li>
            </ul>

            <DialogFooter class="mt-4">
                <DialogClose as-child>
                    <Button type="button">Done</Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

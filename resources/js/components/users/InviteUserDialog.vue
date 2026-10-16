<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/admin/users/invites';

const props = defineProps<{
    roles: { id: number; name: string; assignable: boolean }[];
}>();

const open = defineModel<boolean>('open', { default: false });

const assignable = computed(() =>
    props.roles.filter((role) => role.assignable),
);

const form = useForm({ email: '', role_id: '' });

// Start on the first role the person may hand out each time it opens.
watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        form.role_id = String(assignable.value[0]?.id ?? '');
    }
});

function submit() {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <form @submit.prevent="submit">
                <DialogHeader class="space-y-3">
                    <DialogTitle>Invite someone</DialogTitle>
                    <DialogDescription>
                        They get a link that works for a week and only once. If
                        they have no account yet, the link lets them make one.
                    </DialogDescription>
                </DialogHeader>

                <div class="my-6 grid gap-4">
                    <div class="grid gap-2">
                        <Label for="invite-email">Email</Label>
                        <Input
                            id="invite-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="invite-role">Role</Label>
                        <Select v-model="form.role_id">
                            <SelectTrigger id="invite-role" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="role in assignable"
                                    :key="role.id"
                                    :value="String(role.id)"
                                >
                                    {{ role.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.role_id" />
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Send invitation
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
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
import { ownership } from '@/routes/admin/users';

const props = defineProps<{
    candidates: { id: number; name: string; email: string }[];
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({ member_id: '', email: '', password: '' });

const chosen = computed(() =>
    props.candidates.find(
        (candidate) => String(candidate.id) === form.member_id,
    ),
);

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        form.member_id = String(props.candidates[0]?.id ?? '');
    }
});

function submit() {
    form.transform((data) => ({
        ...data,
        member_id: Number(data.member_id),
    })).post(ownership().url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <form @submit.prevent="submit">
                <DialogHeader class="space-y-3">
                    <DialogTitle>Hand over ownership</DialogTitle>
                    <DialogDescription>
                        The owner updates the program and installs modules for
                        every site. You stay an Administrator of each site, but
                        you can't take ownership back unless the new owner hands
                        it to you.
                    </DialogDescription>
                </DialogHeader>

                <div class="my-6 grid gap-4">
                    <div class="grid gap-2">
                        <Label for="owner-member">New owner</Label>
                        <Select v-model="form.member_id">
                            <SelectTrigger id="owner-member" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="candidate in candidates"
                                    :key="candidate.id"
                                    :value="String(candidate.id)"
                                >
                                    {{ candidate.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.member_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="owner-email">Their email</Label>
                        <Input
                            id="owner-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="off"
                            :placeholder="chosen?.email"
                            required
                        />
                        <p class="text-sm text-muted-foreground">
                            Type it as shown to confirm who you mean.
                        </p>
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="owner-password">Your password</Label>
                        <PasswordInput
                            id="owner-password"
                            v-model="form.password"
                            autocomplete="current-password"
                            required
                        />
                        <InputError :message="form.errors.password" />
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="form.processing || !chosen"
                    >
                        <Spinner v-if="form.processing" />
                        Hand over ownership
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

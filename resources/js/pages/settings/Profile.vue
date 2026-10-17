<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const props = defineProps<{
    /** The address a change is waiting on, until its link is opened. */
    pendingEmail: string | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const email = ref(props.pendingEmail ?? user.value.email);

// The password is asked for only when the address is edited.
const emailChanged = computed(
    () => email.value.trim().toLowerCase() !== user.value.email.toLowerCase(),
);

// After a save, show the address that now stands or is waiting.
watch(
    () => [user.value.email, props.pendingEmail],
    () => {
        email.value = props.pendingEmail ?? user.value.email;
    },
);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            :reset-on-success="['current_password']"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    v-model="email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
                <p
                    v-if="pendingEmail"
                    class="text-sm text-muted-foreground"
                    data-test="pending-email"
                >
                    Waiting for you to open the link we sent to
                    <span class="text-foreground">{{ pendingEmail }}</span
                    >. Until then your email stays {{ user.email }}. Enter
                    {{ user.email }} here and save to cancel the change.
                </p>
            </div>

            <div v-if="emailChanged" class="grid gap-2">
                <Label for="current_password">Current password</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Current password"
                />
                <p class="text-sm text-muted-foreground">
                    Needed to change your email address.
                </p>
                <InputError class="mt-2" :message="errors.current_password" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>

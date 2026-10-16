<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { accept } from '@/routes/invitations';

const props = defineProps<{
    token: string;
    siteName: string;
    email: string;
    role: string;
    invitedBy: string | null;
    /** new: make an account. join: signed in as the invited email. other: signed in as someone else. */
    mode: 'new' | 'join' | 'other';
    passwordRules: string;
}>();

const who = computed(() => props.invitedBy ?? 'Someone');

setLayoutProps({
    title: `Join ${props.siteName}`,
    description: `${who.value} invited ${props.email} as ${props.role}.`,
});
</script>

<template>
    <Head :title="`Join ${siteName}`" />

    <div class="grid gap-6">
        <Form
            v-if="mode === 'new'"
            v-bind="accept.form(token)"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">Your name</Label>
                    <Input
                        id="name"
                        name="name"
                        autocomplete="name"
                        autofocus
                        required
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        autocomplete="new-password"
                        :passwordrules="passwordRules"
                        required
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        autocomplete="new-password"
                        :passwordrules="passwordRules"
                        required
                    />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing"
                    data-test="accept-invitation-button"
                >
                    <Spinner v-if="processing" />
                    Create account and join
                </Button>
            </div>
        </Form>

        <Form
            v-else-if="mode === 'join'"
            v-bind="accept.form(token)"
            v-slot="{ processing }"
        >
            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="accept-invitation-button"
            >
                <Spinner v-if="processing" />
                Join {{ siteName }}
            </Button>
        </Form>

        <div v-else class="grid gap-4">
            <p class="text-center text-sm">
                You are signed in as someone else. Sign out, then open the link
                in your invitation again.
            </p>
            <Button as-child variant="secondary" class="w-full">
                <Link :href="logout()" method="post" as="button">
                    Sign out
                </Link>
            </Button>
        </div>
    </div>
</template>

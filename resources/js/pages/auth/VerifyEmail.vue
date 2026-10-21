<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Confirm your email',
        description:
            'Open the link we send to your address, then come back and carry on.',
    },
});

defineProps<{
    email: string;
    status?: string;
}>();
</script>

<template>
    <Head title="Confirm your email" />

    <div class="space-y-6">
        <p class="text-center text-sm">
            {{ email }} has not been confirmed yet. Send yourself the link and
            open it to unlock the admin.
        </p>

        <div
            v-if="status === 'verification-link-sent'"
            class="text-center text-sm font-medium text-success"
        >
            A new link is on its way to {{ email }}.
        </div>

        <Form v-bind="send.form()" v-slot="{ processing }">
            <Button
                class="w-full"
                :disabled="processing"
                data-test="resend-verification-button"
            >
                <Spinner v-if="processing" />
                Send the link
            </Button>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>Wrong address?</span>
            <TextLink :href="edit()">Change it</TextLink>
            <span>or</span>
            <Link
                :href="logout()"
                method="post"
                as="button"
                class="underline underline-offset-4"
                data-test="logout-button"
            >
                sign out
            </Link>
        </div>
    </div>
</template>

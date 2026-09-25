<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import InstallLayout from '@/layouts/InstallLayout.vue';
import { store } from '@/routes/install/admin';

defineOptions({ layout: InstallLayout });

defineProps<{
    defaults: { site_name: string; app_url: string };
}>();
</script>

<template>
    <Head title="Your account" />

    <div class="flex flex-col gap-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">
                Name your site and create your account
            </h1>
            <p class="text-sm text-muted-foreground">
                This account manages everything on the site. You can change all
                of it later.
            </p>
        </header>

        <Form
            v-bind="store.form()"
            :reset-on-error="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-2">
                <Label for="site_name">Site name</Label>
                <Input
                    id="site_name"
                    name="site_name"
                    required
                    v-focus
                    :default-value="defaults.site_name"
                />
                <InputError :message="errors.site_name" />
            </div>

            <div class="grid gap-2">
                <Label for="app_url">Site address</Label>
                <Input
                    id="app_url"
                    name="app_url"
                    type="url"
                    required
                    :default-value="defaults.app_url"
                />
                <p class="text-xs text-muted-foreground">
                    The address visitors type to reach this site. Use https://
                    if your host gives you a certificate.
                </p>
                <InputError :message="errors.app_url" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Your name</Label>
                <Input id="name" name="name" required autocomplete="name" />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Your email address</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Password again</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                />
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="finish-button"
            >
                <Spinner v-if="processing" />
                Finish installing
            </Button>
        </Form>
    </div>
</template>

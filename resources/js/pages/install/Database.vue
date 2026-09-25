<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import InstallLayout from '@/layouts/InstallLayout.vue';
import { store } from '@/routes/install/database';

defineOptions({ layout: InstallLayout });

defineProps<{
    defaults: {
        host: string;
        port: number;
        database: string;
        username: string;
    };
    reclaim: boolean;
}>();
</script>

<template>
    <Head title="Database" />

    <div class="flex flex-col gap-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">
                Connect your database
            </h1>
            <p class="text-sm text-muted-foreground">
                Create a MySQL or MariaDB database and a user for it in your
                hosting control panel (often under "MySQL Databases"), then
                enter the details here.
            </p>
        </header>

        <Alert v-if="reclaim">
            <AlertTitle>Please enter your database details again</AlertTitle>
            <AlertDescription>
                For safety, only the person who started this install can finish
                it. Entering the same details again proves it's you.
            </AlertDescription>
        </Alert>

        <Form
            v-bind="store.form()"
            :reset-on-error="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <Alert v-if="errors.connection" variant="destructive">
                <AlertTriangle />
                <AlertTitle>That didn't work</AlertTitle>
                <AlertDescription>{{ errors.connection }}</AlertDescription>
            </Alert>

            <div class="grid gap-6 sm:grid-cols-[1fr_7rem]">
                <div class="grid gap-2">
                    <Label for="host">Database server</Label>
                    <Input
                        id="host"
                        name="host"
                        required
                        :default-value="defaults.host"
                    />
                    <p class="text-xs text-muted-foreground">
                        Usually "localhost".
                    </p>
                    <InputError :message="errors.host" />
                </div>
                <div class="grid gap-2">
                    <Label for="port">Port</Label>
                    <Input
                        id="port"
                        name="port"
                        type="number"
                        required
                        :default-value="defaults.port"
                    />
                    <InputError :message="errors.port" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="database">Database name</Label>
                <Input
                    id="database"
                    name="database"
                    required
                    v-focus
                    :default-value="defaults.database"
                />
                <InputError :message="errors.database" />
            </div>

            <div class="grid gap-2">
                <Label for="username">Database username</Label>
                <Input
                    id="username"
                    name="username"
                    required
                    autocomplete="off"
                    :default-value="defaults.username"
                />
                <InputError :message="errors.username" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Database password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="off"
                />
                <InputError :message="errors.password" />
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="database-button"
            >
                <Spinner v-if="processing" />
                Test and save
            </Button>
        </Form>
    </div>
</template>

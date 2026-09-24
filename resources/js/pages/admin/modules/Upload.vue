<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import ModuleController from '@/actions/App/Http/Controllers/Admin/ModuleController';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create, index } from '@/routes/admin/modules';

defineProps<{
    zipSupported: boolean;
    maxKilobytes: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Modules', href: index() },
            { title: 'Upload', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Upload module" />

    <div class="flex max-w-2xl flex-1 flex-col gap-4 p-4">
        <Alert variant="destructive">
            <ShieldAlert />
            <AlertTitle
                >Modules run their own PHP code on your server</AlertTitle
            >
            <AlertDescription>
                <p>
                    A module can read and change everything on this install:
                    every site's content, user accounts and passwords, and the
                    files on the server. StarSystem can't check what a module
                    does. Only upload modules from people you trust.
                </p>
            </AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <CardTitle>Upload a module</CardTitle>
                <CardDescription>
                    A zip with one module folder in it, with its
                    <code>module.json</code> at the top. Uploading a newer
                    version of an installed module upgrades it. New modules
                    start disabled.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p v-if="!zipSupported" class="text-sm text-destructive">
                    This server's PHP has no zip support. Unzip the module on
                    your computer and upload its folder into
                    <code>modules/</code> over FTP instead.
                </p>
                <Form
                    v-else
                    v-bind="ModuleController.store.form()"
                    class="grid gap-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="module">Module zip</Label>
                        <Input
                            id="module"
                            name="module"
                            type="file"
                            accept=".zip,application/zip"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Up to {{ Math.round(maxKilobytes / 1024) }} MB.
                        </p>
                        <InputError :message="errors.module" />
                    </div>

                    <div class="flex gap-2">
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            Install
                        </Button>
                        <Button variant="secondary" as-child>
                            <Link :href="index()">Cancel</Link>
                        </Button>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>

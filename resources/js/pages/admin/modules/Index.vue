<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, Puzzle, Upload } from '@lucide/vue';
import ModuleController from '@/actions/App/Http/Controllers/Admin/ModuleController';
import HookSlot from '@/components/HookSlot.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/admin/modules';

type ModuleRow = {
    slug: string | null;
    name: string;
    version: string | null;
    installedVersion: string | null;
    description: string;
    author: string;
    requires: string | null;
    enabled: boolean;
    updatePending: boolean;
    problem: string | null;
    lastError: string | null;
    lastErrorAt: string | null;
};

defineProps<{
    modules: ModuleRow[];
    safeMode: 'env' | 'file' | null;
    coreVersion: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Modules', href: index() }],
    },
});

const formatTime = (iso: string) => new Date(iso).toLocaleString();
</script>

<template>
    <Head title="Modules" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-0.5">
                <h2 class="text-xl font-semibold tracking-tight">Modules</h2>
                <p class="text-sm text-muted-foreground">
                    Modules add features to StarSystem {{ coreVersion }}. They
                    run for every site on this install.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()">
                    <Upload />
                    Upload module
                </Link>
            </Button>
        </div>

        <Alert v-if="safeMode">
            <AlertTriangle />
            <AlertTitle>Safe mode is on</AlertTitle>
            <AlertDescription>
                <p>
                    No module is loaded, whatever it says below. You can still
                    disable or uninstall the module that caused trouble.
                </p>
                <p v-if="safeMode === 'file'">
                    Turn safe mode off by deleting the
                    <code>safe-mode</code> file in the storage folder.
                </p>
                <p v-else>
                    Turn safe mode off by setting
                    <code>STARSYSTEM_SAFE_MODE=false</code> in
                    <code>.env</code>.
                </p>
            </AlertDescription>
        </Alert>

        <HookSlot name="backend.view:modules:index" />

        <Card v-if="modules.length === 0">
            <CardHeader class="items-center text-center">
                <Puzzle class="size-8 text-muted-foreground" />
                <CardTitle>No modules yet</CardTitle>
                <CardDescription>
                    Upload a module as a zip, or copy its folder into
                    <code>modules/</code> over FTP.
                </CardDescription>
            </CardHeader>
        </Card>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <Card
                v-for="module in modules"
                :key="module.slug ?? module.name"
                :data-test="`module-${module.slug ?? module.name}`"
            >
                <CardHeader>
                    <div class="flex flex-wrap items-center gap-2">
                        <CardTitle>{{ module.name }}</CardTitle>
                        <span
                            v-if="module.version"
                            class="text-sm text-muted-foreground tabular-nums"
                        >
                            {{ module.version }}
                        </span>
                        <Badge v-if="module.enabled">Enabled</Badge>
                        <Badge v-else variant="outline">Disabled</Badge>
                        <Badge v-if="module.updatePending" variant="secondary">
                            Update ready
                        </Badge>
                        <Badge
                            v-if="module.problem || module.lastError"
                            variant="destructive"
                        >
                            Needs attention
                        </Badge>
                    </div>
                    <CardDescription v-if="module.description">
                        {{ module.description }}
                    </CardDescription>
                </CardHeader>

                <CardContent class="grid gap-3 text-sm">
                    <dl
                        v-if="module.author || module.requires"
                        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1"
                    >
                        <template v-if="module.author">
                            <dt class="text-muted-foreground">Author</dt>
                            <dd>{{ module.author }}</dd>
                        </template>
                        <template v-if="module.requires">
                            <dt class="text-muted-foreground">Needs</dt>
                            <dd>StarSystem {{ module.requires }}</dd>
                        </template>
                        <template v-if="module.updatePending">
                            <dt class="text-muted-foreground">Database</dt>
                            <dd>
                                At {{ module.installedVersion }}, files at
                                {{ module.version }}
                            </dd>
                        </template>
                    </dl>

                    <p v-if="module.problem" class="text-destructive">
                        {{ module.problem }}
                    </p>

                    <div
                        v-if="module.lastError"
                        class="grid gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3"
                    >
                        <p class="font-medium text-destructive">
                            Switched off after an error<template
                                v-if="module.lastErrorAt"
                            >
                                on
                                {{ formatTime(module.lastErrorAt) }}</template
                            >
                        </p>
                        <p class="font-mono text-xs break-all">
                            {{ module.lastError }}
                        </p>
                        <Form
                            v-if="module.slug"
                            v-bind="ModuleController.dismiss.form(module.slug)"
                            :options="{ preserveScroll: true }"
                        >
                            <Button type="submit" variant="ghost" size="sm">
                                Dismiss
                            </Button>
                        </Form>
                    </div>
                </CardContent>

                <CardFooter v-if="module.slug" class="flex flex-wrap gap-2">
                    <Form
                        v-if="module.enabled"
                        v-bind="ModuleController.disable.form(module.slug)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="secondary"
                            :disabled="processing"
                        >
                            Disable
                        </Button>
                    </Form>
                    <Form
                        v-else-if="module.version && !module.problem"
                        v-bind="ModuleController.enable.form(module.slug)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button type="submit" :disabled="processing">
                            Enable
                        </Button>
                    </Form>

                    <Form
                        v-if="module.updatePending"
                        v-bind="ModuleController.update.form(module.slug)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button type="submit" :disabled="processing">
                            Apply update
                        </Button>
                    </Form>

                    <Dialog>
                        <DialogTrigger as-child>
                            <Button variant="destructive" class="ml-auto">
                                Uninstall
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <Form
                                v-bind="
                                    ModuleController.destroy.form(module.slug)
                                "
                                class="space-y-6"
                                v-slot="{ processing }"
                            >
                                <DialogHeader class="space-y-3">
                                    <DialogTitle>
                                        Uninstall {{ module.name }}?
                                    </DialogTitle>
                                    <DialogDescription>
                                        Its files are deleted from
                                        <code>modules/</code>. This can't be
                                        undone, but you can upload the module
                                        again later.
                                    </DialogDescription>
                                </DialogHeader>

                                <Label class="flex items-start gap-3">
                                    <Checkbox
                                        name="delete_data"
                                        :default-value="true"
                                    />
                                    <span class="grid gap-1">
                                        <span>Also delete its data</span>
                                        <span
                                            class="text-sm font-normal text-muted-foreground"
                                        >
                                            Removes the tables it created, for
                                            every site.
                                        </span>
                                    </span>
                                </Label>

                                <DialogFooter class="gap-2">
                                    <DialogClose as-child>
                                        <Button variant="secondary">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        :disabled="processing"
                                    >
                                        Uninstall
                                    </Button>
                                </DialogFooter>
                            </Form>
                        </DialogContent>
                    </Dialog>
                </CardFooter>
            </Card>
        </div>
    </div>
</template>

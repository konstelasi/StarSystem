<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import ModelTransferController from '@/actions/App/Http/Controllers/Admin/ModelTransferController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const open = defineModel<boolean>('open', { default: false });

const slug = ref('');
const label = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        slug.value = '';
        label.value = '';
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <Form
                v-bind="ModelTransferController.import.form()"
                class="grid gap-4"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Import a model</DialogTitle>
                    <DialogDescription>
                        A schema exported from this or another site. Leave the
                        name and address name blank to use the ones in the file.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="import-model-file">Schema file</Label>
                    <Input
                        id="import-model-file"
                        name="file"
                        type="file"
                        accept=".json,application/json"
                        required
                    />
                    <InputError :message="errors.file" />
                </div>

                <div class="grid gap-2">
                    <Label for="import-model-label">Name (optional)</Label>
                    <Input
                        id="import-model-label"
                        name="label"
                        v-model="label"
                    />
                    <InputError :message="errors.label" />
                </div>

                <div class="grid gap-2">
                    <Label for="import-model-slug"
                        >Address name (optional)</Label
                    >
                    <Input id="import-model-slug" name="slug" v-model="slug" />
                    <InputError :message="errors.slug" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Import
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>

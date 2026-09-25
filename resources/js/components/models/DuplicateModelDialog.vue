<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { modelSlugProblem, uniqueKey } from '@/lib/modelSchema';

const props = defineProps<{
    model: { id: number; slug: string; label: string };
    existingSlugs: string[];
}>();

const open = ref(false);
const label = ref('');
const slug = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        label.value = `Copy of ${props.model.label}`;
        slug.value = uniqueKey(`${props.model.slug}_copy`, props.existingSlugs);
    }
});

const slugProblem = computed(() =>
    slug.value === ''
        ? null
        : modelSlugProblem(slug.value, props.existingSlugs),
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="ghost" size="sm">
                <Copy />
                Duplicate
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="ModelTransferController.duplicate.form(model.id)"
                class="grid gap-4"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Duplicate {{ model.label }}</DialogTitle>
                    <DialogDescription>
                        Copies its fields into a new model. Entries stay with
                        the original.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="duplicate-model-label">Name</Label>
                    <Input
                        id="duplicate-model-label"
                        name="label"
                        v-model="label"
                        required
                        autofocus
                    />
                    <InputError :message="errors.label" />
                </div>

                <div class="grid gap-2">
                    <Label for="duplicate-model-slug">Address name</Label>
                    <Input
                        id="duplicate-model-slug"
                        name="slug"
                        v-model="slug"
                        required
                    />
                    <InputError :message="slugProblem ?? errors.slug" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="processing || !!slugProblem"
                    >
                        <Spinner v-if="processing" />
                        Duplicate
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>

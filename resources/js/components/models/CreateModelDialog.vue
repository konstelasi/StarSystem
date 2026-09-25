<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ModelController from '@/actions/App/Http/Controllers/Admin/ModelController';
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
import { modelSlugFromLabel, modelSlugProblem } from '@/lib/modelSchema';

const props = defineProps<{
    existingSlugs: string[];
}>();

const open = defineModel<boolean>('open', { default: false });

const label = ref('');
const slug = ref('');
const slugTouched = ref(false);
const group = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        label.value = '';
        slug.value = '';
        slugTouched.value = false;
        group.value = '';
    }
});

function onLabelInput(value: string | number) {
    label.value = String(value);

    if (!slugTouched.value) {
        slug.value = modelSlugFromLabel(label.value);
    }
}

function onSlugInput(value: string | number) {
    slugTouched.value = true;
    slug.value = String(value);
}

const slugProblem = computed(() =>
    slug.value === ''
        ? null
        : modelSlugProblem(slug.value, props.existingSlugs),
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <Form
                v-bind="ModelController.store.form()"
                class="grid gap-4"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>New model</DialogTitle>
                    <DialogDescription>
                        A model defines the fields an entry has, like Article or
                        Page.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="create-model-label">Name</Label>
                    <Input
                        id="create-model-label"
                        name="label"
                        :model-value="label"
                        autofocus
                        required
                        @update:model-value="onLabelInput"
                    />
                    <InputError :message="errors.label" />
                </div>

                <div class="grid gap-2">
                    <Label for="create-model-slug">Address name</Label>
                    <Input
                        id="create-model-slug"
                        name="slug"
                        :model-value="slug"
                        required
                        @update:model-value="onSlugInput"
                    />
                    <p class="text-xs text-muted-foreground">
                        Used in the content API and StarDust. Lowercase letters,
                        numbers, underscores and hyphens.
                    </p>
                    <InputError :message="slugProblem ?? errors.slug" />
                </div>

                <div class="grid gap-2">
                    <Label for="create-model-group">Menu group</Label>
                    <Input
                        id="create-model-group"
                        name="group"
                        v-model="group"
                        placeholder="For example Content"
                    />
                    <InputError :message="errors.group" />
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
                        Create
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>

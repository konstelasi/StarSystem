<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store, update } from '@/routes/admin/roles';

type Area = {
    name: string;
    abilities: { key: string; label: string; held: boolean }[];
};

const props = defineProps<{
    /** Null while making a new role. */
    role: { id: number; name: string; system: boolean } | null;
    selected: string[];
    readonly: boolean;
    areas: Area[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles', href: index() }],
    },
});

const form = useForm({
    name: props.role?.name ?? '',
    abilities: [...props.selected],
});

const has = (key: string) => form.abilities.includes(key);

function toggle(key: string, on: boolean) {
    form.abilities = on
        ? [...form.abilities, key]
        : form.abilities.filter((ability) => ability !== key);
}

function submit() {
    if (props.role) {
        form.put(update(props.role.id).url);
    } else {
        form.post(store().url);
    }
}

const reason = () =>
    props.role?.system
        ? 'A built-in role is fixed. Copy it from the roles list to change a version of your own.'
        : 'You can look at this role, but not change it. It is your own role, or it holds abilities you do not have.';
</script>

<template>
    <Head :title="role ? role.name : 'New role'" />

    <PageBody>
        <PageHeader
            :title="role ? role.name : 'New role'"
            :description="
                readonly
                    ? reason()
                    : 'Tick what people with this role may do. You can only give abilities you have yourself.'
            "
        />

        <form class="grid gap-6" @submit.prevent="submit">
            <div class="grid max-w-md gap-1.5">
                <Label for="role-name">Name</Label>
                <Input
                    id="role-name"
                    v-model="form.name"
                    maxlength="64"
                    :disabled="readonly"
                    required
                />
                <InputError :message="form.errors.name" />
            </div>

            <fieldset
                v-for="area in areas"
                :key="area.name"
                class="grid gap-3 rounded-lg border p-4"
            >
                <legend class="px-1 text-sm font-medium">
                    {{ area.name }}
                </legend>
                <Label
                    v-for="ability in area.abilities"
                    :key="ability.key"
                    class="flex items-center gap-3 font-normal"
                >
                    <Checkbox
                        :model-value="has(ability.key)"
                        :disabled="readonly || !ability.held"
                        @update:model-value="
                            (on) => toggle(ability.key, on === true)
                        "
                    />
                    {{ ability.label }}
                </Label>
            </fieldset>
            <InputError :message="form.errors.abilities" />

            <div class="flex gap-2">
                <Button
                    v-if="!readonly"
                    type="submit"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Save v-else />
                    Save
                </Button>
                <Button variant="secondary" as-child>
                    <Link :href="index()">{{
                        readonly ? 'Back' : 'Cancel'
                    }}</Link>
                </Button>
            </div>
        </form>
    </PageBody>
</template>

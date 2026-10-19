<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import AbilityChoice from '@/components/roles/AbilityChoice.vue';
import ModelGrants from '@/components/roles/ModelGrants.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store, update } from '@/routes/admin/roles';
import type { ModelAbilityRow, ModelChoice, RoleAbility } from '@/types/roles';

type Area = {
    name: string;
    abilities: RoleAbility[];
};

const props = defineProps<{
    /** Null while making a new role. */
    role: { id: number; name: string; system: boolean } | null;
    selected: string[];
    readonly: boolean;
    areas: Area[];
    /** The models the role can be given something for, with what this person may give for each. */
    models: ModelChoice[];
    modelRows: ModelAbilityRow[];
    /** What the role holds for particular models now, by model id. */
    modelSelected: Record<number, string[]>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles', href: index() }],
    },
});

const form = useForm({
    name: props.role?.name ?? '',
    abilities: [...props.selected],
    models: { ...props.modelSelected } as Record<number, string[]>,
});

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
                <AbilityChoice
                    v-for="ability in area.abilities"
                    :key="ability.key"
                    :ability="ability"
                    :selected="form.abilities"
                    :disabled="readonly"
                    @update:selected="(next) => (form.abilities = next)"
                />
            </fieldset>
            <InputError :message="form.errors.abilities" />

            <fieldset class="grid gap-3 rounded-lg border p-4">
                <legend class="px-1 text-sm font-medium">
                    Specific models
                </legend>
                <p class="max-w-prose text-sm text-muted-foreground">
                    Give this role more on some models than on the rest. What
                    you tick here is added to the areas above, and never takes
                    anything away from them.
                </p>
                <ModelGrants
                    v-if="models.length > 0"
                    v-model="form.models"
                    :models="models"
                    :rows="modelRows"
                    :disabled="readonly"
                />
                <p v-else class="text-sm text-muted-foreground">
                    This site has no models yet.
                </p>
            </fieldset>
            <InputError :message="form.errors.models" />

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

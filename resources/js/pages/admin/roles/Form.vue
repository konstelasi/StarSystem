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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { index, store, update } from '@/routes/admin/roles';

type Ability = {
    key: string;
    label: string;
    held: boolean;
    /**
     * Set when the ability can also be given for only the entries a person
     * created. The row then offers None, Own and All in place of a box.
     */
    own?: { key: string; label: string; held: boolean };
};

type Area = {
    name: string;
    abilities: Ability[];
};

type Reach = 'none' | 'own' | 'all';

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

/** How far a role holds an ability that has an own form: not at all, for entries a person made, or for every entry. */
function reach(ability: Ability): Reach {
    if (has(ability.key)) {
        return 'all';
    }

    return ability.own && has(ability.own.key) ? 'own' : 'none';
}

function setReach(ability: Ability, level: unknown) {
    if (!ability.own) {
        return;
    }

    const others = form.abilities.filter(
        (key) => key !== ability.key && key !== ability.own?.key,
    );

    form.abilities =
        level === 'all'
            ? [...others, ability.key]
            : level === 'own'
              ? [...others, ability.own.key]
              : others;
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
                <template v-for="ability in area.abilities" :key="ability.key">
                    <div
                        v-if="ability.own"
                        class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2"
                    >
                        <span class="text-sm">{{ ability.label }}</span>
                        <RadioGroup
                            :model-value="reach(ability)"
                            :disabled="readonly"
                            class="flex flex-wrap gap-x-5 gap-y-1"
                            :aria-label="ability.label"
                            @update:model-value="
                                (level) => setReach(ability, level)
                            "
                        >
                            <Label class="flex items-center gap-2 font-normal">
                                <RadioGroupItem value="none" />
                                None
                            </Label>
                            <Label class="flex items-center gap-2 font-normal">
                                <RadioGroupItem
                                    value="own"
                                    :disabled="!ability.own.held"
                                />
                                Their own entries
                            </Label>
                            <Label class="flex items-center gap-2 font-normal">
                                <RadioGroupItem
                                    value="all"
                                    :disabled="!ability.held"
                                />
                                Any entry
                            </Label>
                        </RadioGroup>
                    </div>
                    <Label v-else class="flex items-center gap-3 font-normal">
                        <Checkbox
                            :model-value="has(ability.key)"
                            :disabled="readonly || !ability.held"
                            @update:model-value="
                                (on) => toggle(ability.key, on === true)
                            "
                        />
                        {{ ability.label }}
                    </Label>
                </template>
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

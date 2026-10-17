<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { twoFactor } from '@/routes/admin/roles';

const props = defineProps<{
    roles: { id: number; name: string }[];
    /** The ids of the roles held to two-factor now. */
    selected: number[];
    /** Whether this account may change the list. */
    editable: boolean;
    /** Whether this account has two-factor on itself. */
    yours: boolean;
}>();

const form = useForm({ roles: [...props.selected] });

const changed = computed(
    () =>
        form.roles.length !== props.selected.length ||
        form.roles.some((id) => !props.selected.includes(id)),
);

function toggle(id: number, on: boolean) {
    form.roles = on
        ? [...new Set([...form.roles, id])]
        : form.roles.filter((role) => role !== id);
}

function save() {
    form.put(twoFactor().url, {
        preserveScroll: true,
        onSuccess: () => form.defaults({ roles: [...form.roles] }),
    });
}
</script>

<template>
    <section class="grid max-w-2xl gap-3" data-test="two-factor-requirement">
        <div class="space-y-1">
            <h2 class="text-base">Two-factor sign-in</h2>
            <p class="text-sm text-muted-foreground">
                Someone in a checked role has to turn on two-factor sign-in
                before they can use the site, and is sent to their security
                settings until they do. The install owner counts as an
                Administrator.
            </p>
        </div>

        <form class="grid gap-3" @submit.prevent="save">
            <div class="flex flex-wrap gap-x-5 gap-y-2">
                <Label
                    v-for="role in roles"
                    :key="role.id"
                    class="flex items-center gap-2 font-normal"
                >
                    <Checkbox
                        :model-value="form.roles.includes(role.id)"
                        :disabled="!editable"
                        @update:model-value="
                            (on) => toggle(role.id, on === true)
                        "
                    />
                    {{ role.name }}
                </Label>
            </div>

            <InputError :message="form.errors.roles" />
            <p v-if="editable && !yours" class="text-sm text-muted-foreground">
                You haven't turned on two-factor yourself. Do that first if you
                want to include your own role.
            </p>

            <div v-if="editable">
                <Button
                    type="submit"
                    :disabled="form.processing || !changed"
                    variant="secondary"
                >
                    <Spinner v-if="form.processing" />
                    Save
                </Button>
            </div>
        </form>
    </section>
</template>

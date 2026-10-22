<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { PlusCircle, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    addressFields,
    entryAddress,
    newRoute,
    publishedFields,
    titleFields,
    withModel,
} from '@/lib/siteRoutes';
import type { SiteField, SiteModel, SiteRoute } from '@/lib/siteRoutes';
import { index, update } from '@/routes/admin/site';

type ThemeRow = {
    name: string;
    title: string;
    version: string;
    description: string;
    author: string;
};

const props = defineProps<{
    themes: ThemeRow[];
    /** Folders in the themes folder that are not usable, with why. */
    broken: { folder: string; message: string }[];
    /** The theme the site chose, or null for the default one. */
    theme: string | null;
    defaultTheme: string;
    routes: SiteRoute[];
    models: SiteModel[];
    editable: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Site', href: index() }],
    },
});

const form = useForm<{ theme: string | null; routes: SiteRoute[] }>({
    theme: props.theme,
    routes: props.routes.map((route) => ({ ...route })),
});

/** The theme shown as chosen: the default one while the site has none. */
const chosen = computed(() => form.theme ?? props.defaultTheme);

const modelOf = (id: number | null): SiteModel | undefined =>
    props.models.find((model) => model.id === id);

/** Models that no row uses yet, plus the one this row already has. */
const modelsFor = (route: SiteRoute) =>
    props.models.filter(
        (model) =>
            model.id === route.model_id ||
            !form.routes.some((other) => other.model_id === model.id),
    );

const unused = computed(
    () =>
        props.models.filter(
            (model) => !form.routes.some((row) => row.model_id === model.id),
        ).length,
);

function chooseTheme(name: unknown) {
    if (typeof name === 'string') {
        form.theme = name === props.defaultTheme ? null : name;
    }
}

function addRow() {
    const model = props.models.find(
        (candidate) =>
            !form.routes.some((row) => row.model_id === candidate.id),
    );

    if (model !== undefined) {
        form.routes.push(
            newRoute(
                model,
                form.routes.map((row) => row.path),
            ),
        );
    }
}

function chooseModel(position: number, value: unknown) {
    const model = modelOf(Number(value));
    const row = form.routes[position];

    if (model !== undefined && row !== undefined) {
        form.routes[position] = withModel(row, model, modelOf(row.model_id));
    }
}

function mark(field: SiteField): string {
    return field.indexed ? field.label : `${field.label} (still being indexed)`;
}

/** Radix selects can't hold an empty value, so "none" stands for it. */
const NONE = 'none';

function save() {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Site" />

    <PageBody>
        <PageHeader
            title="Site"
            description="The theme visitors see, and which models are public at which address."
        />

        <form class="grid max-w-3xl gap-8" @submit.prevent="save">
            <section class="grid gap-3" data-test="site-theme">
                <h2 class="text-base">Theme</h2>

                <RadioGroup
                    v-if="themes.length > 0"
                    :model-value="chosen"
                    :disabled="!editable"
                    class="gap-2"
                    aria-label="Theme"
                    @update:model-value="chooseTheme"
                >
                    <Label
                        v-for="theme in themes"
                        :key="theme.name"
                        class="flex items-start gap-3 rounded-md border p-3 font-normal"
                    >
                        <RadioGroupItem :value="theme.name" class="mt-0.5" />
                        <span class="grid gap-0.5">
                            <span>
                                <span class="font-medium">{{
                                    theme.title
                                }}</span>
                                <span class="ml-2 text-xs text-muted-foreground"
                                    >{{ theme.version
                                    }}<template v-if="theme.author">
                                        by {{ theme.author }}</template
                                    ></span
                                >
                            </span>
                            <span
                                v-if="theme.description"
                                class="text-sm text-muted-foreground"
                                >{{ theme.description }}</span
                            >
                        </span>
                    </Label>
                </RadioGroup>
                <p v-else class="text-sm text-muted-foreground">
                    No theme is installed. Copy a theme folder into the themes
                    folder on the server.
                </p>
                <InputError :message="form.errors.theme" />

                <ul
                    v-if="broken.length > 0"
                    class="grid gap-1 text-sm text-muted-foreground"
                >
                    <li v-for="item in broken" :key="item.folder">
                        <code>{{ item.folder }}</code> isn't used.
                        {{ item.message }}
                    </li>
                </ul>
            </section>

            <section class="grid gap-4" data-test="site-addresses">
                <div class="space-y-1">
                    <h2 class="text-base">Addresses</h2>
                    <p class="text-sm text-muted-foreground">
                        A model that has a row here is public. Its entries
                        appear under its address, so with the address
                        <code>blog</code> an entry whose address field says
                        <code>hello</code> is at <code>/blog/hello</code>. Give
                        one model no address and its entries sit at the root,
                        such as <code>/about</code>. A published model needs a
                        required, filterable text field for the address.
                    </p>
                </div>

                <p
                    v-if="models.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    There are no models yet. Build one under Models first.
                </p>

                <fieldset
                    v-for="(route, position) in form.routes"
                    :key="position"
                    class="grid gap-4 rounded-lg border p-4"
                    :disabled="!editable"
                >
                    <legend class="px-1 text-sm font-medium">
                        {{ modelOf(route.model_id)?.label ?? 'Model' }}
                    </legend>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label :for="`model-${position}`">Model</Label>
                            <Select
                                :model-value="
                                    route.model_id === null
                                        ? undefined
                                        : String(route.model_id)
                                "
                                @update:model-value="
                                    (value) => chooseModel(position, value)
                                "
                            >
                                <SelectTrigger
                                    :id="`model-${position}`"
                                    class="w-full"
                                >
                                    <SelectValue
                                        placeholder="Choose a model…"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="model in modelsFor(route)"
                                        :key="model.id"
                                        :value="String(model.id)"
                                    >
                                        {{ model.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-1.5">
                            <Label :for="`path-${position}`">Address</Label>
                            <div class="flex items-center gap-1">
                                <span class="text-muted-foreground">/</span>
                                <Input
                                    :id="`path-${position}`"
                                    v-model="route.path"
                                    autocomplete="off"
                                    spellcheck="false"
                                    placeholder="Leave empty for the root"
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Entries appear at
                                <code>{{ entryAddress(route) }}</code>
                            </p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label :for="`slug-${position}`"
                                >Field that holds the address</Label
                            >
                            <Select
                                :model-value="route.slug_field ?? undefined"
                                @update:model-value="
                                    (value) =>
                                        (route.slug_field = value as string)
                                "
                            >
                                <SelectTrigger
                                    :id="`slug-${position}`"
                                    class="w-full"
                                >
                                    <SelectValue
                                        placeholder="Choose a field…"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="field in addressFields(
                                            modelOf(route.model_id),
                                        )"
                                        :key="field.uuid"
                                        :value="field.uuid"
                                    >
                                        {{ mark(field) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="
                                    addressFields(modelOf(route.model_id))
                                        .length === 0
                                "
                                class="text-xs text-muted-foreground"
                            >
                                This model has no required, filterable text
                                field. Add one in the builder.
                            </p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label :for="`title-${position}`"
                                >Field that names an entry</Label
                            >
                            <Select
                                :model-value="route.title_field ?? undefined"
                                @update:model-value="
                                    (value) =>
                                        (route.title_field = value as string)
                                "
                            >
                                <SelectTrigger
                                    :id="`title-${position}`"
                                    class="w-full"
                                >
                                    <SelectValue
                                        placeholder="Choose a field…"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="field in titleFields(
                                            modelOf(route.model_id),
                                        )"
                                        :key="field.uuid"
                                        :value="field.uuid"
                                    >
                                        {{ field.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-1.5">
                            <Label :for="`published-${position}`"
                                >Published switch</Label
                            >
                            <Select
                                :model-value="route.published_field ?? NONE"
                                @update:model-value="
                                    (value) =>
                                        (route.published_field =
                                            value === NONE
                                                ? null
                                                : (value as string))
                                "
                            >
                                <SelectTrigger
                                    :id="`published-${position}`"
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="NONE">
                                        None, every entry is public
                                    </SelectItem>
                                    <SelectItem
                                        v-for="field in publishedFields(
                                            modelOf(route.model_id),
                                        )"
                                        :key="field.uuid"
                                        :value="field.uuid"
                                    >
                                        {{ mark(field) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">
                                A checkbox field. An entry is public only while
                                it is ticked.
                            </p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label :for="`label-${position}`"
                                >Name in the menu</Label
                            >
                            <Input
                                :id="`label-${position}`"
                                v-model="route.label"
                                autocomplete="off"
                            />
                        </div>
                    </div>

                    <Label
                        v-if="route.path !== ''"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="route.list"
                            @update:model-value="
                                (on) => (route.list = on === true)
                            "
                        />
                        Show a list of its entries at /{{ route.path }}
                    </Label>

                    <div v-else class="grid gap-1.5">
                        <Label :for="`home-${position}`">Home page entry</Label>
                        <Input
                            :id="`home-${position}`"
                            :model-value="route.home_slug ?? ''"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="The address of the entry shown at /"
                            @update:model-value="
                                (value) =>
                                    (route.home_slug =
                                        String(value) === ''
                                            ? null
                                            : String(value))
                            "
                        />
                    </div>

                    <InputError :message="form.errors[`routes.${position}`]" />

                    <div v-if="editable">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="form.routes.splice(position, 1)"
                        >
                            <Trash2 />
                            Stop publishing this model
                        </Button>
                    </div>
                </fieldset>

                <div v-if="editable">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="unused === 0"
                        @click="addRow"
                    >
                        <PlusCircle />
                        Publish a model
                    </Button>
                </div>
            </section>

            <div v-if="editable">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Save
                </Button>
            </div>
        </form>
    </PageBody>
</template>

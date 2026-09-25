<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Card, CardContent } from '@/components/ui/card';

const steps = [
    { page: 'install/Requirements', title: 'Check' },
    { page: 'install/Database', title: 'Database' },
    { page: 'install/Setup', title: 'Set up' },
    { page: 'install/Admin', title: 'Your account' },
    { page: 'install/Done', title: 'Done' },
];

const page = usePage();

const current = computed(() =>
    steps.findIndex((step) => step.page === page.component),
);
</script>

<template>
    <div class="flex min-h-svh flex-col items-center bg-background p-6 md:p-10">
        <div class="flex w-full max-w-xl flex-col gap-8">
            <div class="flex flex-col items-center gap-3">
                <AppLogoIcon
                    class="size-9 fill-current text-[var(--foreground)] dark:text-white"
                />
                <p class="text-sm font-medium text-muted-foreground">
                    Install StarSystem
                </p>
            </div>

            <ol class="grid grid-cols-5 gap-2 text-center text-xs">
                <li
                    v-for="(step, index) in steps"
                    :key="step.page"
                    class="flex flex-col items-center gap-1.5"
                    :class="
                        index === current
                            ? 'font-medium text-foreground'
                            : 'text-muted-foreground'
                    "
                    :aria-current="index === current ? 'step' : undefined"
                >
                    <span
                        class="flex size-6 items-center justify-center rounded-full border text-[0.7rem] tabular-nums"
                        :class="{
                            'border-foreground bg-foreground text-background':
                                index <= current,
                        }"
                    >
                        <Check v-if="index < current" class="size-3.5" />
                        <template v-else>{{ index + 1 }}</template>
                    </span>
                    {{ step.title }}
                </li>
            </ol>

            <Card>
                <CardContent>
                    <slot />
                </CardContent>
            </Card>
        </div>
    </div>
</template>

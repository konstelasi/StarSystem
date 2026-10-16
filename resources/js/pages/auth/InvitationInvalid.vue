<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'This invitation does not work',
        description:
            'It may have expired, been used already, or been withdrawn. Ask the person who invited you to send a new one.',
    },
});
</script>

<template>
    <Head title="Invitation" />

    <Button as-child variant="secondary" class="w-full">
        <Link :href="login()">Go to sign in</Link>
    </Button>
</template>

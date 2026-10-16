<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
</script>

<template>
    <Head title="No access" />

    <PageBody width="text">
        <PageHeader
            title="Your role can't open this"
            description="Whoever manages this site decides what each role may do. Ask them if you need access to this page."
        />

        <div>
            <Button as-child>
                <Link :href="dashboard()">Back to the dashboard</Link>
            </Button>
        </div>
    </PageBody>
</template>

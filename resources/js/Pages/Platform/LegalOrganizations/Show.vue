<template>
    <PlatformLayout>
        <h1 class="text-h5 mb-2">{{ legal_organization.name }}</h1>
        <p class="text-body-2 mb-6">Status: {{ legal_organization.status }}</p>

        <h2 class="text-h6 mb-2">Tenants</h2>
        <ul class="mb-6">
            <li v-for="t in legal_organization.tenants" :key="t.id">
                {{ t.name }} ({{ t.slug }}) — {{ t.status }}
            </li>
        </ul>

        <h2 class="text-h6 mb-2">Business Owners</h2>
        <ul>
            <li v-for="o in legal_organization.business_owners" :key="o.id">
                User {{ o.user_id }} — {{ o.status }}
            </li>
        </ul>

        <v-btn class="mt-6" variant="text" :href="route('platform.legal-organizations.index')">Back</v-btn>
    </PlatformLayout>
</template>

<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue';
import { route } from 'ziggy-js';

defineProps({
    legal_organization: { type: Object, required: true },
});
</script>

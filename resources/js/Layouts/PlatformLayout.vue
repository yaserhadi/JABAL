<template>
    <v-app>
        <v-app-bar color="primary" density="comfortable">
            <v-app-bar-title>Platform Management</v-app-bar-title>
            <v-spacer />
            <v-btn variant="text" :href="route('platform.tenants.index')">Tenants</v-btn>
            <v-btn variant="text" :href="route('platform.catalog.index')">Catalog</v-btn>
            <v-btn variant="text" :href="route('platform.legal-organizations.index')">Legal Orgs</v-btn>
            <v-btn variant="text" :href="route('platform.settings.index')">Settings</v-btn>
            <v-btn variant="text" :href="route('platform.audit.index')">Audit</v-btn>
            <v-btn variant="outlined" class="ms-2" @click="logout">Sign out</v-btn>
        </v-app-bar>
        <v-main>
            <v-container class="py-6">
                <slot />
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const logout = () => {
    router.post(route('platform.logout'));
};
</script>

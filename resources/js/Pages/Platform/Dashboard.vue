<template>
    <PlatformLayout>
        <v-row>
            <v-col cols="12" md="8">
                <h1 class="text-h4 mb-2">Platform dashboard</h1>
                <p class="text-body-1 text-medium-emphasis mb-6">
                    Operator home for Platform Management. Tenant registry and other tools are available from the navigation.
                </p>
                <v-btn color="primary" :href="route('platform.tenants.index')">Open tenant registry</v-btn>
            </v-col>
        </v-row>
    </PlatformLayout>
</template>

<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue';
import { route } from 'ziggy-js';
</script>

<template>
    <PublicOnboardingShell :brand="appName" subtitle="Tenant user — find your organization">
        <p class="text-body-2 text-medium-emphasis text-center mb-4">
            Sign in to your organization.
        </p>
        <v-form @submit.prevent="submit">
            <v-text-field
                v-model="form.slug"
                label="Tenant handle"
                :error-messages="form.errors.slug"
                prepend-inner-icon="mdi-office-building"
                hint="Preferred: go directly to your tenant login"
                persistent-hint
            />
            <v-text-field
                v-model="form.email"
                label="Email"
                type="email"
                :error-messages="form.errors.email"
                prepend-inner-icon="mdi-email"
                hint="Or discover your organization by email"
                persistent-hint
                class="mt-2"
            />
            <v-btn type="submit" color="primary" block :loading="form.processing" class="mt-4">
                Continue
            </v-btn>
        </v-form>

        <template #actions>
            <v-btn text :href="route('register')" variant="text"> Don't have an account? Register </v-btn>
        </template>
    </PublicOnboardingShell>
</template>

<script setup>
import PublicOnboardingShell from '@/Layouts/PublicOnboardingShell.vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps({
    entryPlane: {
        type: String,
        default: 'tenant_user',
    },
});

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';

const form = useForm({
    slug: '',
    email: '',
});

const submit = () => {
    form.post(route('login'));
};
</script>

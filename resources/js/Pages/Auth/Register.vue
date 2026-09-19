<template>
    <PublicOnboardingShell :brand="appName" subtitle="Create your account">
        <v-form @submit.prevent="submit">
            <v-text-field
                v-model="form.name"
                label="Name"
                :error-messages="form.errors.name"
                required
                prepend-inner-icon="mdi-account"
                autocomplete="name"
            />
            <v-text-field
                v-model="form.email"
                label="Email"
                type="email"
                :error-messages="form.errors.email"
                required
                prepend-inner-icon="mdi-email"
                autocomplete="email"
            />
            <PasswordPairFields
                v-model:password="form.password"
                v-model:password-confirmation="form.password_confirmation"
                :errors="passwordErrors"
                :match-hint="showMatchHint"
            />
            <v-btn type="submit" color="primary" block :loading="form.processing" class="mt-2">
                Register
            </v-btn>
        </v-form>

        <template #actions>
            <v-btn text :href="route('login')" variant="text"> Already have an account? Sign in </v-btn>
        </template>
    </PublicOnboardingShell>
</template>

<script setup>
import PasswordPairFields from '@/Components/Onboarding/PasswordPairFields.vue';
import PublicOnboardingShell from '@/Layouts/PublicOnboardingShell.vue';
import { passwordFieldErrors, passwordsMatch } from '@/support/registerFormFeedback';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const passwordErrors = computed(() =>
    passwordFieldErrors(form.errors, form.password, form.password_confirmation)
);

const showMatchHint = computed(() => passwordsMatch(form.password, form.password_confirmation));

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

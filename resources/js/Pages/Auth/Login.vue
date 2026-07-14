<template>
    <v-app>
        <v-main>
            <v-container fluid class="fill-height">
                <v-row align="center" justify="center">
                    <v-col cols="12" sm="8" md="4">
                        <v-card>
                            <v-card-title class="text-h5 text-center pa-4">
                                {{ appName }}
                            </v-card-title>
                            <v-card-subtitle class="text-center pb-2">
                                Find your workspace
                            </v-card-subtitle>
                            <v-card-text>
                                <v-form @submit.prevent="submit">
                                    <v-text-field
                                        v-model="form.slug"
                                        label="Workspace slug"
                                        :error-messages="form.errors.slug"
                                        prepend-inner-icon="mdi-office-building"
                                        hint="Preferred: go directly to your workspace login"
                                        persistent-hint
                                    />
                                    <v-text-field
                                        v-model="form.email"
                                        label="Email"
                                        type="email"
                                        :error-messages="form.errors.email"
                                        prepend-inner-icon="mdi-email"
                                        hint="Or discover your workspace by email"
                                        persistent-hint
                                        class="mt-2"
                                    />
                                    <v-btn
                                        type="submit"
                                        color="primary"
                                        block
                                        :loading="form.processing"
                                        class="mt-4"
                                    >
                                        Continue
                                    </v-btn>
                                </v-form>
                            </v-card-text>
                            <v-card-actions class="justify-center">
                                <v-btn
                                    text
                                    :href="route('register')"
                                    variant="text"
                                >
                                    Don't have an account? Register
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-col>
                </v-row>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';

const form = useForm({
    slug: '',
    email: '',
});

const submit = () => {
    form.post(route('login'));
};
</script>

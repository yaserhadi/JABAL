<template>
    <v-app>
        <v-main>
            <v-container fluid class="fill-height">
                <v-row align="center" justify="center">
                    <v-col cols="12" sm="8" md="4">
                        <v-card>
                            <v-card-title class="text-h5 text-center pa-4">
                                Platform Management
                            </v-card-title>
                            <v-card-subtitle class="text-center pb-2">
                                Platform operator sign in
                            </v-card-subtitle>
                            <v-card-text>
                                <p class="text-body-2 text-medium-emphasis text-center mb-4">
                                    This plane is for platform operators who manage tenants.
                                    Workspace members should use workspace sign-in instead.
                                </p>
                                <v-form @submit.prevent="submit">
                                    <v-text-field
                                        v-model="form.email"
                                        label="Email"
                                        type="email"
                                        :error-messages="form.errors.email"
                                        required
                                        prepend-inner-icon="mdi-shield-account"
                                    />
                                    <PasswordField
                                        v-model="form.password"
                                        label="Password"
                                        :error-messages="form.errors.password"
                                        required
                                        prepend-inner-icon="mdi-lock"
                                    />
                                    <v-btn type="submit" color="primary" block :loading="form.processing">
                                        Sign in
                                    </v-btn>
                                </v-form>
                            </v-card-text>
                            <v-card-actions class="justify-center flex-column ga-1 pb-4">
                                <v-btn
                                    v-if="alternatePlane?.url"
                                    variant="text"
                                    :href="alternatePlane.url"
                                >
                                    {{ alternatePlane.label }}
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
import PasswordField from '@/Components/PasswordField.vue';

defineProps({
    entryPlane: {
        type: String,
        default: 'platform_operator',
    },
    alternatePlane: {
        type: Object,
        default: null,
    },
});

const form = useForm({
    email: '',
    password: '',
});

const submit = () => {
    form.post(route('platform.login.attempt'));
};
</script>

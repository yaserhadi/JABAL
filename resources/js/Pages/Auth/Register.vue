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
                                Create your account
                            </v-card-subtitle>
                            <v-card-text>
                                <v-form @submit.prevent="submit">
                                    <v-text-field
                                        v-model="form.name"
                                        label="Name"
                                        :error-messages="form.errors.name"
                                        required
                                        prepend-inner-icon="mdi-account"
                                    />
                                    <v-text-field
                                        v-model="form.email"
                                        label="Email"
                                        type="email"
                                        :error-messages="form.errors.email"
                                        required
                                        prepend-inner-icon="mdi-email"
                                    />
                                    <v-text-field
                                        v-model="form.password"
                                        label="Password"
                                        type="password"
                                        :error-messages="form.errors.password"
                                        required
                                        prepend-inner-icon="mdi-lock"
                                    />
                                    <v-text-field
                                        v-model="form.password_confirmation"
                                        label="Confirm Password"
                                        type="password"
                                        :error-messages="form.errors.password_confirmation"
                                        required
                                        prepend-inner-icon="mdi-lock-check"
                                    />
                                    <v-btn
                                        type="submit"
                                        color="primary"
                                        block
                                        :loading="form.processing"
                                        class="mt-2"
                                    >
                                        Register
                                    </v-btn>
                                </v-form>
                            </v-card-text>
                            <v-card-actions class="justify-center">
                                <v-btn
                                    text
                                    :href="route('login')"
                                    variant="text"
                                >
                                    Already have an account? Sign in
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

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

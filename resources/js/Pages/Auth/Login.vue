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
                                Sign in to your account
                            </v-card-subtitle>
                            <v-card-text>
                                <v-form @submit.prevent="submit">
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
                                    <v-checkbox
                                        v-model="form.remember"
                                        label="Remember me"
                                    />
                                    <v-btn
                                        type="submit"
                                        color="primary"
                                        block
                                        :loading="form.processing"
                                        class="mt-2"
                                    >
                                        Sign In
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

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

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
                                Operator sign in
                            </v-card-subtitle>
                            <v-card-text>
                                <v-form @submit.prevent="submit">
                                    <v-text-field
                                        v-model="form.email"
                                        label="Email"
                                        type="email"
                                        :error-messages="form.errors.email"
                                        required
                                        prepend-inner-icon="mdi-shield-account"
                                    />
                                    <v-text-field
                                        v-model="form.password"
                                        label="Password"
                                        type="password"
                                        :error-messages="form.errors.password"
                                        required
                                        prepend-inner-icon="mdi-lock"
                                    />
                                    <v-btn type="submit" color="primary" block :loading="form.processing">
                                        Sign in
                                    </v-btn>
                                </v-form>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
});

const submit = () => {
    form.post(route('platform.login'));
};
</script>

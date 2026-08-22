<template>
    <v-app>
        <v-main>
            <v-container class="py-8" style="max-width: 480px">
                <v-card>
                    <v-card-title>Confirm your password</v-card-title>
                    <v-card-text>
                        <p class="mb-4">
                            Linking Enterprise SSO requires a fresh password confirmation, then MFA.
                            Your current session is not treated as high-assurance proof.
                        </p>
                        <v-form @submit.prevent="submit">
                            <v-text-field
                                v-model="form.password"
                                label="Password"
                                type="password"
                                :error-messages="form.errors.password"
                                required
                                autocomplete="current-password"
                            />
                            <v-btn type="submit" color="primary" block :loading="form.processing">
                                Continue
                            </v-btn>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { tenantRouteParams } from '@/support/tenantEntry';

const props = defineProps({
    tenant: Object,
});

const form = useForm({ password: '' });

const submit = () => {
    form.post(route('identity.sso.enrollment.step-up.password', tenantRouteParams(props.tenant)));
};
</script>

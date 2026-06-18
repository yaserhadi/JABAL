<template>
    <v-app>
        <v-main>
            <v-container class="py-8" style="max-width: 480px">
                <v-card>
                    <v-card-title>Set up two-factor authentication</v-card-title>
                    <v-card-text>
                        <p class="mb-4">Scan this secret in your authenticator app, then enter the verification code.</p>
                        <v-alert type="info" variant="tonal" class="mb-4 text-break">
                            {{ secret }}
                        </v-alert>
                        <v-form @submit.prevent="submit">
                            <v-text-field
                                v-model="form.code"
                                label="Verification code"
                                :error-messages="form.errors.code"
                                required
                            />
                            <v-btn type="submit" color="primary" block :loading="form.processing">
                                Confirm enrollment
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

const props = defineProps({
    tenant: Object,
    secret: String,
});

const form = useForm({ code: '' });

const submit = () => {
    form.post(route('identity.mfa.enroll.confirm', { tenant: props.tenant.id }));
};
</script>

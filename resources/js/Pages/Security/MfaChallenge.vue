<template>
    <v-app>
        <v-main>
            <v-container class="py-8" style="max-width: 480px">
                <v-card>
                    <v-card-title>Two-factor verification</v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="submit">
                            <v-text-field
                                v-model="form.code"
                                label="Authentication code"
                                :error-messages="form.errors.code"
                                required
                            />
                            <v-btn type="submit" color="primary" block :loading="form.processing">
                                Verify
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
});

const form = useForm({ code: '' });

const submit = () => {
    form.post(route('identity.mfa.challenge.verify', { tenant: props.tenant.id }));
};
</script>

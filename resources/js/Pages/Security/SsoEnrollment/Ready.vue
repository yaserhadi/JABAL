<template>
    <v-app>
        <v-main>
            <v-container class="py-8" style="max-width: 480px">
                <v-card>
                    <v-card-title>Connect Enterprise SSO</v-card-title>
                    <v-card-text>
                        <p class="mb-4">
                            You are signed in as the invited account. Continue to your organization's
                            identity provider to link Enterprise SSO. Your current session will stay active.
                        </p>
                        <v-form @submit.prevent="submit">
                            <input type="hidden" name="invitation_id" :value="invitation_id" />
                            <v-btn type="submit" color="primary" block :loading="form.processing">
                                Continue to identity provider
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
    invitation_id: { type: String, required: true },
    start_url: { type: String, required: true },
    token: { type: String, default: null },
});

const form = useForm({
    invitation_id: props.invitation_id,
});

const submit = () => {
    form.post(props.start_url);
};
</script>

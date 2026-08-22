<template>
    <AppLayout>
        <v-row justify="center">
            <v-col cols="12" md="6">
                <v-card>
                    <v-card-title class="text-h5 pa-4">Complete your account</v-card-title>
                    <v-card-text>
                        <p v-if="tenant" class="mb-4">
                            You have been invited to join <strong>{{ tenant.name }}</strong> as
                            <strong>{{ email }}</strong>.
                        </p>

                        <v-alert v-if="isAuthenticated && isIntendedUser && emailMatches" type="info" variant="tonal" class="mb-4">
                            Signed in as {{ email }}. Click below to join this workspace.
                        </v-alert>

                        <v-alert v-else-if="isAuthenticated && !emailMatches" type="warning" variant="tonal" class="mb-4">
                            You are signed in with a different account. Log out and complete the invitation for {{ email }}.
                        </v-alert>

                        <v-form v-if="isAuthenticated && isIntendedUser && emailMatches" @submit.prevent="acceptInvite">
                            <v-btn type="submit" color="primary" block :loading="acceptForm.processing">
                                Accept invitation
                            </v-btn>
                        </v-form>

                        <v-form v-else-if="!isAuthenticated" @submit.prevent="completeAccount">
                            <v-text-field
                                :model-value="intendedUserName || email"
                                label="Name"
                                readonly
                            />
                            <v-text-field :model-value="email" label="Email" type="email" readonly />
                            <v-text-field
                                v-model="completeForm.password"
                                label="Password"
                                type="password"
                                :error-messages="completeForm.errors.password"
                                required
                            />
                            <v-text-field
                                v-model="completeForm.password_confirmation"
                                label="Confirm password"
                                type="password"
                                required
                            />
                            <v-btn type="submit" color="primary" block class="mt-2" :loading="completeForm.processing">
                                Set password and join
                            </v-btn>
                        </v-form>

                        <div v-else class="mt-4">
                            <v-btn :href="route('login')" variant="outlined" block class="mb-2">Log in to accept</v-btn>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps({
    email: String,
    intendedUserName: String,
    tenant: Object,
    isAuthenticated: Boolean,
    emailMatches: Boolean,
    isIntendedUser: Boolean,
});

const acceptForm = useForm({});
const completeForm = useForm({
    password: '',
    password_confirmation: '',
});

const acceptInvite = () => {
    acceptForm.post(route('invitations.accept'));
};

const completeAccount = () => {
    completeForm.post(route('invitations.register'));
};
</script>

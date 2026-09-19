<template>
    <GuestLayout>
        <v-row justify="center">
            <v-col cols="12" md="6">
                <v-card>
                    <v-card-title class="text-h5 pa-4">Complete your account</v-card-title>
                    <v-card-text>
                        <p v-if="invitationTenant" class="mb-4">
                            You have been invited to join <strong>{{ invitationTenant.name }}</strong> as
                            <strong>{{ email }}</strong>.
                        </p>

                        <v-alert
                            v-if="lifecycleMessage"
                            type="warning"
                            variant="tonal"
                            class="mb-4"
                        >
                            {{ lifecycleMessage }}
                        </v-alert>

                        <template v-else>
                            <v-alert
                                v-if="isAuthenticated && isIntendedUser && emailMatches"
                                type="info"
                                variant="tonal"
                                class="mb-4"
                            >
                                Signed in as {{ email }}. Click below to join this workspace.
                            </v-alert>

                            <v-alert
                                v-else-if="isAuthenticated && !emailMatches"
                                type="warning"
                                variant="tonal"
                                class="mb-4"
                            >
                                You are signed in with a different account. Log out and complete the invitation for
                                {{ email }}.
                            </v-alert>

                            <v-form
                                v-if="isAuthenticated && isIntendedUser && emailMatches"
                                @submit.prevent="acceptInvite"
                            >
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
                                    :error-messages="passwordErrors.password"
                                    required
                                />
                                <v-text-field
                                    v-model="completeForm.password_confirmation"
                                    label="Confirm password"
                                    type="password"
                                    :error-messages="passwordErrors.password_confirmation"
                                    required
                                />
                                <v-btn
                                    type="submit"
                                    color="primary"
                                    block
                                    class="mt-2"
                                    :loading="completeForm.processing"
                                >
                                    Set password and join
                                </v-btn>
                            </v-form>

                            <div v-else class="mt-4">
                                <v-btn
                                    v-if="isAuthenticated"
                                    color="primary"
                                    variant="outlined"
                                    block
                                    class="mb-2"
                                    :loading="logoutForm.processing"
                                    @click="logoutToAccept"
                                >
                                    Log out
                                </v-btn>
                                <v-btn :href="route('login')" variant="outlined" block class="mb-2">
                                    Log in to accept
                                </v-btn>
                            </div>
                        </template>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </GuestLayout>
</template>

<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { passwordFieldErrors } from '@/support/registerFormFeedback';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps({
    email: String,
    intendedUserName: String,
    invitationTenant: Object,
    isAuthenticated: Boolean,
    emailMatches: Boolean,
    isIntendedUser: Boolean,
    lifecycleStatus: {
        type: String,
        default: null,
    },
    lifecycleMessage: {
        type: String,
        default: null,
    },
});

const acceptForm = useForm({});
const completeForm = useForm({
    password: '',
    password_confirmation: '',
});
const logoutForm = useForm({});

const passwordErrors = computed(() => passwordFieldErrors(completeForm.errors));

const acceptInvite = () => {
    acceptForm.post(route('invitations.accept'));
};

const completeAccount = () => {
    completeForm.post(route('invitations.register'));
};

const logoutToAccept = () => {
    logoutForm.post(route('logout'));
};
</script>

<template>
    <PublicOnboardingShell :brand="appName" :subtitle="pageSubtitle">
        <p v-if="invitationTenant && !lifecycleMessage" class="text-body-2 text-medium-emphasis text-center mb-4">
            You’ve been invited to join:
            <strong class="text-high-emphasis">{{ invitationTenant.name }}</strong>
        </p>

        <v-alert v-if="lifecycleMessage" type="warning" variant="tonal" class="mb-4">
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
                <v-alert
                    v-if="submitError"
                    type="error"
                    variant="tonal"
                    class="mb-4"
                    data-testid="activation-submit-error"
                >
                    {{ submitError }}
                </v-alert>
                <v-text-field
                    :model-value="intendedUserName || email"
                    label="Name"
                    readonly
                    prepend-inner-icon="mdi-account"
                />
                <v-text-field
                    :model-value="email"
                    label="Email"
                    type="email"
                    readonly
                    prepend-inner-icon="mdi-email"
                    hint="Verified by your invitation"
                    persistent-hint
                    class="mb-2"
                />
                <PasswordPairFields
                    v-model:password="completeForm.password"
                    v-model:password-confirmation="completeForm.password_confirmation"
                    :errors="passwordErrors"
                    :match-hint="showMatchHint"
                />
                <v-btn
                    type="submit"
                    color="primary"
                    block
                    class="mt-2"
                    :loading="completeForm.processing"
                    :disabled="completeForm.processing"
                >
                    Create account
                </v-btn>
            </v-form>

            <div v-else class="mt-2">
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
                <v-btn :href="route('login')" variant="outlined" block>
                    Log in to accept
                </v-btn>
            </div>
        </template>

        <template v-if="!lifecycleMessage && !isAuthenticated" #actions>
            <v-btn text :href="route('login')" variant="text"> Already have an account? Sign in </v-btn>
        </template>
    </PublicOnboardingShell>
</template>

<script setup>
import PasswordPairFields from '@/Components/Onboarding/PasswordPairFields.vue';
import PublicOnboardingShell from '@/Layouts/PublicOnboardingShell.vue';
import { passwordFieldErrors, passwordsMatch } from '@/support/registerFormFeedback';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';

const pageSubtitle = computed(() =>
    props.lifecycleMessage ? 'Invitation unavailable' : 'Complete your account'
);

const acceptForm = useForm({});
const completeForm = useForm({
    password: '',
    password_confirmation: '',
});
const logoutForm = useForm({});
const submitError = ref(null);

const passwordErrors = computed(() =>
    passwordFieldErrors(
        completeForm.errors,
        completeForm.password,
        completeForm.password_confirmation
    )
);

const showMatchHint = computed(() =>
    passwordsMatch(completeForm.password, completeForm.password_confirmation)
);

const acceptInvite = () => {
    acceptForm.post(route('invitations.accept'));
};

const completeAccount = () => {
    submitError.value = null;

    if (!completeForm.password || !completeForm.password_confirmation) {
        submitError.value = 'Enter a password and confirmation to create your account.';
        return;
    }

    if (!passwordsMatch(completeForm.password, completeForm.password_confirmation)) {
        submitError.value = 'Passwords do not match.';
        return;
    }

    completeForm.post(route('invitations.register'), {
        onError: () => {
            submitError.value =
                completeForm.errors.password?.[0] ||
                completeForm.errors.password_confirmation?.[0] ||
                completeForm.errors.email?.[0] ||
                completeForm.errors.token?.[0] ||
                'Could not create your account. Check the form and try again.';
        },
    });
};

const logoutToAccept = () => {
    logoutForm.post(route('logout'));
};
</script>

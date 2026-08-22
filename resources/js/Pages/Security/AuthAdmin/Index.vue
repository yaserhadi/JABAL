<template>
    <AppLayout>
        <v-row>
            <v-col cols="12" md="10" lg="8">
                <h1 class="text-h5 mb-2">Authentication Administration</h1>
                <p class="text-medium-emphasis mb-4">
                    Distinct sensitive operations — not a generic Reset User.
                    Fresh administrator Password (and MFA when enrolled) is required per operation.
                </p>

                <v-alert v-if="flash?.success" type="success" variant="tonal" class="mb-4" dismissible>
                    {{ flash.success }}
                </v-alert>

                <v-card class="mb-4">
                    <v-card-title>1. Fresh administrator proof</v-card-title>
                    <v-card-text>
                        <v-select
                            v-model="stepUp.purpose"
                            :items="purposeOptions"
                            label="Operation purpose"
                            item-title="label"
                            item-value="value"
                            class="mb-2"
                        />
                        <v-text-field
                            v-model="stepUp.password"
                            label="Confirm your password"
                            type="password"
                            :error-messages="stepUp.errors.password"
                        />
                        <v-btn color="primary" :loading="stepUp.processing" @click="confirmPassword">
                            Confirm password
                        </v-btn>
                        <p class="text-caption mt-2">
                            Then complete MFA challenge if prompted for the selected purpose.
                        </p>
                    </v-card-text>
                </v-card>

                <v-card class="mb-4">
                    <v-card-title>Reset Password</v-card-title>
                    <v-card-text>
                        <v-text-field v-model="forms.resetPassword.user_id" label="Target user UUID" />
                        <v-btn color="primary" variant="outlined" @click="submit('resetPassword', 'auth-admin.reset-password')">
                            Initiate reset
                        </v-btn>
                    </v-card-text>
                </v-card>

                <v-card class="mb-4">
                    <v-card-title>Reset MFA</v-card-title>
                    <v-card-text>
                        <v-text-field v-model="forms.resetMfa.user_id" label="Target user UUID" />
                        <v-btn color="primary" variant="outlined" @click="submit('resetMfa', 'auth-admin.reset-mfa')">
                            Reset MFA
                        </v-btn>
                    </v-card-text>
                </v-card>

                <v-card class="mb-4">
                    <v-card-title>Reset SSO</v-card-title>
                    <v-card-subtitle>Lifecycle status — not editable issuer/EUID fields</v-card-subtitle>
                    <v-card-text>
                        <v-text-field v-model="forms.resetSso.user_id" label="Target user UUID" />
                        <v-checkbox v-model="forms.resetSso.compromised" label="Current binding compromised (security-hold)" />
                        <v-btn color="primary" variant="outlined" @click="submit('resetSso', 'auth-admin.reset-sso')">
                            Initiate Reset SSO
                        </v-btn>
                    </v-card-text>
                </v-card>

                <v-card class="mb-4">
                    <v-card-title>Change Authentication Policy</v-card-title>
                    <v-card-text>
                        <v-select
                            v-model="forms.changePolicy.authentication_policy"
                            :items="policyOptions"
                            item-title="label"
                            item-value="value"
                            label="Policy"
                        />
                        <v-btn color="primary" variant="outlined" @click="submit('changePolicy', 'auth-admin.change-policy')">
                            Change policy
                        </v-btn>
                    </v-card-text>
                </v-card>

                <v-card class="mb-4">
                    <v-card-title>Change Canonical Email</v-card-title>
                    <v-card-text>
                        <v-text-field v-model="forms.changeEmail.user_id" label="Target user UUID" />
                        <v-text-field v-model="forms.changeEmail.email" label="New email" type="email" />
                        <v-btn color="primary" variant="outlined" @click="submit('changeEmail', 'auth-admin.change-email')">
                            Initiate email change
                        </v-btn>
                    </v-card-text>
                </v-card>

                <v-card class="mb-4">
                    <v-card-title>IdP Migration</v-card-title>
                    <v-card-text>
                        <v-text-field v-model="forms.pathA.user_id" label="Target user UUID (PATH A)" class="mb-2" />
                        <v-btn class="me-2" color="primary" variant="outlined" @click="submit('pathA', 'auth-admin.path-a')">
                            Start PATH A
                        </v-btn>
                        <v-btn color="secondary" variant="outlined" @click="submit('pathB', 'auth-admin.path-b')">
                            Activate PATH B bridge
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import { tenantRouteParams } from '@/support/tenantEntry';

const props = defineProps({
    tenant: { type: Object, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash);

const purposeOptions = [
    { label: 'Reset Password', value: 'auth_admin.reset_password' },
    { label: 'Reset MFA', value: 'auth_admin.reset_mfa' },
    { label: 'Reset SSO', value: 'auth_admin.reset_sso' },
    { label: 'Change Policy', value: 'auth_admin.change_policy' },
    { label: 'Change Email', value: 'auth_admin.change_email' },
    { label: 'IdP Migration', value: 'auth_admin.idp_migration' },
];

const policyOptions = [
    { label: 'Password', value: 'password' },
    { label: 'Enterprise SSO', value: 'sso' },
    { label: 'Password + Enterprise SSO', value: 'both' },
];

const stepUp = useForm({ purpose: 'auth_admin.reset_sso', password: '' });
const forms = {
    resetPassword: useForm({ user_id: '' }),
    resetMfa: useForm({ user_id: '' }),
    resetSso: useForm({ user_id: '', compromised: false }),
    changePolicy: useForm({ authentication_policy: 'both' }),
    changeEmail: useForm({ user_id: '', email: '' }),
    pathA: useForm({ user_id: '' }),
    pathB: useForm({}),
};

const confirmPassword = () => {
    stepUp.post(route('auth-admin.confirm-password', tenantRouteParams(props.tenant)), {
        preserveScroll: true,
        onSuccess: () => stepUp.reset('password'),
    });
};

const submit = (key, routeName) => {
    forms[key].post(route(routeName, tenantRouteParams(props.tenant)), { preserveScroll: true });
};
</script>

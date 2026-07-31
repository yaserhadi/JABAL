<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-4">Security settings</h1>
            </v-col>
        </v-row>

        <v-row>
            <v-col v-if="policies" cols="12" md="8" lg="6">
                <v-card class="mb-4">
                    <v-card-title class="text-h6 pa-4">Security policies</v-card-title>
                    <v-card-subtitle class="px-4 pb-2">
                        Tenant-level security policy (tenant admins only).
                    </v-card-subtitle>
                    <v-card-text>
                        <v-alert v-if="flash?.success" type="success" variant="tonal" class="mb-4" dismissible>
                            {{ flash.success }}
                        </v-alert>

                        <v-form @submit.prevent="submitPolicies">
                            <v-switch
                                v-model="policyForm.mfa_required"
                                label="Require MFA"
                                color="primary"
                                class="mb-2"
                            />
                            <v-text-field
                                v-model.number="policyForm.mfa_grace_period_days"
                                label="MFA grace period (days)"
                                type="number"
                                min="0"
                                max="365"
                                class="mb-2"
                            />
                            <v-text-field
                                v-model.number="policyForm.password_policy.min_length"
                                label="Minimum password length"
                                type="number"
                                min="6"
                                max="128"
                                class="mb-2"
                            />
                            <v-switch
                                v-model="policyForm.password_policy.require_uppercase"
                                label="Require uppercase"
                                color="primary"
                                class="mb-1"
                            />
                            <v-switch
                                v-model="policyForm.password_policy.require_number"
                                label="Require number"
                                color="primary"
                                class="mb-1"
                            />
                            <v-switch
                                v-model="policyForm.password_policy.require_special"
                                label="Require special character"
                                color="primary"
                                class="mb-2"
                            />
                            <v-text-field
                                v-model.number="policyForm.session_idle_timeout"
                                label="Session idle timeout (minutes, -1 = disabled)"
                                type="number"
                                min="-1"
                                max="1440"
                                class="mb-4"
                            />

                            <v-btn
                                v-if="tenant_ui_permissions?.canUpdateSecurityPolicies"
                                color="primary"
                                type="submit"
                                :loading="policyForm.processing"
                                :disabled="policyForm.processing"
                            >
                                Save policies
                            </v-btn>
                            <v-alert v-else type="info" variant="tonal" density="compact" class="mt-2">
                                You can view these policies but cannot change them.
                            </v-alert>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col v-if="sso" cols="12" md="8" lg="6">
                <v-card class="mb-4">
                    <v-card-title class="text-h6 pa-4">Single sign-on (SSO)</v-card-title>
                    <v-card-subtitle class="px-4 pb-2">
                        Enterprise OIDC configuration for this organization.
                    </v-card-subtitle>
                    <v-card-text>
                        <v-alert
                            v-if="!tenant_ui_permissions?.ssoEntitlementAvailable"
                            type="warning"
                            variant="tonal"
                            class="mb-4"
                            density="compact"
                        >
                            SSO is not included in this tenant plan.
                        </v-alert>

                        <v-alert
                            v-if="sso.disabled_by_entitlement"
                            type="warning"
                            variant="tonal"
                            class="mb-4"
                            density="compact"
                        >
                            SSO was disabled because the plan no longer includes SSO. Configuration is preserved but sign-in cannot be re-enabled here.
                        </v-alert>

                        <v-form @submit.prevent="submitSso">
                            <v-switch
                                v-model="ssoForm.enabled"
                                label="Enable SSO"
                                color="primary"
                                class="mb-2"
                                :disabled="!canEditSso"
                            />
                            <v-text-field
                                v-model="ssoForm.provider_label"
                                label="Provider label"
                                maxlength="120"
                                class="mb-2"
                                :readonly="!canEditSso"
                            />
                            <v-text-field
                                v-model="ssoForm.issuer_url"
                                label="Issuer URL"
                                class="mb-2"
                                :readonly="!canEditSso"
                                hint="HTTPS OIDC issuer (e.g. Microsoft Entra)"
                                persistent-hint
                            />
                            <v-text-field
                                v-model="ssoForm.client_id"
                                label="Client ID"
                                class="mb-2"
                                :readonly="!canEditSso"
                            />
                            <v-text-field
                                v-model="ssoForm.client_secret"
                                label="Client secret"
                                type="password"
                                autocomplete="new-password"
                                class="mb-2"
                                :readonly="!canEditSso"
                                :placeholder="sso.has_client_secret ? 'Leave blank to keep existing secret' : 'Required when enabling SSO'"
                                :hint="sso.has_client_secret ? 'A client secret is already configured.' : 'No client secret configured yet.'"
                                persistent-hint
                            />
                            <v-text-field
                                v-model="ssoForm.redirect_uri"
                                label="Redirect URI (optional)"
                                class="mb-2"
                                :readonly="!canEditSso"
                                hint="Defaults to the application SSO callback route when empty"
                                persistent-hint
                            />
                            <v-text-field
                                v-model="ssoForm.scopes"
                                label="Scopes (space-separated)"
                                class="mb-4"
                                :readonly="!canEditSso"
                                hint="Must include openid"
                                persistent-hint
                            />

                            <v-btn
                                v-if="canEditSso"
                                color="primary"
                                type="submit"
                                :loading="ssoForm.processing"
                                :disabled="ssoForm.processing"
                            >
                                Save SSO settings
                            </v-btn>
                            <v-alert v-else type="info" variant="tonal" density="compact" class="mt-2">
                                You can view SSO settings but cannot change them.
                            </v-alert>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="8" lg="6">
                <v-card class="mb-4">
                    <v-card-title class="text-h6 pa-4">Active sessions</v-card-title>
                    <v-card-subtitle class="px-4 pb-2">
                        Your signed-in devices for this tenant.
                    </v-card-subtitle>
                    <v-card-text>
                        <v-alert v-if="flash?.success && !policies" type="success" variant="tonal" class="mb-4" dismissible>
                            {{ flash.success }}
                        </v-alert>

                        <v-list v-if="sessions.length" density="compact">
                            <v-list-item v-for="session in sessions" :key="session.id">
                                <v-list-item-title>
                                    {{ session.device_label || 'Unknown device' }}
                                    <v-chip v-if="session.is_current" size="x-small" color="primary" class="ml-2">
                                        This device
                                    </v-chip>
                                </v-list-item-title>
                                <v-list-item-subtitle>
                                    {{ session.ip_address || 'Unknown IP' }}
                                    · Last active {{ formatDate(session.last_activity_at) }}
                                </v-list-item-subtitle>
                                <template #append>
                                    <v-btn
                                        v-if="!session.is_current"
                                        size="small"
                                        variant="text"
                                        color="error"
                                        :loading="revokingSessionId === session.id"
                                        @click="revokeSession(session.id)"
                                    >
                                        Revoke
                                    </v-btn>
                                </template>
                            </v-list-item>
                        </v-list>
                        <v-alert v-else type="info" variant="tonal" density="compact">
                            No active sessions recorded.
                        </v-alert>

                        <v-btn
                            v-if="sessions.some((s) => !s.is_current)"
                            class="mt-4"
                            variant="outlined"
                            color="error"
                            :loading="revokingOthers"
                            @click="revokeOtherSessions"
                        >
                            Revoke all other sessions
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="8" lg="6">
                <v-card class="mb-4">
                    <v-card-title class="text-h6 pa-4">MFA status</v-card-title>
                    <v-card-text>
                        <v-list density="compact">
                            <v-list-item>
                                <v-list-item-title>Tenant policy requires MFA</v-list-item-title>
                                <template #append>
                                    <v-chip :color="mfa.required ? 'warning' : 'default'" size="small">
                                        {{ mfa.required ? 'Yes' : 'No' }}
                                    </v-chip>
                                </template>
                            </v-list-item>
                            <v-list-item>
                                <v-list-item-title>Your MFA status</v-list-item-title>
                                <template #append>
                                    <v-chip :color="mfa.enrolled ? 'success' : 'default'" size="small">
                                        {{ mfa.enrolled ? 'Enrolled' : 'Not enrolled' }}
                                    </v-chip>
                                </template>
                            </v-list-item>
                            <v-list-item v-if="mfa.available !== undefined">
                                <v-list-item-title>MFA available for tenant</v-list-item-title>
                                <template #append>
                                    <v-chip :color="mfa.available ? 'success' : 'default'" size="small">
                                        {{ mfa.available ? 'Yes' : 'No' }}
                                    </v-chip>
                                </template>
                            </v-list-item>
                        </v-list>

                        <v-btn
                            v-if="mfa.available && !mfa.enrolled"
                            class="mt-2"
                            color="primary"
                            variant="outlined"
                            @click="visitEnroll"
                        >
                            Set up MFA
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="8" lg="6">
                <v-card class="mb-4">
                    <v-card-title class="text-h6 pa-4">API tokens</v-card-title>
                    <v-card-subtitle class="px-4 pb-2">
                        Read-only summary of your tokens for this tenant.
                    </v-card-subtitle>
                    <v-card-text>
                        <v-list v-if="tokens.length" density="compact">
                            <v-list-item v-for="token in tokens" :key="token.id">
                                <v-list-item-title>{{ token.name }}</v-list-item-title>
                                <v-list-item-subtitle>
                                    Created {{ formatDate(token.created_at) }}
                                    <span v-if="token.last_used_at"> · Last used {{ formatDate(token.last_used_at) }}</span>
                                    <span v-if="token.expires_at"> · Expires {{ formatDate(token.expires_at) }}</span>
                                </v-list-item-subtitle>
                            </v-list-item>
                        </v-list>
                        <v-alert v-else type="info" variant="tonal" density="compact">
                            No API tokens for this tenant.
                        </v-alert>

                        <v-alert type="info" variant="tonal" density="compact" class="mt-4">
                            Token management (create/revoke) is available via the REST API at
                            <code>/api/v1/auth/tokens</code>.
                        </v-alert>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';
import { tenantRouteParams } from '@/support/tenantEntry';

const page = usePage();

const props = defineProps({
    tenant: { type: Object, required: true },
    policies: { type: Object, default: null },
    sessions: { type: Array, default: () => [] },
    mfa: { type: Object, required: true },
    tokens: { type: Array, default: () => [] },
    sso: { type: Object, default: null },
});

const tenant_ui_permissions = computed(() => page.props.tenant_ui_permissions);
const flash = computed(() => page.props.flash);

const canEditSso = computed(() => {
    return Boolean(
        tenant_ui_permissions.value?.canUpdateSso
        && tenant_ui_permissions.value?.ssoEntitlementAvailable
        && !props.sso?.disabled_by_entitlement
    );
});

const policyForm = useForm({
    mfa_required: props.policies?.mfa_required ?? false,
    mfa_grace_period_days: props.policies?.mfa_grace_period_days ?? 0,
    password_policy: {
        min_length: props.policies?.password_policy?.min_length ?? 8,
        require_uppercase: props.policies?.password_policy?.require_uppercase ?? false,
        require_number: props.policies?.password_policy?.require_number ?? false,
        require_special: props.policies?.password_policy?.require_special ?? false,
    },
    session_idle_timeout: props.policies?.session_idle_timeout ?? -1,
});

const ssoForm = useForm({
    enabled: props.sso?.enabled ?? false,
    provider_label: props.sso?.provider_label ?? '',
    issuer_url: props.sso?.issuer_url ?? '',
    client_id: props.sso?.client_id ?? '',
    client_secret: '',
    redirect_uri: props.sso?.redirect_uri ?? '',
    scopes: (props.sso?.scopes ?? ['openid', 'profile', 'email']).join(' '),
});

const revokingSessionId = ref(null);
const revokingOthers = ref(false);

function submitPolicies() {
    if (!tenant_ui_permissions.value?.canUpdateSecurityPolicies) {
        return;
    }
    policyForm.patch(route('identity.security-settings.update-policies', tenantRouteParams(props.tenant)), {
        preserveScroll: true,
    });
}

function submitSso() {
    if (!canEditSso.value) {
        return;
    }

    const payload = {
        enabled: ssoForm.enabled,
        provider_label: ssoForm.provider_label || null,
        issuer_url: ssoForm.issuer_url || null,
        client_id: ssoForm.client_id || null,
        redirect_uri: ssoForm.redirect_uri || null,
        scopes: ssoForm.scopes
            .split(/\s+/)
            .map((scope) => scope.trim())
            .filter(Boolean),
    };

    if (ssoForm.client_secret) {
        payload.client_secret = ssoForm.client_secret;
    }

    ssoForm.transform(() => payload).patch(route('identity.sso.update', tenantRouteParams(props.tenant)), {
        preserveScroll: true,
        onSuccess: () => {
            ssoForm.client_secret = '';
        },
    });
}

function revokeSession(sessionId) {
    revokingSessionId.value = sessionId;
    router.delete(route('identity.security-settings.revoke-session', tenantRouteParams(props.tenant, {
        session: sessionId,
    })), {
        preserveScroll: true,
        onFinish: () => {
            revokingSessionId.value = null;
        },
    });
}

function revokeOtherSessions() {
    revokingOthers.value = true;
    router.delete(route('identity.security-settings.revoke-other-sessions', tenantRouteParams(props.tenant)), {
        preserveScroll: true,
        onFinish: () => {
            revokingOthers.value = false;
        },
    });
}

function visitEnroll() {
    router.visit(route('identity.mfa.enroll', tenantRouteParams(props.tenant)));
}

function formatDate(iso) {
    if (!iso) {
        return '—';
    }
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}
</script>

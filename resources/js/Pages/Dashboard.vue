<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-h5 pa-4">
                        Dashboard
                    </v-card-title>
                    <v-card-text>
                        <v-alert type="success" variant="tonal" class="mb-4">
                            Welcome to {{ appName }}! You are logged in as <strong>{{ $page.props.auth.user.name }}</strong>.
                        </v-alert>

                        <v-row>
                            <v-col cols="12" md="4">
                                <v-card color="primary" dark>
                                    <v-card-text>
                                        <div class="text-h6">Current tenant</div>
                                        <div class="text-h4 mt-2">{{ tenantDisplayName }}</div>
                                        <div v-if="tenant?.slug" class="text-body-2 mt-1 opacity-80">{{ tenant.slug }}</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-card color="secondary" dark>
                                    <v-card-text>
                                        <div class="text-h6">Tenant settings</div>
                                        <div class="text-body-2 mt-2">
                                            <template v-if="tenant_ui_permissions?.canViewTenantSettings && tenant">
                                                <v-btn
                                                    variant="text"
                                                    class="text-white pa-0 text-decoration-underline"
                                                    :to="route('tenant.settings.index', { tenant: tenant.id })"
                                                >
                                                    Open settings
                                                </v-btn>
                                            </template>
                                            <template v-else>
                                                Tenant admins configure branding and locale here.
                                            </template>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-card color="accent">
                                    <v-card-text>
                                        <div class="text-h6">Audit logs</div>
                                        <div class="text-body-2 mt-2">
                                            <template v-if="tenant_ui_permissions?.canViewTenantAudit && tenant">
                                                <v-btn
                                                    variant="text"
                                                    class="pa-0 text-decoration-underline"
                                                    :to="route('tenant.audit.index', { tenant: tenant.id })"
                                                >
                                                    View activity timeline
                                                </v-btn>
                                            </template>
                                            <template v-else>
                                                Tenant admins can review membership activity here.
                                            </template>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const page = usePage();

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';
const tenant = computed(() => page.props.tenant);
const tenantBranding = computed(() => page.props.tenantBranding);
const tenant_ui_permissions = computed(() => page.props.tenant_ui_permissions);

const tenantDisplayName = computed(() => {
    if (tenantBranding.value?.display_name) {
        return tenantBranding.value.display_name;
    }
    return tenant.value?.name || '—';
});
</script>

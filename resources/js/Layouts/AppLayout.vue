<template>
    <v-app>
        <v-app-bar color="primary" dark app>
            <v-app-bar-nav-icon @click="drawer = !drawer" />
            <v-toolbar-title>
                <span>{{ tenantBranding?.display_name || appName }}</span>
                <span v-if="tenantBranding?.display_name" class="text-caption d-block font-weight-regular opacity-80">
                    {{ appName }}
                </span>
            </v-toolbar-title>
            <v-spacer />
            <v-menu>
                <template #activator="{ props }">
                    <v-btn icon v-bind="props">
                        <v-icon>mdi-account-circle</v-icon>
                    </v-btn>
                </template>
                <v-list>
                    <v-list-item>
                        <v-list-item-title>{{ $page.props.auth.user.name }}</v-list-item-title>
                        <v-list-item-subtitle>{{ $page.props.auth.user.email }}</v-list-item-subtitle>
                    </v-list-item>
                    <v-divider />
                    <v-list-item @click="logout">
                        <v-list-item-title>
                            <v-icon start>mdi-logout</v-icon>
                            Logout
                        </v-list-item-title>
                    </v-list-item>
                </v-list>
            </v-menu>
        </v-app-bar>

        <v-navigation-drawer v-model="drawer" app>
            <v-list nav>
                <v-list-item
                    v-if="tenant"
                    prepend-icon="mdi-view-dashboard"
                    title="Dashboard"
                    @click="visitTenantRoute('dashboard')"
                />
                <v-list-item
                    v-if="tenant"
                    prepend-icon="mdi-folder-multiple"
                    title="Workspaces"
                    @click="visitTenantRoute('workspaces.index')"
                />
                <v-list-item
                    v-if="tenant"
                    prepend-icon="mdi-account-group"
                    title="Members"
                    @click="visitTenantRoute('members.index')"
                />
                <v-divider class="my-2" />
                <v-list-item
                    v-if="tenant_ui_permissions?.canViewTenantSettings && tenant"
                    prepend-icon="mdi-cog"
                    title="Tenant settings"
                    @click="visitTenantRoute('tenant.settings.index')"
                />
                <!-- Intentional: tenant shell nav does not link to platform /admin/audit yet (Settings/Audit modules).
                     Enable only with platform-admin gating + correct route, not tenant Ziggy names. -->
                <v-list-item
                    prepend-icon="mdi-clipboard-text"
                    title="Audit Logs"
                    disabled
                />
            </v-list>
        </v-navigation-drawer>

        <v-main>
            <v-container fluid>
                <slot />
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const page = usePage();
const tenant = computed(() => page.props.tenant);
const tenantBranding = computed(() => page.props.tenantBranding);
const tenant_ui_permissions = computed(() => page.props.tenant_ui_permissions);
const appName = import.meta.env.VITE_APP_NAME || 'JABAL';
const drawer = ref(true);

/** Inertia has no vue-router; v-list-item :to does nothing. Use client visits instead. */
const visitTenantRoute = (name) => {
    const t = tenant.value;
    if (!t?.id) {
        return;
    }
    router.visit(route(name, { tenant: t.id }));
};

const logout = () => {
    // Use form submit for full page navigation so login page displays immediately.
    // CSRF from page props (current) — meta tag can be stale after Inertia navigation.
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = route('logout');
    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = '_token';
    csrf.value = page.props.csrf_token || document.querySelector('meta[name="csrf-token"]')?.content || '';
    form.appendChild(csrf);
    document.body.appendChild(form);
    form.submit();
};
</script>

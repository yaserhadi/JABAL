<template>
    <v-app>
        <v-app-bar color="primary" dark app>
            <v-app-bar-nav-icon @click="drawer = !drawer" />
            <v-toolbar-title>{{ appName }}</v-toolbar-title>
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
                    prepend-icon="mdi-view-dashboard"
                    title="Dashboard"
                    :to="route('dashboard')"
                />
                <v-divider class="my-2" />
                <v-list-item
                    prepend-icon="mdi-cog"
                    title="Settings"
                    disabled
                />
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
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';
const drawer = ref(true);

const logout = () => {
    router.post(route('logout'));
};
</script>

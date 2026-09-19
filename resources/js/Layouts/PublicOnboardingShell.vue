<template>
    <v-app>
        <v-main>
            <v-container fluid class="fill-height">
                <v-row align="center" justify="center">
                    <v-col cols="12" sm="8" md="4">
                        <v-card>
                            <v-card-title class="text-h5 text-center pa-4">
                                {{ brand }}
                            </v-card-title>
                            <v-card-subtitle v-if="subtitle" class="text-center pb-2">
                                {{ subtitle }}
                            </v-card-subtitle>
                            <v-card-text>
                                <slot />
                            </v-card-text>
                            <v-card-actions v-if="$slots.actions" class="justify-center flex-column ga-1 pb-4">
                                <slot name="actions" />
                            </v-card-actions>
                        </v-card>
                    </v-col>
                </v-row>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
/**
 * Shared public onboarding shell for /login, /register, and invitation activation.
 * Intentionally not GuestLayout / AppLayout — no app-bar, nav, or signed-in chrome.
 */
defineProps({
    brand: {
        type: String,
        default: () => import.meta.env.VITE_APP_NAME || 'JABAL',
    },
    subtitle: {
        type: String,
        default: null,
    },
});
</script>

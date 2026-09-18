<template>
    <v-app>
        <v-main>
            <v-container fluid class="fill-height">
                <v-row align="center" justify="center">
                    <v-col cols="12" sm="8" md="5" lg="4">
                        <v-card>
                            <v-card-title class="text-h4 text-center pa-6 pb-2">
                                {{ appName }}
                            </v-card-title>
                            <v-card-text class="text-center px-6 pb-2">
                                <p class="text-body-1 text-medium-emphasis mb-0">
                                    Multi-tenant workspace platform for organizations and their teams.
                                </p>
                            </v-card-text>
                            <v-card-actions class="flex-column ga-3 px-6 pb-8 pt-4">
                                <v-btn
                                    color="primary"
                                    block
                                    size="large"
                                    :href="route('login')"
                                >
                                    Login
                                </v-btn>
                                <v-btn
                                    variant="tonal"
                                    block
                                    size="large"
                                    :href="route('register')"
                                >
                                    Register
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-col>
                </v-row>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { route } from 'ziggy-js';

defineProps({
    appName: {
        type: String,
        default: 'Jabal',
    },
});
</script>

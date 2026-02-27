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
                                        <div class="text-h6">Current Tenant</div>
                                        <div class="text-h4 mt-2">Personal</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-card color="secondary" dark>
                                    <v-card-text>
                                        <div class="text-h6">Settings</div>
                                        <div class="text-body-2 mt-2">Coming soon in Phase 1</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-card color="accent">
                                    <v-card-text>
                                        <div class="text-h6">Audit Logs</div>
                                        <div class="text-body-2 mt-2">Coming soon in Phase 1</div>
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

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';
</script>

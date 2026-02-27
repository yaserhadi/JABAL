<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-h5 pa-4">
                        Platform Settings
                    </v-card-title>
                    <v-card-subtitle class="px-4">
                        Configure central platform settings
                    </v-card-subtitle>
                    <v-card-text>
                        <v-alert type="info" variant="tonal" class="mb-4">
                            These are central platform settings that control the overall behavior of the system.
                            Tenant-specific and user-specific settings will be added in later phases.
                        </v-alert>

                        <v-form @submit.prevent="submit">
                            <v-row>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="form.app_name"
                                        label="Application Name"
                                        hint="The name displayed across the platform"
                                        persistent-hint
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-select
                                        v-model="form.default_isolation"
                                        label="Default Tenant Isolation"
                                        :items="['shared', 'schema', 'database']"
                                        hint="Default isolation level for new tenants"
                                        persistent-hint
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-switch
                                        v-model="form.maintenance_mode"
                                        label="Maintenance Mode"
                                        color="warning"
                                        hint="Enable platform-wide maintenance mode"
                                        persistent-hint
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-switch
                                        v-model="form.registration_enabled"
                                        label="Allow Registration"
                                        color="primary"
                                        hint="Allow new user registrations"
                                        persistent-hint
                                    />
                                </v-col>
                                <v-col cols="12">
                                    <v-textarea
                                        v-model="form.maintenance_message"
                                        label="Maintenance Message"
                                        rows="3"
                                        hint="Message shown during maintenance mode"
                                        persistent-hint
                                    />
                                </v-col>
                            </v-row>

                            <v-divider class="my-4" />

                            <v-btn
                                type="submit"
                                color="primary"
                                :loading="form.processing"
                            >
                                Save Settings
                            </v-btn>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    settings: {
        type: Object,
        default: () => ({}),
    },
});

const form = useForm({
    app_name: props.settings.app_name || 'JABAL',
    default_isolation: props.settings.default_isolation || 'shared',
    maintenance_mode: props.settings.maintenance_mode || false,
    registration_enabled: props.settings.registration_enabled || true,
    maintenance_message: props.settings.maintenance_message || 'We are currently performing scheduled maintenance.',
});

const submit = () => {
    form.post(route('settings.update'), {
        preserveScroll: true,
        onSuccess: () => {
            // Settings updated
        },
    });
};
</script>

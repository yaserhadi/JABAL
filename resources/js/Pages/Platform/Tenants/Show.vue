<template>
    <PlatformLayout>
        <v-row>
            <v-col cols="12">
                <v-alert v-if="flash?.success" type="success" variant="tonal" class="mb-4" dismissible>
                    {{ flash.success }}
                </v-alert>

                <v-card class="mb-4">
                    <v-card-title class="text-h5 pa-4 d-flex justify-space-between align-center flex-wrap ga-2">
                        <span>{{ tenant.name }}</span>
                        <div class="d-flex ga-2">
                            <Link :href="route('platform.tenants.edit', tenant.id)">
                                <v-btn variant="tonal">Edit name</v-btn>
                            </Link>
                            <Link :href="route('platform.tenants.index')">
                                <v-btn variant="text">Back</v-btn>
                            </Link>
                        </div>
                    </v-card-title>
                    <v-card-text>
                        <v-row>
                            <v-col cols="12" md="6">
                                <div class="text-caption text-medium-emphasis">UUID</div>
                                <code>{{ tenant.id }}</code>
                            </v-col>
                            <v-col cols="12" md="6">
                                <div class="text-caption text-medium-emphasis">Tenant Handle</div>
                                <code>{{ tenant.handle }}</code>
                                <div class="text-body-2 mt-1">
                                    Live path:
                                    <code>{{ tenant.entry_url }}</code>
                                </div>
                            </v-col>
                            <v-col cols="12" md="4">
                                <div class="text-caption text-medium-emphasis">Isolation</div>
                                <div>{{ tenant.isolation_level }} <span class="text-caption">(read-only)</span></div>
                            </v-col>
                            <v-col cols="12" md="4">
                                <div class="text-caption text-medium-emphasis">Lifecycle status</div>
                                <v-chip size="small" variant="tonal">{{ tenant.lifecycle_status }}</v-chip>
                            </v-col>
                            <v-col cols="12" md="4">
                                <div class="text-caption text-medium-emphasis">Provisioning status</div>
                                <v-chip
                                    size="small"
                                    :color="tenant.provisioning_status === 'completed' ? 'success' : 'warning'"
                                    variant="tonal"
                                >
                                    {{ tenant.provisioning_status }}
                                </v-chip>
                            </v-col>
                        </v-row>

                        <v-alert
                            v-if="tenant.provisioning_status === 'action_required'"
                            type="warning"
                            variant="tonal"
                            class="mt-4"
                        >
                            <div class="font-weight-medium mb-1">Action required</div>
                            <div>{{ tenant.provisioning_detail }}</div>
                            <div class="text-caption mt-2">
                                No in-app retry is available. Use the operational guidance above.
                            </div>
                        </v-alert>
                        <v-alert v-else type="success" variant="tonal" class="mt-4">
                            {{ tenant.provisioning_detail }}
                        </v-alert>
                    </v-card-text>
                </v-card>

                <v-row>
                    <v-col cols="12" md="6">
                        <v-card>
                            <v-card-title>Application Owner</v-card-title>
                            <v-card-text>
                                <template v-if="tenant.application_owner">
                                    <div>{{ tenant.application_owner.name }}</div>
                                    <div class="text-medium-emphasis">{{ tenant.application_owner.email }}</div>
                                </template>
                                <v-alert v-else type="info" variant="tonal" density="compact">
                                    Not assigned
                                </v-alert>
                            </v-card-text>
                        </v-card>
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-card>
                            <v-card-title>Commercial Owner Contact</v-card-title>
                            <v-card-text>
                                <template v-if="tenant.commercial_owner_contact?.assigned">
                                    <div>{{ tenant.commercial_owner_contact.full_name }}</div>
                                    <div v-if="tenant.commercial_owner_contact.email" class="text-medium-emphasis">
                                        {{ tenant.commercial_owner_contact.email }}
                                    </div>
                                </template>
                                <v-alert v-else type="info" variant="tonal" density="compact">
                                    Not assigned
                                </v-alert>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-col>
        </v-row>
    </PlatformLayout>
</template>

<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps({
    tenant: { type: Object, required: true },
    flash: { type: Object, default: () => ({}) },
});
</script>

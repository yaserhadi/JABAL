<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-h5 pa-4 d-flex justify-space-between align-center">
                        <span>Workspaces</span>
                        <Link
                            v-if="tenant"
                            :href="route('workspaces.create', { tenant: tenantEntry(tenant) })"
                        >
                            <v-btn color="primary">
                                Create Workspace
                            </v-btn>
                        </Link>
                    </v-card-title>
                    <v-card-text>
                        <v-alert v-if="flash?.success" type="success" variant="tonal" class="mb-4" dismissible>
                            {{ flash.success }}
                        </v-alert>
                        <v-table v-if="workspaces?.length">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="ws in workspaces" :key="ws.id">
                                    <td>{{ ws.name }}</td>
                                    <td>{{ ws.slug }}</td>
                                    <td>
                                        <Link
                                            v-if="tenant"
                                            :href="route('workspaces.show', { tenant: tenantEntry(tenant), workspace: ws.id })"
                                        >
                                            <v-btn variant="text" size="small">
                                                View
                                            </v-btn>
                                        </Link>
                                        <Link
                                            v-if="tenant"
                                            :href="route('workspaces.edit', { tenant: tenantEntry(tenant), workspace: ws.id })"
                                        >
                                            <v-btn variant="text" size="small">
                                                Edit
                                            </v-btn>
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                        <v-alert v-else type="info" variant="tonal">
                            No workspaces yet. Create one to get started.
                        </v-alert>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { tenantEntry } from '@/support/tenantEntry';

defineProps({
    tenant: Object,
    workspaces: Array,
    flash: Object,
});
</script>

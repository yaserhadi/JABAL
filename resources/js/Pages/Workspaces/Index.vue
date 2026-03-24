<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-h5 pa-4 d-flex justify-space-between align-center">
                        <span>Workspaces</span>
                        <v-btn
                            v-if="tenant"
                            color="primary"
                            :to="route('workspaces.create', { tenant: tenant.id })"
                        >
                            Create Workspace
                        </v-btn>
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
                                        <v-btn
                                            v-if="tenant"
                                            variant="text"
                                            size="small"
                                            :to="route('workspaces.show', { tenant: tenant.id, workspace: ws.id })"
                                        >
                                            View
                                        </v-btn>
                                        <v-btn
                                            v-if="tenant"
                                            variant="text"
                                            size="small"
                                            :to="route('workspaces.edit', { tenant: tenant.id, workspace: ws.id })"
                                        >
                                            Edit
                                        </v-btn>
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
import { route } from 'ziggy-js';

defineProps({
    tenant: Object,
    workspaces: Array,
    flash: Object,
});
</script>

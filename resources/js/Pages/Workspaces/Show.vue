<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-h5 pa-4 d-flex justify-space-between align-center">
                        <span>{{ workspace?.name }}</span>
                        <div v-if="tenant">
                            <v-btn
                                variant="text"
                                size="small"
                                :to="route('workspaces.edit', { tenant: tenant.id, workspace: workspace?.id })"
                            >
                                Edit
                            </v-btn>
                        </div>
                    </v-card-title>
                    <v-card-text>
                        <v-alert v-if="flash?.success" type="success" variant="tonal" class="mb-4" dismissible>
                            {{ flash.success }}
                        </v-alert>
                        <dl>
                            <dt class="text-caption">Slug</dt>
                            <dd>{{ workspace?.slug }}</dd>
                        </dl>
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
    workspace: Object,
    flash: Object,
});
</script>

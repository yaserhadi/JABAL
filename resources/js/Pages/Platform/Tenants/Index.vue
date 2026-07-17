<template>
    <PlatformLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-h5 pa-4 d-flex justify-space-between align-center flex-wrap ga-2">
                        <span>Tenants</span>
                        <Link :href="route('platform.tenants.create')">
                            <v-btn color="primary">Create Tenant</v-btn>
                        </Link>
                    </v-card-title>
                    <v-card-text>
                        <v-row class="mb-4" dense>
                            <v-col cols="12" md="4">
                                <v-text-field
                                    v-model="localFilters.search"
                                    label="Search name or handle"
                                    density="compact"
                                    hide-details
                                    clearable
                                    @keyup.enter="applyFilters"
                                />
                            </v-col>
                            <v-col cols="12" md="2">
                                <v-select
                                    v-model="localFilters.status"
                                    :items="statusOptions"
                                    label="Lifecycle"
                                    density="compact"
                                    hide-details
                                    clearable
                                />
                            </v-col>
                            <v-col cols="12" md="2">
                                <v-select
                                    v-model="localFilters.isolation_level"
                                    :items="isolationOptions"
                                    label="Isolation"
                                    density="compact"
                                    hide-details
                                    clearable
                                />
                            </v-col>
                            <v-col cols="12" md="2">
                                <v-select
                                    v-model="localFilters.provisioning_status"
                                    :items="provisioningOptions"
                                    label="Provisioning"
                                    density="compact"
                                    hide-details
                                    clearable
                                />
                            </v-col>
                            <v-col cols="12" md="2" class="d-flex align-center">
                                <v-btn color="primary" variant="tonal" @click="applyFilters">Filter</v-btn>
                            </v-col>
                        </v-row>

                        <v-table v-if="tenants?.data?.length">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Handle</th>
                                    <th>Lifecycle</th>
                                    <th>Provisioning</th>
                                    <th>Isolation</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in tenants.data" :key="row.id">
                                    <td>{{ row.name }}</td>
                                    <td>
                                        <code>{{ row.handle }}</code>
                                        <div class="text-caption text-medium-emphasis">{{ row.entry_url }}</div>
                                    </td>
                                    <td>
                                        <v-chip size="small" variant="tonal">{{ row.lifecycle_status }}</v-chip>
                                    </td>
                                    <td>
                                        <v-chip
                                            size="small"
                                            :color="row.provisioning_status === 'completed' ? 'success' : 'warning'"
                                            variant="tonal"
                                        >
                                            {{ row.provisioning_status }}
                                        </v-chip>
                                    </td>
                                    <td>{{ row.isolation_level }}</td>
                                    <td>
                                        <Link :href="route('platform.tenants.show', row.id)">
                                            <v-btn variant="text" size="small">View</v-btn>
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                        <v-alert v-else type="info" variant="tonal">No tenants match these filters.</v-alert>

                        <div v-if="tenants?.last_page > 1" class="d-flex justify-center mt-4">
                            <v-pagination
                                :model-value="tenants.current_page"
                                :length="tenants.last_page"
                                @update:model-value="goPage"
                            />
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </PlatformLayout>
</template>

<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps({
    tenants: Object,
    filters: Object,
});

const localFilters = reactive({
    search: props.filters?.search || '',
    status: props.filters?.status || null,
    isolation_level: props.filters?.isolation_level || null,
    provisioning_status: props.filters?.provisioning_status || null,
});

const statusOptions = ['active', 'suspended'];
const isolationOptions = ['shared', 'database'];
const provisioningOptions = [
    { title: 'Completed', value: 'completed' },
    { title: 'Action required', value: 'action_required' },
];

const applyFilters = () => {
    router.get(route('platform.tenants.index'), {
        search: localFilters.search || undefined,
        status: localFilters.status || undefined,
        isolation_level: localFilters.isolation_level || undefined,
        provisioning_status: localFilters.provisioning_status || undefined,
    }, { preserveState: true, replace: true });
};

const goPage = (page) => {
    router.get(route('platform.tenants.index'), {
        ...localFilters,
        page,
    }, { preserveState: true });
};
</script>

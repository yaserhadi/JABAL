<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-h5 pa-4">
                        Audit Logs
                    </v-card-title>
                    <v-card-subtitle class="px-4">
                        Track all system changes and user activities
                    </v-card-subtitle>
                    <v-card-text>
                        <!-- Filters -->
                        <v-expansion-panels class="mb-4">
                            <v-expansion-panel>
                                <v-expansion-panel-title>
                                    <v-icon start>mdi-filter</v-icon>
                                    Filters
                                </v-expansion-panel-title>
                                <v-expansion-panel-text>
                                    <v-row>
                                        <v-col cols="12" md="3">
                                            <v-select
                                                v-model="filterForm.event"
                                                label="Event Type"
                                                :items="['created', 'updated', 'deleted']"
                                                clearable
                                            />
                                        </v-col>
                                        <v-col cols="12" md="3">
                                            <v-text-field
                                                v-model="filterForm.auditable_type"
                                                label="Resource Type"
                                                clearable
                                            />
                                        </v-col>
                                        <v-col cols="12" md="3">
                                            <v-text-field
                                                v-model="filterForm.date_from"
                                                label="Date From"
                                                type="date"
                                                clearable
                                            />
                                        </v-col>
                                        <v-col cols="12" md="3">
                                            <v-text-field
                                                v-model="filterForm.date_to"
                                                label="Date To"
                                                type="date"
                                                clearable
                                            />
                                        </v-col>
                                        <v-col cols="12">
                                            <v-btn color="primary" @click="applyFilters">
                                                Apply Filters
                                            </v-btn>
                                            <v-btn variant="text" @click="clearFilters" class="ml-2">
                                                Clear
                                            </v-btn>
                                        </v-col>
                                    </v-row>
                                </v-expansion-panel-text>
                            </v-expansion-panel>
                        </v-expansion-panels>

                        <!-- Audit logs table -->
                        <v-table>
                            <thead>
                                <tr>
                                    <th>Event</th>
                                    <th>Resource</th>
                                    <th>User</th>
                                    <th>Changes</th>
                                    <th>IP Address</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="log in logs.data" :key="log.id">
                                    <td>
                                        <v-chip :color="getEventColor(log.event)" size="small">
                                            {{ log.event }}
                                        </v-chip>
                                    </td>
                                    <td>
                                        <div class="text-caption">{{ log.auditable_type }}</div>
                                        <div class="text-caption text-grey">{{ log.auditable_id }}</div>
                                    </td>
                                    <td>
                                        <div v-if="log.user">
                                            <div>{{ log.user.name }}</div>
                                            <div class="text-caption text-grey">{{ log.user.email }}</div>
                                        </div>
                                        <div v-else class="text-caption text-grey">{{ log.actor_type }}</div>
                                    </td>
                                    <td>
                                        <div class="text-caption">{{ log.changes_summary || 'No changes' }}</div>
                                    </td>
                                    <td>{{ log.ip_address }}</td>
                                    <td>{{ log.created_at }}</td>
                                </tr>
                            </tbody>
                        </v-table>

                        <!-- Pagination -->
                        <div class="d-flex justify-center mt-4" v-if="logs.meta.last_page > 1">
                            <v-pagination
                                :length="logs.meta.last_page"
                                v-model="currentPage"
                                @update:model-value="changePage"
                            />
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    logs: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const currentPage = ref(props.logs.meta.current_page);

const filterForm = ref({
    event: props.filters.event || null,
    auditable_type: props.filters.auditable_type || null,
    date_from: props.filters.date_from || null,
    date_to: props.filters.date_to || null,
});

const getEventColor = (event) => {
    switch (event) {
        case 'created':
            return 'success';
        case 'updated':
            return 'info';
        case 'deleted':
            return 'error';
        default:
            return 'default';
    }
};

const applyFilters = () => {
    router.get(route('audit.index'), filterForm.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    filterForm.value = {
        event: null,
        auditable_type: null,
        date_from: null,
        date_to: null,
    };
    router.get(route('audit.index'), {}, {
        preserveState: true,
        preserveScroll: true,
    });
};

const changePage = (page) => {
    router.get(route('audit.index'), { ...filterForm.value, page }, {
        preserveState: true,
        preserveScroll: true,
    });
};
</script>

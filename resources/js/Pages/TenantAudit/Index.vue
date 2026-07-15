<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-h5 pa-4">Activity timeline</v-card-title>
                    <v-card-subtitle class="px-4 pb-2">
                        A chronological record of membership and invitation activity in this tenant.
                    </v-card-subtitle>
                    <v-card-text>
                        <v-expansion-panels class="mb-4">
                            <v-expansion-panel>
                                <v-expansion-panel-title>
                                    <v-icon start>mdi-filter</v-icon>
                                    Filters
                                </v-expansion-panel-title>
                                <v-expansion-panel-text>
                                    <v-row>
                                        <v-col cols="12" md="4">
                                            <v-select
                                                v-model="filterForm.event"
                                                label="Event type"
                                                :items="eventOptions"
                                                item-title="label"
                                                item-value="value"
                                                clearable
                                            />
                                        </v-col>
                                        <v-col cols="12" md="4">
                                            <v-text-field
                                                v-model="filterForm.date_from"
                                                label="From date"
                                                type="date"
                                                clearable
                                            />
                                        </v-col>
                                        <v-col cols="12" md="4">
                                            <v-text-field
                                                v-model="filterForm.date_to"
                                                label="To date"
                                                type="date"
                                                clearable
                                            />
                                        </v-col>
                                        <v-col cols="12">
                                            <v-btn color="primary" @click="applyFilters">Apply</v-btn>
                                            <v-btn variant="text" class="ml-2" @click="clearFilters">Clear</v-btn>
                                        </v-col>
                                    </v-row>
                                </v-expansion-panel-text>
                            </v-expansion-panel>
                        </v-expansion-panels>

                        <v-alert v-if="logs.data.length === 0" type="info" variant="tonal" class="mb-4">
                            No activity recorded yet for the selected filters.
                        </v-alert>

                        <v-table v-else>
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Event</th>
                                    <th>Actor</th>
                                    <th>Target</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="entry in logs.data" :key="entry.id">
                                    <td class="text-caption text-no-wrap">{{ formatWhen(entry.occurred_at) }}</td>
                                    <td>
                                        <v-chip :color="eventColor(entry.event)" size="small" variant="tonal">
                                            {{ entry.event_label }}
                                        </v-chip>
                                    </td>
                                    <td>
                                        <div>{{ entry.actor.label }}</div>
                                        <div v-if="entry.actor.email" class="text-caption text-grey">
                                            {{ entry.actor.email }}
                                        </div>
                                    </td>
                                    <td>
                                        <div>{{ entry.target.label }}</div>
                                        <div v-if="entry.target.email" class="text-caption text-grey">
                                            {{ entry.target.email }}
                                        </div>
                                    </td>
                                    <td class="text-caption">{{ formatDetails(entry) }}</td>
                                </tr>
                            </tbody>
                        </v-table>

                        <div v-if="logs.meta.last_page > 1" class="d-flex justify-center mt-4">
                            <v-pagination
                                v-model="currentPage"
                                :length="logs.meta.last_page"
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
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { tenantEntry } from '@/support/tenantEntry';

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
    logs: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    eventTypes: {
        type: Array,
        default: () => [],
    },
});

const currentPage = ref(props.logs.meta.current_page);

const filterForm = ref({
    event: props.filters.event || null,
    date_from: props.filters.date_from || null,
    date_to: props.filters.date_to || null,
});

const eventOptions = computed(() =>
    props.eventTypes.map((value) => ({ value, label: humanizeEvent(value) }))
);

const humanizeEvent = (event) => {
    const labels = {
        'tenant_member.invited': 'Invitation sent',
        'tenant_member.invitation_reissued': 'Invitation resent',
        'tenant_member.invitation_accepted': 'Invitation accepted',
        'tenant_member.invitation_revoked': 'Invitation revoked',
        'tenant_member.removed': 'Member removed',
        'tenant_member.restored': 'Member restored',
        'tenant_member.permanently_deleted': 'Member permanently deleted',
        'tenant_member.role_changed': 'Role changed',
        'tenant_member.suspended': 'Member suspended',
        'tenant_member.activated': 'Member activated',
        'tenant_member.ownership_transferred': 'Ownership transferred',
    };

    return labels[event] || event;
};

const eventColor = (event) => {
    if (event.includes('invitation') || event.includes('invited')) {
        return 'primary';
    }
    if (event.includes('removed') || event.includes('revoked') || event.includes('suspended')) {
        return 'error';
    }
    if (event.includes('activated') || event.includes('accepted')) {
        return 'success';
    }

    return 'default';
};

const formatWhen = (iso) => {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString();
};

const formatDetails = (entry) => {
    const parts = [];
    const d = entry.details || {};

    if (d.from_role && d.to_role) {
        parts.push(`${d.from_role} → ${d.to_role}`);
    } else if (d.role) {
        parts.push(`Role: ${d.role}`);
    }
    if (d.expires_at) {
        parts.push(`Expires: ${new Date(d.expires_at).toLocaleString()}`);
    }
    if (entry.metadata?.ip) {
        parts.push(`IP: ${entry.metadata.ip}`);
    }

    return parts.length ? parts.join(' · ') : '—';
};

const auditRouteParams = () => ({
    tenant: tenantEntry(props.tenant),
    ...filterForm.value,
});

const applyFilters = () => {
    router.get(route('tenant.audit.index', auditRouteParams()), {}, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    filterForm.value = { event: null, date_from: null, date_to: null };
    router.get(route('tenant.audit.index', { tenant: tenantEntry(props.tenant) }), {}, {
        preserveState: true,
        preserveScroll: true,
    });
};

const changePage = (page) => {
    router.get(route('tenant.audit.index', { ...auditRouteParams(), page }), {}, {
        preserveState: true,
        preserveScroll: true,
    });
};
</script>

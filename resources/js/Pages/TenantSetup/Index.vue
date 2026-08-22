<template>
    <v-app>
        <v-main>
            <v-container class="py-6">
                <h1 class="text-h5 mb-2">Tenant setup</h1>
                <p class="mb-2">Tenant status (Active ≠ Ready): <strong>{{ tenant_status }}</strong></p>
                <p class="mb-4">
                    Operationally ready:
                    <strong>{{ readiness.ready ? 'Yes' : 'No' }}</strong>
                    <span v-if="setup_grandfathered"> (grandfathered)</span>
                </p>

                <v-alert v-if="!readiness.ready" type="warning" class="mb-4" variant="tonal">
                    Blocking incomplete: {{ readiness.blocking_incomplete.join(', ') || '—' }}
                </v-alert>

                <v-table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in readiness.applicable" :key="item.code">
                            <td>{{ item.title }} ({{ item.code }})</td>
                            <td>{{ item.requirement_type }}</td>
                            <td>{{ item.status }}</td>
                            <td>
                                <v-btn
                                    v-if="item.status === 'pending'"
                                    size="small"
                                    @click="complete(item.code)"
                                >
                                    Complete
                                </v-btn>
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps({
    tenant: { type: Object, required: true },
    readiness: { type: Object, required: true },
    tenant_status: { type: String, required: true },
    setup_grandfathered: { type: Boolean, default: false },
});

const complete = (setupCode) => {
    router.post(route('tenant.setup.complete', props.tenant.entryKey || props.tenant.id), {
        setup_code: setupCode,
    });
};
</script>

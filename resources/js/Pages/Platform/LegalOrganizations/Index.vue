<template>
    <PlatformLayout>
        <h1 class="text-h5 mb-4">Legal Organizations</h1>
        <p class="text-body-2 mb-6">Customer Legal Organization ≠ Tenant.</p>

        <v-table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Tenants</th>
                    <th>Business Owners</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="org in legal_organizations" :key="org.id">
                    <td>{{ org.name }}</td>
                    <td>{{ org.status }}</td>
                    <td>{{ org.tenants_count }}</td>
                    <td>{{ org.business_owners_count }}</td>
                    <td>
                        <Link :href="route('platform.legal-organizations.show', org.id)">View</Link>
                    </td>
                </tr>
            </tbody>
        </v-table>
    </PlatformLayout>
</template>

<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue';
import { Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps({
    legal_organizations: { type: Array, required: true },
});
</script>

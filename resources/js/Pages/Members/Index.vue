<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-h5 pa-4">Tenant Members</v-card-title>
                    <v-card-text>
                        <v-alert v-if="flash?.success" type="success" variant="tonal" class="mb-4" dismissible>
                            {{ flash.success }}
                        </v-alert>
                        <v-table v-if="members?.length">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Membership</th>
                                    <th>Status</th>
                                    <th>Roles</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in members" :key="m.id">
                                    <td>{{ m.user?.name }}</td>
                                    <td>{{ m.user?.email }}</td>
                                    <td>{{ m.membership_type }}</td>
                                    <td>{{ m.status }}</td>
                                    <td>{{ m.roles?.join(', ') || '-' }}</td>
                                    <td>
                                        <v-btn
                                            v-if="tenant && m.status === 'active'"
                                            variant="text"
                                            size="small"
                                            @click="openSuspend(m)"
                                        >
                                            Suspend
                                        </v-btn>
                                        <v-btn
                                            v-else-if="tenant && m.status === 'suspended'"
                                            variant="text"
                                            size="small"
                                            @click="openActivate(m)"
                                        >
                                            Activate
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                        <v-alert v-else type="info" variant="tonal">
                            No members found.
                        </v-alert>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps({
    tenant: Object,
    members: Array,
    flash: Object,
});

const openSuspend = (member) => {
    if (!confirm(`Suspend ${member.user?.name}?`)) return;
    if (!props.tenant) return;
    router.post(route('members.suspend', { tenant: props.tenant.id, user: member.user_id }));
};

const openActivate = (member) => {
    if (!props.tenant) return;
    router.post(route('members.activate', { tenant: props.tenant.id, user: member.user_id }));
};
</script>

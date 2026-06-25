<template>
    <AppLayout>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="d-flex align-center justify-space-between pa-4">
                        <span class="text-h5">Tenant Members</span>
                        <v-btn
                            v-if="tenant"
                            color="primary"
                            prepend-icon="mdi-account-plus"
                            @click="inviteDialog = true"
                        >
                            Invite member
                        </v-btn>
                    </v-card-title>
                    <v-card-text>
                        <v-alert v-if="flash?.success" type="success" variant="tonal" class="mb-4" dismissible>
                            {{ flash.success }}
                        </v-alert>
                        <v-alert v-if="flash?.inviteUrl" type="info" variant="tonal" class="mb-4">
                            <div class="mb-2">Share this invitation link (one-time):</div>
                            <div class="d-flex align-center ga-2">
                                <code class="flex-grow-1 text-truncate">{{ flash.inviteUrl }}</code>
                                <v-btn size="small" variant="outlined" @click="copyUrl(flash.inviteUrl)">Copy</v-btn>
                            </div>
                        </v-alert>

                        <v-card v-if="pendingInvitations?.length" variant="outlined" class="mb-4">
                            <v-card-title class="text-subtitle-1">Pending invitations</v-card-title>
                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Expires</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="inv in pendingInvitations" :key="inv.id">
                                        <td>{{ inv.email }}</td>
                                        <td>{{ inv.role }}</td>
                                        <td>{{ inv.expires_at }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card>

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
                                    <td class="d-flex flex-wrap ga-1">
                                        <v-btn
                                            v-if="tenant && m.status === 'active' && m.user_id !== currentUserId"
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
                                        <v-btn
                                            v-if="tenant && m.user_id !== currentUserId && !(actorIsOwner && m.membership_type === 'owner' && m.status === 'active')"
                                            variant="text"
                                            size="small"
                                            color="error"
                                            @click="openRemove(m)"
                                        >
                                            Remove
                                        </v-btn>
                                        <v-btn
                                            v-if="actorIsOwner && m.status === 'active' && !m.membership_type?.includes('owner') && m.user_id !== currentUserId"
                                            variant="text"
                                            size="small"
                                            @click="openTransfer(m)"
                                        >
                                            Transfer ownership
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

        <v-dialog v-model="inviteDialog" max-width="480">
            <v-card title="Invite member">
                <v-card-text>
                    <v-text-field v-model="inviteForm.email" label="Email" type="email" />
                    <v-select
                        v-model="inviteForm.role"
                        :items="roleOptions"
                        label="Role"
                        item-title="label"
                        item-value="value"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="inviteDialog = false">Cancel</v-btn>
                    <v-btn color="primary" :loading="inviteForm.processing" @click="submitInvite">Send invite</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-snackbar v-model="copySnackbar" timeout="2000">Copied to clipboard</v-snackbar>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

const props = defineProps({
    tenant: Object,
    members: Array,
    pendingInvitations: Array,
    actorIsOwner: Boolean,
    flash: Object,
});

const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id);

const inviteDialog = ref(false);
const copySnackbar = ref(false);
const inviteForm = useForm({
    email: '',
    role: 'member',
});

const roleOptions = [
    { label: 'Member', value: 'member' },
    { label: 'Tenant admin', value: 'tenant-admin' },
];

const copyUrl = async (url) => {
    await navigator.clipboard.writeText(url);
    copySnackbar.value = true;
};

const submitInvite = () => {
    if (!props.tenant) return;
    inviteForm.post(route('members.invite', { tenant: props.tenant.id }), {
        preserveScroll: true,
        onSuccess: () => {
            inviteDialog.value = false;
            inviteForm.reset();
        },
    });
};

const openSuspend = (member) => {
    if (!confirm(`Suspend ${member.user?.name}?`)) return;
    if (!props.tenant) return;
    router.post(route('members.suspend', { tenant: props.tenant.id, user: member.user_id }));
};

const openActivate = (member) => {
    if (!props.tenant) return;
    router.post(route('members.activate', { tenant: props.tenant.id, user: member.user_id }));
};

const openRemove = (member) => {
    if (!confirm(`Remove ${member.user?.name} from this tenant?`)) return;
    if (!props.tenant) return;
    router.delete(route('members.remove', { tenant: props.tenant.id, user: member.user_id }));
};

const openTransfer = (member) => {
    if (!confirm(`Transfer ownership to ${member.user?.name}? You will become a member.`)) return;
    if (!props.tenant) return;
    router.post(route('members.transfer-ownership', { tenant: props.tenant.id, user: member.user_id }));
};
</script>

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
                            New user
                        </v-btn>
                    </v-card-title>
                    <v-card-text>
                        <v-alert v-if="flash?.success" type="success" variant="tonal" class="mb-4" dismissible>
                            {{ flash.success }}
                        </v-alert>
                        <v-alert v-if="flash?.inviteUrl" type="info" variant="tonal" class="mb-4">
                            <div class="mb-2">You can also share this invitation link manually:</div>
                            <div class="d-flex align-center ga-2">
                                <code class="flex-grow-1 text-truncate">{{ flash.inviteUrl }}</code>
                                <v-btn size="small" variant="outlined" @click="copyUrl(flash.inviteUrl)">Copy</v-btn>
                            </div>
                        </v-alert>

                        <v-tabs v-model="activeTab" class="mb-4">
                            <v-tab value="active">Active members</v-tab>
                            <v-tab value="pending">
                                <span>Pending invitations</span>
                                <v-badge
                                    v-if="pendingCount > 0"
                                    :content="pendingCount"
                                    color="primary"
                                    inline
                                    class="ms-2"
                                />
                            </v-tab>
                            <v-tab v-if="showRemovedTab" value="removed">
                                <span>Removed members</span>
                                <v-badge
                                    v-if="removedCount > 0"
                                    :content="removedCount"
                                    color="error"
                                    inline
                                    class="ms-2"
                                />
                            </v-tab>
                        </v-tabs>

                        <v-window v-model="activeTab">
                            <v-window-item value="active">
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
                            </v-window-item>

                            <v-window-item value="pending">
                                <v-table v-if="pendingInvitations?.length" density="compact">
                                    <thead>
                                        <tr>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Expires</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="inv in pendingInvitations" :key="inv.id">
                                            <td>{{ inv.email }}</td>
                                            <td>{{ inv.role }}</td>
                                            <td>{{ inv.expires_at }}</td>
                                            <td class="d-flex flex-wrap ga-1">
                                                <v-btn
                                                    variant="text"
                                                    size="small"
                                                    @click="openResend(inv)"
                                                >
                                                    Resend
                                                </v-btn>
                                                <v-btn
                                                    variant="text"
                                                    size="small"
                                                    color="error"
                                                    @click="openRevoke(inv)"
                                                >
                                                    Revoke
                                                </v-btn>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                                <v-alert v-else type="info" variant="tonal">
                                    No pending invitations.
                                </v-alert>
                            </v-window-item>

                            <v-window-item v-if="showRemovedTab" value="removed">
                                <v-table v-if="removedMembers?.length" density="compact">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Removed at</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="m in removedMembers" :key="m.id">
                                            <td>{{ m.user?.name }}</td>
                                            <td>{{ m.user?.email }}</td>
                                            <td>{{ m.removed_at || '-' }}</td>
                                            <td class="d-flex flex-wrap ga-1">
                                                <v-btn
                                                    variant="text"
                                                    size="small"
                                                    color="primary"
                                                    @click="openRestore(m)"
                                                >
                                                    Restore
                                                </v-btn>
                                                <v-btn
                                                    variant="text"
                                                    size="small"
                                                    color="error"
                                                    @click="openDeleteForever(m)"
                                                >
                                                    Delete forever
                                                </v-btn>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                                <v-alert v-else type="info" variant="tonal">
                                    No removed members.
                                </v-alert>
                            </v-window-item>
                        </v-window>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-dialog v-model="inviteDialog" max-width="520">
            <v-card title="New user">
                <v-card-subtitle class="px-4">
                    Create User first, then send a 24-hour account-completion invite.
                </v-card-subtitle>
                <v-card-text>
                    <v-alert
                        v-if="suspendedMemberForInviteShortcut"
                        type="warning"
                        variant="tonal"
                        class="mb-4"
                    >
                        This email belongs to a suspended member. Activate them to restore access.
                        <v-btn
                            class="mt-2"
                            size="small"
                            variant="outlined"
                            @click="activateSuspendedFromInvite"
                        >
                            Activate member
                        </v-btn>
                    </v-alert>
                    <v-text-field
                        v-model="inviteForm.first_name"
                        label="First name"
                        :error-messages="inviteForm.errors.first_name"
                    />
                    <v-text-field
                        v-model="inviteForm.last_name"
                        label="Last name"
                        :error-messages="inviteForm.errors.last_name"
                    />
                    <v-text-field
                        v-model="inviteForm.email"
                        label="Email"
                        type="email"
                        :error-messages="inviteForm.errors.email"
                    />
                    <v-select
                        v-model="inviteForm.role"
                        :items="roleOptions"
                        label="Role"
                        item-title="label"
                        item-value="value"
                        :error-messages="inviteForm.errors.role"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="inviteDialog = false">Cancel</v-btn>
                    <v-btn color="primary" :loading="inviteForm.processing" @click="submitInvite">
                        Create user and send invite
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-snackbar v-model="copySnackbar" timeout="2000">Copied to clipboard</v-snackbar>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import { tenantRouteParams } from '@/support/tenantEntry';

const props = defineProps({
    tenant: Object,
    members: Array,
    removedMembers: Array,
    memberRemovalMode: String,
    pendingInvitations: Array,
    actorIsOwner: Boolean,
    flash: Object,
});

const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id);
const pendingCount = computed(() => props.pendingInvitations?.length ?? 0);
const removedCount = computed(() => props.removedMembers?.length ?? 0);
const showRemovedTab = computed(
    () => props.memberRemovalMode === 'reversible' || removedCount.value > 0,
);

const activeTab = ref('active');

watch(showRemovedTab, (visible) => {
    if (!visible && activeTab.value === 'removed') {
        activeTab.value = 'active';
    }
});
const inviteDialog = ref(false);
const copySnackbar = ref(false);
const inviteForm = useForm({
    first_name: '',
    last_name: '',
    email: '',
    role: 'member',
});

const roleOptions = [
    { label: 'Member', value: 'member' },
    { label: 'Tenant admin', value: 'tenant-admin' },
];

const suspendedMemberForInviteShortcut = computed(() => {
    if (!inviteForm.errors.email || !inviteForm.email) {
        return null;
    }

    const normalized = inviteForm.email.trim().toLowerCase();

    return props.members?.find(
        (member) =>
            member.status === 'suspended' &&
            (member.user?.email ?? '').trim().toLowerCase() === normalized,
    ) ?? null;
});

const activateSuspendedFromInvite = () => {
    const member = suspendedMemberForInviteShortcut.value;
    if (!member) {
        return;
    }

    inviteDialog.value = false;
    openActivate(member);
};

const copyUrl = async (url) => {
    await navigator.clipboard.writeText(url);
    copySnackbar.value = true;
};

const submitInvite = () => {
    if (!props.tenant) return;
    inviteForm.post(route('members.invite', tenantRouteParams(props.tenant)), {
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
    router.post(route('members.suspend', tenantRouteParams(props.tenant, { user: member.user_id  })));
};

const openActivate = (member) => {
    if (!props.tenant) return;
    router.post(route('members.activate', tenantRouteParams(props.tenant, { user: member.user_id  })));
};

const openRemove = (member) => {
    const modeHint = props.memberRemovalMode === 'reversible'
        ? ' They will appear on the Removed members tab and can be restored.'
        : ' This permanently removes their membership.';
    if (!confirm(`Remove ${member.user?.name} from this tenant?${modeHint}`)) return;
    if (!props.tenant) return;
    router.delete(route('members.remove', tenantRouteParams(props.tenant, { user: member.user_id  })));
};

const openRestore = (member) => {
    if (!confirm(`Restore ${member.user?.name} as a standard member? Previous roles will not be restored.`)) return;
    if (!props.tenant) return;
    router.post(route('members.restore', tenantRouteParams(props.tenant, { user: member.user_id  })), {}, {
        preserveScroll: true,
    });
};

const openDeleteForever = (member) => {
    if (!confirm(`Permanently delete ${member.user?.name}? This cannot be undone.`)) return;
    if (!props.tenant) return;
    router.delete(route('members.delete-forever', tenantRouteParams(props.tenant, { user: member.user_id  })), {
        preserveScroll: true,
    });
};

const openTransfer = (member) => {
    if (!confirm(`Transfer ownership to ${member.user?.name}? You will become a member.`)) return;
    if (!props.tenant) return;
    router.post(route('members.transfer-ownership', tenantRouteParams(props.tenant, { user: member.user_id  })));
};

const openRevoke = (invitation) => {
    if (!confirm(`Revoke invitation for ${invitation.email}?`)) return;
    if (!props.tenant) return;
    router.delete(route('members.revoke-invitation', tenantRouteParams(props.tenant, { invitation: invitation.id  })), {
        preserveScroll: true,
    });
};

const openResend = (invitation) => {
    if (!confirm(`Resend invitation email to ${invitation.email}? The previous link will stop working.`)) return;
    if (!props.tenant) return;
    router.post(route('members.resend-invitation', tenantRouteParams(props.tenant, { invitation: invitation.id  })), {}, {
        preserveScroll: true,
    });
};
</script>

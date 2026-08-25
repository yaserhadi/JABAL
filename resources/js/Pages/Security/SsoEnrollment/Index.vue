<template>
    <AppLayout>
        <v-container class="py-8" style="max-width: 640px">
            <v-card>
                <v-card-title>Workforce SSO enrollments</v-card-title>
                <v-card-text>
                    <v-form
                        v-if="canConfigureSso"
                        class="mb-6"
                        @submit.prevent="submit"
                    >
                        <v-autocomplete
                            v-model="selectedUserId"
                            :items="candidateItems"
                            item-title="label"
                            item-value="id"
                            label="Intended user"
                            :error-messages="form.errors.intended_user_id"
                            clearable
                            required
                            @update:model-value="onCandidateSelected"
                        />
                        <v-text-field
                            v-model="form.delivery_email"
                            label="Delivery email (notification only)"
                            type="email"
                            :error-messages="form.errors.delivery_email"
                            required
                        />
                        <v-btn type="submit" color="primary" :loading="form.processing">
                            Issue invitation
                        </v-btn>
                    </v-form>
                    <v-alert
                        v-else
                        type="info"
                        variant="tonal"
                        density="compact"
                        class="mb-6"
                    >
                        You can view enrollment invitations but cannot issue or cancel them.
                    </v-alert>

                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>Delivery email</th>
                                <th>Status</th>
                                <th>Expires</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in invitations" :key="row.id">
                                <td>{{ row.delivery_email }}</td>
                                <td>{{ row.pending ? 'pending' : (row.consumed_at ? 'consumed' : 'cancelled') }}</td>
                                <td>{{ row.expires_at }}</td>
                                <td>
                                    <v-btn
                                        v-if="canConfigureSso && row.pending"
                                        size="small"
                                        variant="text"
                                        color="error"
                                        @click="cancel(row.id)"
                                    >
                                        Cancel
                                    </v-btn>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card-text>
            </v-card>
        </v-container>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { computed, ref } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    invitations: { type: Array, default: () => [] },
    enrollmentCandidates: { type: Array, default: () => [] },
});

const page = usePage();
const canConfigureSso = computed(() => Boolean(page.props.tenant_ui_permissions?.canConfigureSso));

const candidateItems = computed(() =>
    (props.enrollmentCandidates ?? []).map((c) => ({
        id: c.id,
        label: `${c.name || 'User'} — ${c.email || ''}`.trim(),
        email: c.email || '',
    })),
);

const selectedUserId = ref(null);

const form = useForm({
    intended_user_id: '',
    delivery_email: '',
});

const onCandidateSelected = (id) => {
    form.intended_user_id = id || '';
    if (!id) {
        return;
    }
    const match = (props.enrollmentCandidates ?? []).find((c) => c.id === id);
    if (match?.email) {
        form.delivery_email = match.email;
    }
};

const submit = () => {
    form.post('/security/sso/enrollments');
};

const cancel = (id) => {
    router.delete(`/security/sso/enrollments/${id}`);
};
</script>

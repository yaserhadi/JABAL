<template>
    <v-app>
        <v-main>
            <v-container class="py-8" style="max-width: 640px">
                <v-card>
                    <v-card-title>Workforce SSO enrollments</v-card-title>
                    <v-card-text>
                        <v-form class="mb-6" @submit.prevent="submit">
                            <v-text-field
                                v-model="form.intended_user_id"
                                label="Intended user ID"
                                :error-messages="form.errors.intended_user_id"
                                required
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
                                            v-if="row.pending"
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
        </v-main>
    </v-app>
</template>

<script setup>
import { useForm, router } from '@inertiajs/vue3';

defineProps({
    invitations: { type: Array, default: () => [] },
});

const form = useForm({
    intended_user_id: '',
    delivery_email: '',
});

const submit = () => {
    form.post('/security/sso/enrollments');
};

const cancel = (id) => {
    router.delete(`/security/sso/enrollments/${id}`);
};
</script>

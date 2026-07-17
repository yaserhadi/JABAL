<template>
    <PlatformLayout>
        <v-row>
            <v-col cols="12" md="6" offset-md="3">
                <v-card>
                    <v-card-title class="text-h5 pa-4">Edit Tenant display name</v-card-title>
                    <v-card-text>
                        <v-alert type="info" variant="tonal" class="mb-4">
                            Tenant Handle is read-only after creation:
                            <code>{{ tenant.handle }}</code>
                            · live entry <code>{{ tenant.entry_url }}</code>
                        </v-alert>
                        <v-form @submit.prevent="submit">
                            <v-text-field
                                v-model="form.name"
                                label="Display name"
                                :error-messages="form.errors.name"
                                required
                            />
                            <div class="d-flex ga-2 mt-4">
                                <v-btn type="submit" color="primary" :loading="form.processing">Save</v-btn>
                                <Link :href="route('platform.tenants.show', tenant.id)">
                                    <v-btn variant="text">Cancel</v-btn>
                                </Link>
                            </div>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </PlatformLayout>
</template>

<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps({
    tenant: { type: Object, required: true },
});

const form = useForm({
    name: props.tenant.name,
});

const submit = () => {
    form.patch(route('platform.tenants.update', props.tenant.id));
};
</script>

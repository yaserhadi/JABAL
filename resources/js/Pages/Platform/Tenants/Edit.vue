<template>
    <PlatformLayout>
        <v-row>
            <v-col cols="12" md="6" offset-md="3">
                <v-card class="mb-4">
                    <v-card-title class="text-h5 pa-4">Edit Tenant display name</v-card-title>
                    <v-card-text>
                        <v-form @submit.prevent="submitName">
                            <v-text-field
                                v-model="nameForm.name"
                                label="Display name"
                                :error-messages="nameForm.errors.name"
                                required
                            />
                            <div class="d-flex ga-2 mt-4">
                                <v-btn type="submit" color="primary" :loading="nameForm.processing">Save name</v-btn>
                                <Link :href="route('platform.tenants.show', tenant.id)">
                                    <v-btn variant="text">Cancel</v-btn>
                                </Link>
                            </div>
                        </v-form>
                    </v-card-text>
                </v-card>

                <v-card>
                    <v-card-title class="text-h5 pa-4">Rename Tenant Handle</v-card-title>
                    <v-card-text>
                        <v-alert type="warning" variant="tonal" class="mb-4">
                            Tenant ID stays the same. Current handle
                            <code>{{ tenant.handle }}</code>
                            will be retired (not immediately reusable).
                            Live entry: <code>{{ tenant.entry_url }}</code>
                        </v-alert>
                        <v-form @submit.prevent="submitHandle">
                            <v-text-field
                                v-model="handleForm.handle"
                                label="New Tenant Handle"
                                :error-messages="handleForm.errors.handle"
                                hint="lowercase letters, numbers, single hyphens"
                                persistent-hint
                                required
                            />
                            <div class="d-flex ga-2 mt-4">
                                <v-btn type="submit" color="warning" :loading="handleForm.processing">
                                    Rename handle
                                </v-btn>
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

const nameForm = useForm({
    name: props.tenant.name,
});

const handleForm = useForm({
    handle: '',
});

const submitName = () => {
    nameForm.patch(route('platform.tenants.update', props.tenant.id));
};

const submitHandle = () => {
    handleForm.post(route('platform.tenants.rename-handle', props.tenant.id));
};
</script>

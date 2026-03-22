<template>
    <AppLayout>
        <v-row>
            <v-col cols="12" md="8">
                <v-card>
                    <v-card-title class="text-h5 pa-4">Create Workspace</v-card-title>
                    <v-card-text>
                        <form @submit.prevent="submit">
                            <v-text-field
                                v-model="form.name"
                                label="Name"
                                :error-messages="form.errors.name"
                                required
                                class="mb-4"
                            />
                            <v-text-field
                                v-model="form.slug"
                                label="Slug"
                                hint="Lowercase letters, numbers, hyphens only"
                                :error-messages="form.errors.slug"
                                required
                                class="mb-4"
                            />
                            <v-btn type="submit" color="primary" :loading="form.processing">
                                Create
                            </v-btn>
                            <v-btn
                                v-if="tenant"
                                variant="text"
                                class="ml-2"
                                :to="route('workspaces.index', { tenant: tenant.id })"
                            >
                                Cancel
                            </v-btn>
                        </form>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps({
    tenant: Object,
});

const form = useForm({
    name: '',
    slug: '',
});

const submit = () => {
    if (!props.tenant) return;
    form.post(route('workspaces.store', { tenant: props.tenant.id }));
};
</script>

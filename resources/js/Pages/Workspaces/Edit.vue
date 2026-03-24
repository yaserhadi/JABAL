<template>
    <AppLayout>
        <v-row>
            <v-col cols="12" md="8">
                <v-card>
                    <v-card-title class="text-h5 pa-4">Edit Workspace</v-card-title>
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
                                Update
                            </v-btn>
                            <v-btn
                                v-if="tenant"
                                variant="text"
                                class="ml-2"
                                :to="route('workspaces.show', { tenant: tenant.id, workspace: workspace?.id })"
                            >
                                Cancel
                            </v-btn>
                            <v-btn
                                v-if="tenant && workspace"
                                color="error"
                                variant="outlined"
                                class="ml-2"
                                :loading="deleteForm.processing"
                                @click="confirmDestroy"
                            >
                                Delete
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
    workspace: Object,
});

const form = useForm({
    name: props.workspace?.name ?? '',
    slug: props.workspace?.slug ?? '',
});

const deleteForm = useForm({});

const submit = () => {
    if (!props.tenant || !props.workspace) return;
    form.put(route('workspaces.update', { tenant: props.tenant.id, workspace: props.workspace.id }));
};

const confirmDestroy = () => {
    if (!confirm('Are you sure you want to delete this workspace?')) return;
    if (!props.tenant || !props.workspace) return;
    deleteForm.delete(route('workspaces.destroy', { tenant: props.tenant.id, workspace: props.workspace.id }));
};
</script>

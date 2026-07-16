<template>
    <PlatformLayout>
        <v-row>
            <v-col cols="12" md="8" offset-md="2">
                <v-card>
                    <v-card-title class="text-h5 pa-4">Create Tenant</v-card-title>
                    <v-card-text>
                        <v-alert type="info" variant="tonal" class="mb-4">
                            Isolation mode is platform-controlled
                            (<strong>{{ default_isolation_level }}</strong>) and is not selectable here.
                        </v-alert>

                        <v-form @submit.prevent="submit">
                            <v-text-field
                                v-model="form.organization_name"
                                label="Organization / Tenant name"
                                :error-messages="form.errors.organization_name"
                                required
                            />

                            <v-text-field
                                v-model="form.handle"
                                label="Organization Address (Tenant Handle)"
                                hint="Lowercase letters, numbers, and hyphens"
                                persistent-hint
                                :error-messages="form.errors.handle"
                                required
                                @blur="checkAvailability"
                            />
                            <div class="mb-4 text-body-2">
                                Live entry path:
                                <code>/t/{{ normalizedHandle || '…' }}</code>
                            </div>
                            <v-alert
                                v-if="availability"
                                class="mb-4"
                                density="compact"
                                variant="tonal"
                                :type="availability.code === 'available' ? 'success' : 'warning'"
                            >
                                {{ availability.message }}
                            </v-alert>

                            <v-divider class="mb-4" />
                            <div class="text-subtitle-1 mb-2">Application Owner</div>
                            <v-text-field
                                v-model="form.owner_name"
                                label="Owner name"
                                :error-messages="form.errors.owner_name"
                                required
                            />
                            <v-text-field
                                v-model="form.owner_email"
                                label="Owner email"
                                type="email"
                                :error-messages="form.errors.owner_email"
                                required
                            />
                            <v-text-field
                                v-model="form.owner_password"
                                label="Owner password"
                                type="password"
                                :error-messages="form.errors.owner_password"
                                required
                            />

                            <div class="d-flex ga-2 mt-4">
                                <v-btn type="submit" color="primary" :loading="form.processing">
                                    Create Tenant
                                </v-btn>
                                <Link :href="route('platform.tenants.index')">
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
import axios from 'axios';
import { computed, ref } from 'vue';
import { route } from 'ziggy-js';

defineProps({
    default_isolation_level: { type: String, default: 'shared' },
});

const form = useForm({
    organization_name: '',
    handle: '',
    owner_name: '',
    owner_email: '',
    owner_password: '',
});

const availability = ref(null);

const normalizedHandle = computed(() => (form.handle || '').trim().toLowerCase());

const checkAvailability = async () => {
    if (!normalizedHandle.value) {
        availability.value = null;
        return;
    }
    try {
        const { data } = await axios.post(route('platform.tenants.handle-availability'), {
            handle: normalizedHandle.value,
        });
        availability.value = data;
    } catch (e) {
        availability.value = null;
    }
};

const submit = () => {
    form.handle = normalizedHandle.value;
    form.post(route('platform.tenants.store'));
};
</script>

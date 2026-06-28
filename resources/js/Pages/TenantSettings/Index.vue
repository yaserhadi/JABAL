<template>
    <AppLayout>
        <v-row>
            <v-col cols="12" md="8" lg="6">
                <v-card>
                    <v-card-title class="text-h5 pa-4">Tenant settings</v-card-title>
                    <v-card-subtitle class="px-4 pb-2">
                        Branding and defaults for this tenant (tenant admins only).
                    </v-card-subtitle>
                    <v-card-text>
                        <v-alert v-if="flash?.success" type="success" variant="tonal" class="mb-4" dismissible>
                            {{ flash.success }}
                        </v-alert>

                        <v-form @submit.prevent="submit">
                            <v-text-field
                                v-model="form.display_name"
                                label="Display name"
                                hint="Optional override shown in the app shell"
                                persistent-hint
                                class="mb-2"
                            />
                            <v-text-field
                                v-model="form.timezone"
                                label="Timezone"
                                hint="PHP timezone identifier (e.g. UTC, America/New_York)"
                                persistent-hint
                                class="mb-2"
                            />
                            <v-select
                                v-model="form.locale"
                                :items="supportedLocales"
                                label="Locale"
                                class="mb-2"
                            />
                            <v-text-field
                                v-model="form.branding_logo_url"
                                label="Logo URL"
                                hint="HTTPS URL to an image"
                                persistent-hint
                                class="mb-2"
                            />
                            <v-select
                                v-model="form.member_removal_mode"
                                :items="removalModeOptions"
                                label="Removal Mode"
                                hint="How future Remove actions behave for members"
                                persistent-hint
                                item-title="label"
                                item-value="value"
                                class="mb-4"
                            />

                            <v-btn
                                v-if="tenant_ui_permissions?.canUpdateTenantSettings"
                                color="primary"
                                type="submit"
                                :loading="form.processing"
                                :disabled="form.processing"
                            >
                                Save
                            </v-btn>
                            <v-alert v-else type="info" variant="tonal" density="compact" class="mt-2">
                                You can view these settings but cannot change them.
                            </v-alert>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';

const page = usePage();

const props = defineProps({
    tenant: { type: Object, required: true },
    settings: { type: Object, required: true },
    supportedLocales: { type: Array, default: () => ['en'] },
});

const tenant_ui_permissions = computed(() => page.props.tenant_ui_permissions);
const flash = computed(() => page.props.flash);

const form = useForm({
    display_name: props.settings.display_name ?? '',
    timezone: props.settings.timezone ?? 'UTC',
    locale: props.settings.locale ?? 'en',
    branding_logo_url: props.settings.branding_logo_url ?? '',
    member_removal_mode: props.settings.member_removal_mode ?? 'permanent',
});

const removalModeOptions = [
    { label: 'Permanent', value: 'permanent' },
    { label: 'Reversible', value: 'reversible' },
];

function submit() {
    if (!tenant_ui_permissions.value?.canUpdateTenantSettings) {
        return;
    }
    form.patch(route('tenant.settings.update', { tenant: props.tenant.id }), {
        preserveScroll: true,
    });
}
</script>

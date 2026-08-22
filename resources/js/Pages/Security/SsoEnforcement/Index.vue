<template>
    <AppLayout>
        <v-row>
            <v-col cols="12" md="10" lg="9">
                <h1 class="text-h5 mb-2">SSO Enforcement</h1>
                <p class="text-medium-emphasis mb-4">
                    Readiness accounting, exceptions, and Enforcement Readiness Gate before SSO-only.
                </p>

                <v-alert :type="gatePass ? 'success' : 'error'" variant="tonal" class="mb-4">
                    Enforcement Readiness Gate: {{ gatePass ? 'PASS' : 'FAIL' }}
                    <div v-if="gateFailures?.length" class="text-caption mt-1">
                        {{ gateFailures.join('; ') }}
                    </div>
                </v-alert>

                <v-card class="mb-4">
                    <v-card-title>Population</v-card-title>
                    <v-card-text>
                        Ready: {{ counts.ready }} · Exception: {{ counts.exception }} ·
                        Not ready: {{ counts.not_ready }} · Ineligible: {{ counts.ineligible }}
                    </v-card-text>
                </v-card>

                <v-card class="mb-4">
                    <v-card-title>Settings</v-card-title>
                    <v-card-text>
                        <v-switch v-model="settings.mandatory_sso_enrollment" label="Mandatory SSO Enrollment" color="primary" />
                        <v-select
                            v-model="settings.sso_exception_closure_mode"
                            :items="['automatic', 'manual']"
                            label="Exception closure mode"
                            class="mb-2"
                        />
                        <v-btn color="primary" :loading="settingsForm.processing" @click="saveSettings">Save settings</v-btn>
                        <p class="text-caption mt-2">Current authentication policy: {{ authenticationPolicy }}</p>
                    </v-card-text>
                </v-card>

                <v-card class="mb-4">
                    <v-card-title>Create Exception</v-card-title>
                    <v-card-text>
                        <v-text-field v-model="exceptionForm.user_id" label="User ID" />
                        <v-text-field v-model="exceptionForm.reason" label="Reason" />
                        <v-btn color="primary" :loading="exceptionForm.processing" @click="createException">Create</v-btn>
                    </v-card-text>
                </v-card>

                <v-card>
                    <v-card-title>Active exceptions</v-card-title>
                    <v-list>
                        <v-list-item v-for="row in exceptions" :key="row.id">
                            <v-list-item-title>{{ row.user_id }}</v-list-item-title>
                            <v-list-item-subtitle>{{ row.reason }} · {{ row.closure_mode }}</v-list-item-subtitle>
                            <template #append>
                                <v-btn size="small" variant="text" @click="revokeException(row.id)">Revoke</v-btn>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    counts: { type: Object, required: true },
    gatePass: { type: Boolean, required: true },
    gateFailures: { type: Array, default: () => [] },
    population: { type: Array, default: () => [] },
    exceptions: { type: Array, default: () => [] },
    mandatorySsoEnrollment: { type: Boolean, default: false },
    exceptionClosureMode: { type: String, default: 'automatic' },
    authenticationPolicy: { type: String, default: 'both' },
});

const settings = reactive({
    mandatory_sso_enrollment: props.mandatorySsoEnrollment,
    sso_exception_closure_mode: props.exceptionClosureMode,
});

const settingsForm = useForm({});
const exceptionForm = useForm({
    user_id: '',
    reason: '',
});

function saveSettings() {
    settingsForm.transform(() => ({ ...settings })).post(route('sso-enforcement.settings'), { preserveScroll: true });
}

function createException() {
    exceptionForm.post(route('sso-enforcement.exceptions.store'), { preserveScroll: true });
}

function revokeException(id) {
    useForm({}).post(route('sso-enforcement.exceptions.revoke', id), { preserveScroll: true });
}
</script>

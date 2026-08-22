<template>
    <v-app>
        <v-main>
            <v-container>
                <h1 class="text-h5 mb-2">Platform Emergency Authority</h1>
                <p class="text-medium-emphasis mb-4">
                    Dedicated Platform recovery — not ordinary Tenant administration.
                    Availability vs compromise must be classified. No automatic Password fallback.
                </p>

                <v-card class="mb-4">
                    <v-card-title>Invoke PEA</v-card-title>
                    <v-card-text>
                        <v-select
                            v-model="form.tenant_id"
                            :items="tenantItems"
                            label="Tenant"
                            item-title="label"
                            item-value="id"
                        />
                        <v-text-field v-model="form.emergency_tenant_user_id" label="Target Tenant User ID (optional)" />
                        <v-select
                            v-model="form.classification"
                            :items="['availability', 'compromise']"
                            label="Classification"
                        />
                        <v-text-field v-model="form.reason" label="Reason" />
                        <v-switch v-model="form.enable_temporary_password" label="Enable temporary Password recovery" color="primary" />
                        <v-btn color="primary" :loading="form.processing" @click="invoke">Invoke</v-btn>
                    </v-card-text>
                </v-card>

                <v-card>
                    <v-card-title>Recent cases</v-card-title>
                    <v-list>
                        <v-list-item v-for="c in cases" :key="c.id">
                            <v-list-item-title>{{ c.tenant_id }} · {{ c.status }} · {{ c.classification }}</v-list-item-title>
                            <v-list-item-subtitle>{{ c.reason }}</v-list-item-subtitle>
                            <template #append>
                                <v-btn
                                    v-if="c.status === 'active'"
                                    size="small"
                                    variant="text"
                                    @click="closeCase(c.id)"
                                >
                                    Close
                                </v-btn>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    cases: { type: Array, default: () => [] },
    tenants: { type: Array, default: () => [] },
});

const tenantItems = computed(() =>
    props.tenants.map((t) => ({ id: t.id, label: `${t.slug} (${t.status})` }))
);

const form = useForm({
    tenant_id: '',
    emergency_tenant_user_id: '',
    classification: 'availability',
    reason: '',
    enable_temporary_password: true,
    ttl_hours: 24,
});

function invoke() {
    form.post(route('platform.emergency.invoke'), { preserveScroll: true });
}

function closeCase(id) {
    useForm({ close_reason: 'return_to_normal' }).post(route('platform.emergency.close', id), {
        preserveScroll: true,
    });
}
</script>

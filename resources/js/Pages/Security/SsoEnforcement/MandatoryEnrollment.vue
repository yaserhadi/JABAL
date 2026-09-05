<template>
    <AppLayout>
        <v-row justify="center">
            <v-col cols="12" md="8" lg="6">
                <h1 class="text-h5 mb-2">Mandatory SSO Enrollment</h1>
                <p class="text-medium-emphasis mb-4">
                    Your organization requires Enterprise SSO before normal application access.
                    Complete Company SSO sign-in. Skip and Maybe Later are not available.
                </p>

                <v-alert type="warning" variant="tonal" class="mb-4">
                    Status: {{ readinessState }}
                    <span v-if="reason"> ({{ reason }})</span>
                </v-alert>

                <v-btn
                    v-if="ssoOperational && ssoStartUrl"
                    color="primary"
                    :href="ssoStartUrl"
                    size="large"
                    class="mb-4"
                    @click.prevent="goToSsoStart"
                >
                    Continue with Company SSO
                </v-btn>
                <v-alert v-else type="error" variant="tonal" class="mb-4">
                    Company SSO is not currently available. Contact your administrator.
                </v-alert>

                <p class="text-caption">
                    Skip allowed: {{ skipAllowed ? 'yes' : 'no' }} ·
                    Maybe later: {{ maybeLaterAllowed ? 'yes' : 'no' }}
                </p>
            </v-col>
        </v-row>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    readinessState: { type: String, required: true },
    reason: { type: String, default: null },
    ssoOperational: { type: Boolean, default: false },
    ssoStartUrl: { type: String, default: null },
    skipAllowed: { type: Boolean, default: false },
    maybeLaterAllowed: { type: Boolean, default: false },
});

const goToSsoStart = () => {
    if (props.ssoStartUrl) {
        window.location.assign(props.ssoStartUrl);
    }
};
</script>

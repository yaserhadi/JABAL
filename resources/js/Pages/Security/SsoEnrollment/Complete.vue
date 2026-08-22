<template>
    <v-app>
        <v-main>
            <v-container class="py-8" style="max-width: 480px">
                <v-card>
                    <v-card-title>Company SSO linked</v-card-title>
                    <v-card-text>
                        <p>{{ message }}</p>
                        <p class="mt-4 text-medium-emphasis">
                            Status: SSO Linked. This is not SSO Ready yet — sign in again with Company SSO
                            through the normal login path to verify readiness.
                        </p>
                        <v-btn class="mt-4" color="primary" href="/login">Sign in with Company SSO</v-btn>
                        <v-btn class="mt-4 ml-2" variant="text" href="/dashboard">Go to dashboard</v-btn>
                    </v-card-text>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
defineProps({
    identity_link_id: { type: String, required: true },
    created: { type: Boolean, default: true },
    message: { type: String, required: true },
    verification_status: { type: String, default: 'linked' },
    ready: { type: Boolean, default: false },
});
</script>

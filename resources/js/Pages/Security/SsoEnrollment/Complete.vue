<template>
    <v-app>
        <v-main>
            <v-container class="py-8" style="max-width: 480px">
                <v-card>
                    <v-card-title>Enterprise SSO linked</v-card-title>
                    <v-card-text>
                        <p>{{ message }}</p>
                        <p class="mt-4 text-medium-emphasis">
                            Your current session was not changed. Future sign-ins can use Enterprise SSO.
                        </p>
                        <v-btn class="mt-4" color="primary" href="/dashboard">Go to dashboard</v-btn>
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
});
</script>

<template>
    <v-app>
        <v-main>
            <v-container class="py-8" style="max-width: 480px">
                <v-card>
                    <v-card-title>Wrong account</v-card-title>
                    <v-card-text>
                        This enrollment invitation is for a different Jabal account.
                        Sign out and sign in as the invited user to continue.
                    </v-card-text>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
defineProps({
    reason: { type: String, default: 'wrong_account' },
});
</script>

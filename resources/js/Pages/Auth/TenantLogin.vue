<template>
    <v-app>
        <v-main>
            <v-container fluid class="fill-height">
                <v-row align="center" justify="center">
                    <v-col cols="12" sm="8" md="4">
                        <v-card>
                            <v-card-title class="text-h5 text-center pa-4">
                                {{ tenant.name }}
                            </v-card-title>
                            <v-card-subtitle class="text-center pb-2">
                                Sign in to your workspace
                            </v-card-subtitle>
                            <v-card-text>
                                <v-alert
                                    v-if="$page.props.errors?.email"
                                    type="error"
                                    variant="tonal"
                                    class="mb-4"
                                >
                                    {{ $page.props.errors.email }}
                                </v-alert>

                                <v-alert
                                    v-if="!passwordLoginAllowed && !(ssoOperational && ssoStartUrl)"
                                    type="warning"
                                    variant="tonal"
                                    class="mb-4"
                                >
                                    No sign-in methods are currently available for this organization.
                                </v-alert>

                                <v-btn
                                    v-if="ssoOperational && ssoStartUrl"
                                    color="primary"
                                    block
                                    class="mb-4"
                                    :href="ssoStartUrl"
                                >
                                    Sign in with SSO
                                </v-btn>

                                <v-divider
                                    v-if="passwordLoginAllowed && ssoOperational && ssoStartUrl"
                                    class="my-4"
                                />

                                <v-form v-if="passwordLoginAllowed" @submit.prevent="submit">
                                    <v-text-field
                                        v-model="form.email"
                                        label="Email"
                                        type="email"
                                        :error-messages="form.errors.email"
                                        required
                                        prepend-inner-icon="mdi-email"
                                    />
                                    <v-text-field
                                        v-model="form.password"
                                        label="Password"
                                        type="password"
                                        :error-messages="form.errors.password"
                                        required
                                        prepend-inner-icon="mdi-lock"
                                    />
                                    <v-checkbox
                                        v-model="form.remember"
                                        label="Remember me"
                                    />
                                    <v-btn
                                        type="submit"
                                        color="primary"
                                        variant="outlined"
                                        block
                                        :loading="form.processing"
                                        class="mt-2"
                                    >
                                        Sign in with password
                                    </v-btn>
                                </v-form>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { tenantRouteParams } from '@/support/tenantEntry';

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
    ssoOperational: {
        type: Boolean,
        default: false,
    },
    ssoStartUrl: {
        type: String,
        default: null,
    },
    passwordLoginAllowed: {
        type: Boolean,
        default: true,
    },
    prefillEmail: {
        type: String,
        default: '',
    },
});

const form = useForm({
    email: props.prefillEmail || '',
    password: '',
    remember: false,
});

onMounted(() => {
    if (props.prefillEmail && !form.email) {
        form.email = props.prefillEmail;
    }
});

const submit = () => {
    form.post(route('tenant.login.submit', tenantRouteParams(props.tenant)), {
        onFinish: () => form.reset('password'),
    });
};
</script>

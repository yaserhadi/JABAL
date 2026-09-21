<template>
    <v-app>
        <v-main>
            <v-container class="py-8" style="max-width: 480px">
                <v-card>
                    <v-card-title>Set up two-factor authentication</v-card-title>
                    <v-card-text>
                        <p class="mb-4">
                            Scan the QR code with your authenticator app, then enter the verification code.
                        </p>

                        <div v-if="qrDataUrl" class="d-flex justify-center mb-4">
                            <img
                                :src="qrDataUrl"
                                alt="Authenticator QR code"
                                width="200"
                                height="200"
                            />
                        </div>
                        <v-alert v-else-if="otpauthUri" type="warning" variant="tonal" class="mb-4">
                            QR code could not be rendered. Use the text key instead.
                        </v-alert>

                        <!-- Manual fallback: progressive disclosure (never sent to an external QR service) -->
                        <div class="mb-4">
                            <template v-if="!showManualKey">
                                <p class="text-body-2 text-medium-emphasis mb-1">Can't scan the code?</p>
                                <v-btn
                                    variant="text"
                                    color="primary"
                                    class="px-0"
                                    @click="showManualKey = true"
                                >
                                    Enter text key instead
                                </v-btn>
                            </template>
                            <template v-else>
                                <p class="text-body-2 font-weight-medium mb-2">Setup key</p>
                                <div class="d-flex align-center flex-wrap ga-2 mb-2">
                                    <v-alert
                                        type="info"
                                        variant="tonal"
                                        class="text-break font-weight-medium mb-0 flex-grow-1"
                                        density="compact"
                                    >
                                        {{ formattedSecret }}
                                    </v-alert>
                                    <v-btn
                                        variant="tonal"
                                        size="small"
                                        :aria-label="copied ? 'Copied' : 'Copy setup key'"
                                        @click="copySecret"
                                    >
                                        {{ copied ? 'Copied' : 'Copy' }}
                                    </v-btn>
                                </div>
                                <v-btn
                                    variant="text"
                                    size="small"
                                    class="px-0"
                                    @click="showManualKey = false"
                                >
                                    Hide key
                                </v-btn>
                            </template>
                        </div>

                        <v-form @submit.prevent="submit">
                            <v-text-field
                                v-model="form.code"
                                label="Verification code"
                                :error-messages="form.errors.code"
                                autocomplete="one-time-code"
                                required
                            />
                            <v-btn type="submit" color="primary" block :loading="form.processing">
                                Confirm enrollment
                            </v-btn>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-container>
        </v-main>
    </v-app>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { route } from 'ziggy-js';
import { tenantRouteParams } from '@/support/tenantEntry';

const props = defineProps({
    tenant: Object,
    secret: String,
    otpauth_uri: String,
    qr_url: String,
});

const form = useForm({ code: '' });
const qrDataUrl = ref('');
const showManualKey = ref(false);
const copied = ref(false);

const otpauthUri = computed(() => props.otpauth_uri || props.qr_url || '');

/** Display-only grouping; authenticator apps accept the raw secret. */
const formattedSecret = computed(() => {
    const raw = (props.secret || '').replace(/\s+/g, '').toUpperCase();
    return raw.replace(/(.{4})(?=.)/g, '$1-');
});

onMounted(async () => {
    if (!otpauthUri.value) {
        showManualKey.value = true;
        return;
    }

    try {
        qrDataUrl.value = await QRCode.toDataURL(otpauthUri.value, {
            width: 200,
            margin: 2,
            errorCorrectionLevel: 'M',
        });
    } catch {
        qrDataUrl.value = '';
        showManualKey.value = true;
    }
});

const copySecret = async () => {
    const raw = (props.secret || '').replace(/\s+/g, '');
    if (!raw) {
        return;
    }

    try {
        await navigator.clipboard.writeText(raw);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // Fallback: leave key visible for manual selection
        showManualKey.value = true;
    }
};

const submit = () => {
    form.post(route('identity.mfa.enroll.confirm', tenantRouteParams(props.tenant)));
};
</script>

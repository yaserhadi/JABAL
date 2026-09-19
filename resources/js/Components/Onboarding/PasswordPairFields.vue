<template>
    <div>
        <v-text-field
            :model-value="password"
            label="Password"
            :type="showPassword ? 'text' : 'password'"
            :error-messages="errors.password"
            required
            prepend-inner-icon="mdi-lock"
            :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
            autocomplete="new-password"
            @update:model-value="$emit('update:password', $event)"
            @click:append-inner="showPassword = !showPassword"
        />
        <v-text-field
            :model-value="passwordConfirmation"
            label="Confirm Password"
            :type="showConfirmation ? 'text' : 'password'"
            :error-messages="errors.password_confirmation"
            required
            prepend-inner-icon="mdi-lock-check"
            :append-inner-icon="showConfirmation ? 'mdi-eye-off' : 'mdi-eye'"
            autocomplete="new-password"
            @update:model-value="$emit('update:passwordConfirmation', $event)"
            @click:append-inner="showConfirmation = !showConfirmation"
        />
        <p v-if="matchHint" class="text-caption text-success mb-2" data-testid="passwords-match-hint">
            ✓ Passwords match
        </p>
    </div>
</template>

<script setup>
import { ref } from 'vue';

defineProps({
    password: {
        type: String,
        default: '',
    },
    passwordConfirmation: {
        type: String,
        default: '',
    },
    errors: {
        type: Object,
        default: () => ({
            password: [],
            password_confirmation: [],
        }),
    },
    matchHint: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:password', 'update:passwordConfirmation']);

const showPassword = ref(false);
const showConfirmation = ref(false);
</script>

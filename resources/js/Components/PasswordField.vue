<template>
    <v-text-field
        :model-value="modelValue"
        :label="label"
        :type="visible ? 'text' : 'password'"
        :error-messages="errorMessages"
        :required="required"
        :prepend-inner-icon="prependInnerIcon"
        :append-inner-icon="visible ? 'mdi-eye-off' : 'mdi-eye'"
        :autocomplete="autocomplete"
        :disabled="disabled"
        @update:model-value="$emit('update:modelValue', $event)"
        @click:append-inner="visible = !visible"
    />
</template>

<script setup>
import { ref } from 'vue';

defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: 'Password',
    },
    errorMessages: {
        type: [String, Array],
        default: () => [],
    },
    required: {
        type: Boolean,
        default: false,
    },
    prependInnerIcon: {
        type: String,
        default: 'mdi-lock',
    },
    autocomplete: {
        type: String,
        default: 'current-password',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:modelValue']);

const visible = ref(false);
</script>

<template>
  <v-app>
    <v-app-bar
      app
      color="primary"
      dark
    >
      <v-toolbar-title>{{ appName }}</v-toolbar-title>

      <v-spacer />

      <v-btn
        v-if="!auth?.user"
        variant="text"
        :to="{ name: 'login' }"
      >
        Login
      </v-btn>
      <v-btn
        v-if="!auth?.user"
        variant="text"
        :to="{ name: 'register' }"
        class="ml-2"
      >
        Register
      </v-btn>
    </v-app-bar>

    <v-main>
      <v-container>
        <slot />
      </v-container>
    </v-main>
  </v-app>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()

const auth = computed(() => page.props.auth)
const appName = computed(() => page.props.appName || 'JABAL')
</script>

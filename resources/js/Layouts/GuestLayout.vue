<template>
  <v-app>
    <v-app-bar
      app
      color="primary"
      dark
    >
      <v-toolbar-title>{{ appName }}</v-toolbar-title>

      <v-spacer />

      <Link v-if="!auth?.user" :href="route('login')">
        <v-btn variant="text">
          Login
        </v-btn>
      </Link>
      <Link v-if="!auth?.user" :href="route('register')" class="ml-2">
        <v-btn variant="text">
          Register
        </v-btn>
      </Link>
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
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()

const auth = computed(() => page.props.auth)
const appName = computed(() => page.props.appName || 'JABAL')
</script>

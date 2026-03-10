<template>
  <GuestLayout>
    <v-row justify="center" align="center" class="fill-height">
      <v-col cols="12" md="8" lg="6">
        <v-card class="pa-8 text-center" elevation="4">
          <v-icon
            size="80"
            color="primary"
            class="mb-4"
          >
            mdi-rocket-launch
          </v-icon>

          <v-card-title class="text-h3 text-md-h2 mb-4">
            Welcome to {{ appName }}
          </v-card-title>

          <v-card-subtitle class="text-h6 mb-6">
            Your modern, multi-tenant application platform
          </v-card-subtitle>

          <v-card-text class="text-body-1 mb-8">
            <p class="mb-4">
              Get started with our powerful platform that provides secure authentication,
              multi-tenancy support, and comprehensive audit logging.
            </p>
            <p>
              Join thousands of users who trust us with their business needs.
            </p>
          </v-card-text>

          <v-divider class="mb-8" />

          <div v-if="!auth?.user" class="d-flex flex-column flex-md-row gap-4 justify-center">
            <Link :href="route('login')">
              <v-btn
                color="primary"
                size="large"
                prepend-icon="mdi-login"
                class="px-8"
              >
                Log In
              </v-btn>
            </Link>

            <Link :href="route('register')">
              <v-btn
                color="secondary"
                size="large"
                variant="outlined"
                prepend-icon="mdi-account-plus"
                class="px-8"
              >
                Register
              </v-btn>
            </Link>
          </div>

          <div v-else class="d-flex justify-center">
            <Link v-if="personalTenant" :href="route('dashboard', { tenant: personalTenant.id })">
              <v-btn
                color="primary"
                size="large"
                prepend-icon="mdi-view-dashboard"
                class="px-8"
              >
                Go to Dashboard
              </v-btn>
            </Link>
          </div>
        </v-card>
      </v-col>
    </v-row>
  </GuestLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'

const page = usePage()

const auth = computed(() => page.props.auth)
const personalTenant = computed(() => page.props.personalTenant)
const appName = computed(() => page.props.appName || 'JABAL')
</script>

<style scoped>
.fill-height {
  min-height: calc(100vh - 64px);
}
</style>

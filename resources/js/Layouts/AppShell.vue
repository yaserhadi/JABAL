<template>
  <v-app>
    <v-navigation-drawer
      v-model="drawer"
      :temporary="$vuetify.display.mobile"
      app
    >
      <v-list>
        <Link v-if="tenant" :href="route('dashboard', { tenant: tenantEntry(tenant) })">
          <v-list-item
            prepend-icon="mdi-view-dashboard"
            title="Dashboard"
          />
        </Link>
        <v-list-item
          prepend-icon="mdi-cog"
          title="Settings"
          disabled
        />
      </v-list>

      <template v-slot:append>
        <v-divider />
        <v-list>
          <v-list-item
            prepend-icon="mdi-logout"
            title="Logout"
            @click="handleLogout"
          />
        </v-list>
      </template>
    </v-navigation-drawer>

    <v-app-bar app color="primary" dark>
      <v-app-bar-nav-icon
        @click="drawer = !drawer"
      />

      <v-toolbar-title>{{ appName }}</v-toolbar-title>

      <v-spacer />

      <v-chip
        v-if="tenant"
        class="mr-2"
        color="secondary"
        size="small"
      >
        <v-icon start size="small">mdi-domain</v-icon>
        {{ tenant.name }}
      </v-chip>

      <v-menu>
        <template v-slot:activator="{ props }">
          <v-btn
            v-bind="props"
            icon
          >
            <v-icon>mdi-account-circle</v-icon>
          </v-btn>
        </template>
        <v-list>
          <v-list-item>
            <v-list-item-title>{{ user?.name || 'User' }}</v-list-item-title>
            <v-list-item-subtitle>{{ user?.email }}</v-list-item-subtitle>
          </v-list-item>
          <v-divider />
          <v-list-item
            prepend-icon="mdi-logout"
            title="Logout"
            @click="handleLogout"
          />
        </v-list>
      </v-menu>
    </v-app-bar>

    <v-main>
      <v-container fluid>
        <slot />
      </v-container>
    </v-main>
  </v-app>
</template>

<script setup>
import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { tenantEntry } from '@/support/tenantEntry'

const page = usePage()

const drawer = ref(false)

const user = computed(() => page.props.auth?.user)
const tenant = computed(() => page.props.tenant)
const appName = computed(() => page.props.appName || 'JABAL')

const handleLogout = () => {
  // Use form submit for full page navigation so login page displays immediately.
  const form = document.createElement('form')
  form.method = 'POST'
  form.action = route('logout')
  const csrf = document.createElement('input')
  csrf.type = 'hidden'
  csrf.name = '_token'
  csrf.value = page.props.csrf_token || document.querySelector('meta[name="csrf-token"]')?.content || ''
  form.appendChild(csrf)
  document.body.appendChild(form)
  form.submit()
}
</script>

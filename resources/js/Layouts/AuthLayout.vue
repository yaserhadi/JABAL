<template>
  <v-app>
    <v-main>
      <v-container
        class="fill-height"
        fluid
      >
        <v-row
          align="center"
          justify="center"
        >
          <v-col
            cols="12"
            sm="8"
            md="6"
            lg="4"
            xl="3"
          >
            <v-card
              class="elevation-12"
              rounded="lg"
            >
              <v-card-title class="text-center pa-6">
                <h2 class="text-h4">{{ appName }}</h2>
              </v-card-title>

              <v-divider />

              <v-card-text class="pa-6">
                <slot />
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>
      </v-container>
    </v-main>
  </v-app>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()

const appName = computed(() => page.props.appName || 'JABAL')
</script>

<style scoped>
.fill-height {
  min-height: 100vh;
}
</style>

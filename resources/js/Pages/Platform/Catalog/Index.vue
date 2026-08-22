<template>
    <PlatformLayout>
        <h1 class="text-h5 mb-4">Product catalog</h1>
        <p class="text-body-2 mb-6">WAVE-6 foundation — Product / Capability / Offering (no billing portal).</p>

        <h2 class="text-h6 mb-2">Products</h2>
        <v-table class="mb-8">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Capabilities</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in products" :key="p.id">
                    <td>{{ p.code }}</td>
                    <td>{{ p.name }}</td>
                    <td>{{ p.capabilities.map((c) => c.code).join(', ') }}</td>
                </tr>
            </tbody>
        </v-table>

        <h2 class="text-h6 mb-2">Offerings</h2>
        <v-table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Status</th>
                    <th>Product</th>
                    <th>Plan</th>
                    <th>Capabilities</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="o in offerings" :key="o.id">
                    <td>{{ o.code }}</td>
                    <td>{{ o.status }}</td>
                    <td>{{ o.product_code }}</td>
                    <td>{{ o.plan_code }}</td>
                    <td>{{ o.capabilities.join(', ') }}</td>
                </tr>
            </tbody>
        </v-table>
    </PlatformLayout>
</template>

<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

defineProps({
    products: { type: Array, required: true },
    offerings: { type: Array, required: true },
});
</script>

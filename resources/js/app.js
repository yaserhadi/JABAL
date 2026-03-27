import './bootstrap';
import '../css/app.css';

import { Ziggy } from './ziggy';
import { createApp, h } from 'vue';
import { route } from 'ziggy-js';

// Blade @routes outputs `const Ziggy` in a classic script; that does not populate
// globalThis.Ziggy, which ziggy-js route() expects when called from ESM. Without this,
// Login and other pages can throw during setup and render a blank screen.
globalThis.Ziggy = Ziggy;
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';

const appName = import.meta.env.VITE_APP_NAME || 'JABAL';

// Configure Vuetify
const vuetify = createVuetify({
    components,
    directives,
    theme: {
        defaultTheme: 'light',
        themes: {
            light: {
                colors: {
                    primary: '#1976D2',
                    secondary: '#424242',
                    accent: '#82B1FF',
                    error: '#FF5252',
                    info: '#2196F3',
                    success: '#4CAF50',
                    warning: '#FFC107',
                },
            },
            dark: {
                colors: {
                    primary: '#2196F3',
                    secondary: '#424242',
                    accent: '#FF4081',
                    error: '#FF5252',
                    info: '#2196F3',
                    success: '#4CAF50',
                    warning: '#FB8C00',
                },
            },
        },
    },
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(vuetify);
        app.config.globalProperties.route = route;
        return app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

import { fileURLToPath, URL } from 'node:url';

import { PrimeVueResolver } from '@primevue/auto-import-resolver';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import Components from 'unplugin-vue-components/vite';
import { defineConfig } from 'vite';

// https://vitejs.dev/config/
export default defineConfig({
    optimizeDeps: {
        noDiscovery: true,
        include: ['jsbarcode']
    },
    plugins: [
        vue(),
        tailwindcss(),
        Components({
            resolvers: [PrimeVueResolver()]
        })
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url))
        }
    },
    css: {
        preprocessorOptions: {
            scss: {
                api: 'modern-compiler'
            }
        }
    },
    server: {
        port: 5173,
        proxy: {
            '/api': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true
            },
            '/downloads': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true
            }
        }
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return;
                    }

                    if (id.includes('html2canvas')) {
                        return 'html2canvas';
                    }
                    if (id.includes('jspdf')) {
                        return 'pdf';
                    }
                    if (id.includes('chart.js')) {
                        return 'chart';
                    }
                    if (id.includes('jsbarcode')) {
                        return 'barcode';
                    }
                    if (id.includes('axios')) {
                        return 'axios';
                    }
                    if (id.includes('@primeuix')) {
                        return 'primeuix';
                    }
                    if (id.includes('primeicons')) {
                        return 'primeicons';
                    }
                    if (id.includes('primevue')) {
                        const match = id.match(/primevue[/\\]([^/\\]+)/);
                        const name = match?.[1] ?? 'core';

                        if (['config', 'confirmationservice', 'toastservice'].includes(name) || name.startsWith('use')) {
                            return 'primevue-core';
                        }
                        if (['datatable', 'column', 'paginator', 'row', 'columngroup', 'table', 'virtualscroller', 'treetable', 'treenode'].includes(name)) {
                            return 'primevue-data';
                        }
                        if (
                            [
                                'datepicker',
                                'calendar',
                                'select',
                                'multiselect',
                                'autocomplete',
                                'treeselect',
                                'listbox',
                                'cascadeselect',
                                'editor',
                                'inputnumber',
                                'inputtext',
                                'textarea',
                                'checkbox',
                                'radiobutton',
                                'togglebutton',
                                'selectbutton',
                                'slider',
                                'knob',
                                'rating',
                                'colorpicker',
                                'inputotp',
                                'inputmask',
                                'password',
                                'iconfield',
                                'floatlabel',
                                'iftalabel'
                            ].includes(name)
                        ) {
                            return 'primevue-form';
                        }
                        if (['dialog', 'drawer', 'sidebar', 'popover', 'confirmdialog', 'confirmpopup', 'overlaypanel', 'tooltip', 'dynamicdialog'].includes(name)) {
                            return 'primevue-overlay';
                        }
                        if (['menu', 'menubar', 'contextmenu', 'tieredmenu', 'panelmenu', 'megamenu', 'breadcrumb', 'tabmenu', 'dock', 'steps', 'tabview', 'tabs', 'accordion', 'accordionpanel', 'accordionheader', 'accordioncontent'].includes(name)) {
                            return 'primevue-nav';
                        }
                        return 'primevue-misc';
                    }
                    if (id.includes('vue-router') || id.includes('pinia') || /[/\\]vue[/\\]/.test(id) || id.includes('@vue/')) {
                        return 'vue-core';
                    }
                }
            }
        },
        chunkSizeWarningLimit: 600
    }
});

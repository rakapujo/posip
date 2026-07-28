<script setup>
/* anchor: Login.vue form-inner panel + empty-state floor; diverge: public status — light shell always */
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';

const authStore = useAuthStore();
const settingsStore = useSettingsStore();

const homeTo = computed(() => (authStore.isAuthenticated ? '/app' : '/'));
const homeLabel = computed(() => (authStore.isAuthenticated ? 'Kembali ke aplikasi' : 'Ke halaman login'));
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-surface-100 px-5 py-8 md:px-8 md:py-12">
        <div class="w-full max-w-sm md:max-w-md bg-surface-0 border border-surface-200 rounded-2xl shadow-sm px-6 py-8 md:px-8 md:py-10 text-center">
            <img
                :src="settingsStore.storeLogo || '/logo.svg'"
                :alt="settingsStore.storeName"
                class="h-12 max-w-[140px] md:max-w-[160px] object-contain mx-auto"
            />
            <p class="text-primary font-semibold text-3xl mt-6 mb-0">404</p>
            <p class="text-surface-900 font-semibold text-lg mt-3 mb-0">Halaman tidak ditemukan</p>
            <p class="text-surface-500 text-sm leading-relaxed mt-2 mb-0">Alamat tidak tersedia atau sudah dipindah.</p>
            <Button as="router-link" :to="homeTo" :label="homeLabel" class="w-full !min-h-11 mt-7" />
            <div v-if="authStore.isAuthenticated" class="mt-3">
                <router-link to="/" class="text-sm text-primary hover:underline">Ke halaman login</router-link>
            </div>
        </div>
    </div>
</template>

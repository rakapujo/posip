<script setup>
import { ref, onMounted } from 'vue';
import { useSettingsStore } from '@/stores/settings';
import { applyFullTheme, loadPreferencesFromCache } from '@/utils/theme';

const settingsStore = useSettingsStore();
const appReady = ref(false);

function hideHtmlPreloader() {
    const el = document.getElementById('app-preloader');
    if (el) {
        // Add transition ONLY at fade-out time — prevents any CSS-load blitz
        el.style.transition = 'opacity 0.3s ease';
        // Force reflow so transition applies from current state
        el.offsetHeight; // eslint-disable-line no-unused-expressions
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 300);
    }
    const style = document.getElementById('preloader-style');
    if (style) setTimeout(() => style.remove(), 300);
}

onMounted(() => {
    // Apply user theme preferences from localStorage cache (fast path)
    const cachedPrefs = loadPreferencesFromCache();
    if (cachedPrefs) {
        const hasCustomTheme = cachedPrefs.preset !== 'Lara' || cachedPrefs.primary !== 'blue' || cachedPrefs.surface !== 'slate';

        if (hasCustomTheme) {
            applyFullTheme(cachedPrefs);
        }
    }

    // Unblock LCP: render immediately from cached/default public settings
    appReady.value = true;
    hideHtmlPreloader();

    // Refresh public settings in background (store already hydrated from localStorage)
    settingsStore.fetchPublicSettings().catch(() => {
        // Keep cached/default values
    });
});
</script>

<template>
    <Toast position="bottom-right" />
    <router-view v-if="appReady" />
</template>

<style scoped></style>

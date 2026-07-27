<script setup>
/** Section custom: desktop selalu terbuka; mobile collapse default tertutup. */
import { ref } from 'vue';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    meta: { type: String, default: '' }
});

const expanded = ref(false);
</script>

<template>
    <div class="mb-6 w-full">
        <!-- Mobile -->
        <div class="lg:hidden border border-surface-200 dark:border-surface-700 rounded-lg overflow-hidden w-full">
            <button
                type="button"
                class="w-full flex items-center justify-between gap-3 p-4 text-left bg-surface-0 dark:bg-surface-900"
                @click="expanded = !expanded"
            >
                <div class="min-w-0 flex-1">
                    <div class="text-xs text-surface-500 mb-1">{{ title }}</div>
                    <div v-if="subtitle" class="text-xl font-bold text-surface-900 dark:text-surface-0 truncate">{{ subtitle }}</div>
                    <div v-if="meta" class="text-xs text-surface-400 mt-0.5">{{ meta }}</div>
                </div>
                <i :class="['pi text-surface-400 shrink-0', expanded ? 'pi-chevron-up' : 'pi-chevron-down']" />
            </button>
            <div v-show="expanded" class="border-t border-surface-200 dark:border-surface-700 p-3">
                <slot />
            </div>
        </div>

        <!-- Desktop -->
        <div class="hidden lg:block bg-surface-0 dark:bg-surface-900 rounded-lg border border-surface-200 dark:border-surface-700 p-4 w-full">
            <div class="flex items-center justify-between gap-3 mb-3">
                <h3 class="font-semibold text-surface-800 dark:text-surface-100 m-0">{{ title }}</h3>
                <span v-if="subtitle" class="text-sm text-surface-500 shrink-0">{{ subtitle }}</span>
            </div>
            <slot />
        </div>
    </div>
</template>

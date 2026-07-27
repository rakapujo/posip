<script setup>
/**
 * Ringkasan uang: desktop grid; mobile 1 card full + expand/collapse (default collapse).
 *
 * items: [{ label, value, hint?: string, tone?: 'default'|'danger'|'warn'|'success'|'info'|'primary' }]
 * primaryIndex: index item yang tampil saat collapse (default 0)
 *
 * Values use .summary-money-value so full Rp amounts (~10T) wrap inside the card.
 */
import { ref, computed } from 'vue';

const props = defineProps({
    title: { type: String, default: 'Ringkasan' },
    items: { type: Array, default: () => [] },
    primaryIndex: { type: Number, default: 0 },
    cols: { type: Number, default: 4 }
});

const expanded = ref(false);

const primary = computed(() => props.items[props.primaryIndex] || props.items[0] || null);

const toneClass = (tone) => {
    switch (tone) {
        case 'danger':
            return 'text-red-500';
        case 'warn':
            return 'text-yellow-500';
        case 'success':
            return 'text-green-600 dark:text-green-400';
        case 'info':
            return 'text-blue-600 dark:text-blue-400';
        case 'primary':
            return 'text-primary';
        case 'orange':
            return 'text-orange-600 dark:text-orange-400';
        default:
            return 'text-surface-900 dark:text-surface-0';
    }
};

const gridClass = computed(() => {
    const map = {
        2: 'lg:grid-cols-2',
        3: 'lg:grid-cols-3',
        4: 'lg:grid-cols-4',
        5: 'lg:grid-cols-5',
        6: 'lg:grid-cols-6',
        7: 'lg:grid-cols-7'
    };
    return map[props.cols] || 'lg:grid-cols-4';
});
</script>

<template>
    <div class="mb-4">
        <div v-if="title" class="hidden lg:block text-sm font-medium text-surface-600 dark:text-surface-300 mb-2">{{ title }}</div>
        <!-- Mobile: 1 card collapse -->
        <div class="lg:hidden border border-surface-200 dark:border-surface-700 rounded-lg overflow-hidden summary-stat-card">
            <button
                type="button"
                class="w-full flex items-center justify-between gap-3 p-4 text-left bg-surface-0 dark:bg-surface-900"
                @click="expanded = !expanded"
            >
                <div class="min-w-0 flex-1">
                    <div class="text-xs text-surface-500 mb-1">{{ title }}</div>
                    <div v-if="primary" class="text-sm text-surface-500">{{ primary.label }}</div>
                    <div v-if="primary" class="summary-money-value" :class="toneClass(primary.tone)">
                        {{ primary.value }}
                    </div>
                    <div v-if="primary?.hint" class="text-xs text-surface-400 mt-0.5">{{ primary.hint }}</div>
                </div>
                <i :class="['pi text-surface-400 shrink-0', expanded ? 'pi-chevron-up' : 'pi-chevron-down']" />
            </button>
            <div v-show="expanded" class="border-t border-surface-200 dark:border-surface-700 p-3 space-y-3">
                <div
                    v-for="(item, i) in items"
                    :key="i"
                    class="flex items-start justify-between gap-3 px-1"
                >
                    <span class="text-sm text-surface-500 shrink-0">{{ item.label }}</span>
                    <div class="min-w-0 text-right">
                        <div class="summary-money-value" :class="toneClass(item.tone)">{{ item.value }}</div>
                        <div v-if="item.hint" class="text-xs text-surface-400">{{ item.hint }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop: grid -->
        <div class="hidden lg:grid gap-4" :class="gridClass">
            <div
                v-for="(item, i) in items"
                :key="i"
                class="summary-stat-card border border-surface-200 dark:border-surface-700 rounded-lg p-4"
            >
                <div class="text-surface-500 text-sm mb-1">{{ item.label }}</div>
                <div class="summary-money-value" :class="toneClass(item.tone)">{{ item.value }}</div>
                <div v-if="item.hint" class="text-xs text-surface-400 mt-1">{{ item.hint }}</div>
            </div>
        </div>
    </div>
</template>

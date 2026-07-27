<script setup>
/**
 * Aging bucket summary: desktop grid; mobile 1 section collapse (default collapsed).
 * Bucket click → emit('select', key) untuk filter.
 */
import { ref } from 'vue';

defineProps({
    title: { type: String, default: 'Aging Bucket' },
    totalLabel: { type: String, default: 'Total Outstanding' },
    totalValue: { type: String, default: '' },
    totalMeta: { type: String, default: '' },
    buckets: { type: Array, default: () => [] },
    selectedKey: { type: String, default: null }
});

const emit = defineEmits(['select']);
const expanded = ref(false);

function onSelect(key) {
    emit('select', key);
}
</script>

<template>
    <div class="mb-6">
        <!-- Mobile: satu section collapse -->
        <div class="lg:hidden border border-surface-200 dark:border-surface-700 rounded-lg overflow-hidden w-full">
            <button
                type="button"
                class="w-full flex items-center justify-between gap-3 p-4 text-left bg-surface-0 dark:bg-surface-900"
                @click="expanded = !expanded"
            >
                <div class="min-w-0 flex-1">
                    <div class="text-xs text-surface-500 mb-1">{{ title }}</div>
                    <div class="text-sm text-surface-500">{{ totalLabel }}</div>
                    <div class="text-xl font-bold text-surface-900 dark:text-surface-0 truncate">{{ totalValue }}</div>
                    <div v-if="totalMeta" class="text-xs text-surface-400 mt-0.5">{{ totalMeta }}</div>
                </div>
                <i :class="['pi text-surface-400 shrink-0', expanded ? 'pi-chevron-up' : 'pi-chevron-down']" />
            </button>
            <div v-show="expanded" class="border-t border-surface-200 dark:border-surface-700 p-3 space-y-2">
                <div
                    v-for="b in buckets"
                    :key="b.key"
                    class="rounded-lg p-3 cursor-pointer transition hover:ring-2 w-full"
                    :class="[b.bg, selectedKey === b.key ? b.ring : '']"
                    role="button"
                    tabindex="0"
                    :aria-label="`Filter aging ${b.label}`"
                    @click="onSelect(b.key)"
                    @keydown.enter="onSelect(b.key)"
                >
                    <div :class="[b.text, 'text-xs font-medium mb-1 flex items-center gap-1']">
                        {{ b.label }}
                        <i v-if="selectedKey === b.key" class="pi pi-filter-fill text-xs" />
                    </div>
                    <div :class="[b.text, 'text-lg font-bold']">{{ b.value }}</div>
                    <div class="text-xs text-surface-500 mt-1">{{ b.meta }}</div>
                </div>
            </div>
        </div>

        <!-- Desktop: header + grid -->
        <div class="hidden lg:block bg-surface-0 dark:bg-surface-900 rounded-lg border border-surface-200 dark:border-surface-700 p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-surface-800 dark:text-surface-100">{{ title }}</h3>
                <span class="text-sm text-surface-500">
                    {{ totalLabel }}:
                    <span class="font-bold text-surface-900 dark:text-surface-0 ml-1">{{ totalValue }}</span>
                    <span v-if="totalMeta" class="text-surface-400 ml-2">({{ totalMeta }})</span>
                </span>
            </div>
            <div class="grid grid-cols-5 gap-3">
                <div
                    v-for="b in buckets"
                    :key="b.key"
                    class="rounded-lg p-3 cursor-pointer transition hover:ring-2"
                    :class="[b.bg, selectedKey === b.key ? b.ring : '']"
                    role="button"
                    tabindex="0"
                    :aria-label="`Filter aging ${b.label}`"
                    @click="onSelect(b.key)"
                    @keydown.enter="onSelect(b.key)"
                >
                    <div :class="[b.text, 'text-xs font-medium mb-1 flex items-center gap-1']">
                        {{ b.label }}
                        <i v-if="selectedKey === b.key" class="pi pi-filter-fill text-xs" />
                    </div>
                    <div :class="[b.text, 'text-lg font-bold']">{{ b.value }}</div>
                    <div class="text-xs text-surface-500 mt-1">{{ b.meta }}</div>
                </div>
            </div>
        </div>
    </div>
</template>

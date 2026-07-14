<script setup>
/**
 * DataTableHeader Component
 *
 * Reusable header component for DataTable with title and search functionality.
 * Uses the same search behavior as BrandPage:
 * - Enter to search
 * - Auto-reload when input is cleared (by backspace or X button)
 * - X button to clear and reload
 *
 * @example
 * <DataTable>
 *     <template #header>
 *         <DataTableHeader
 *             v-model="searchQuery"
 *             title="Brand Produk"
 *             placeholder="Cari kode, nama..."
 *             @search="loadData"
 *             @clear="loadData"
 *         />
 *     </template>
 * </DataTable>
 */

import { watch } from 'vue';

defineOptions({
    name: 'DataTableHeader'
});

const props = defineProps({
    /**
     * Search query value (v-model)
     */
    modelValue: {
        type: String,
        default: ''
    },
    /**
     * Title displayed on the left side
     */
    title: {
        type: String,
        required: true
    },
    /**
     * Placeholder text for search input
     */
    placeholder: {
        type: String,
        default: 'Cari...'
    },
    /**
     * Whether to show search input
     */
    showSearch: {
        type: Boolean,
        default: true
    },
    /**
     * Width class for search input
     */
    searchWidth: {
        type: String,
        default: 'w-72'
    }
});

const emit = defineEmits(['update:modelValue', 'search', 'clear']);

/**
 * Watch for value changes - emit clear when input is emptied
 * This matches the original BrandPage onSearchInput behavior
 */
watch(
    () => props.modelValue,
    (newValue, oldValue) => {
        // Auto-reload when input is cleared (BrandPage behavior)
        if (oldValue && !newValue) {
            emit('clear');
        }
    }
);

/**
 * Handle input change - emit update
 */
function onInput(value) {
    emit('update:modelValue', value);
}

/**
 * Handle Enter key press - trigger search
 */
function onSearch() {
    emit('search');
}

/**
 * Handle clear button click - clear value (watcher will emit clear)
 */
function onClear() {
    emit('update:modelValue', '');
}
</script>

<template>
    <div class="flex flex-wrap gap-2 items-center justify-between">
        <h4 class="m-0">{{ title }}</h4>

        <IconField v-if="showSearch">
            <InputIcon class="pi pi-search" />
            <InputText :modelValue="modelValue" :placeholder="placeholder" :class="searchWidth" autocomplete="off" @update:modelValue="onInput" @keyup.enter="onSearch" />
            <InputIcon v-if="modelValue" class="pi pi-times cursor-pointer hover:!text-surface-600" @click="onClear" />
        </IconField>

        <!-- Slot for additional content (e.g., extra buttons) -->
        <slot name="extra"></slot>
    </div>
</template>

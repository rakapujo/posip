<script setup>
import { computed } from 'vue';
import Tag from 'primevue/tag';
import { useFormatters } from '@/composables/useFormatters';

const { formatCurrency, formatPercent, formatQty, formatDateTime } = useFormatters();

const props = defineProps({
    label: {
        type: String,
        required: true
    },
    value: {
        type: [String, Number, Boolean, Object, null],
        default: null
    },
    type: {
        type: String,
        default: 'text',
        validator: (value) => ['text', 'badge', 'datetime', 'image', 'currency', 'percent', 'qty'].includes(value)
    },
    // For badge type
    badgeSeverity: {
        type: String,
        default: null
    },
    // For datetime type - user who performed the action
    user: {
        type: String,
        default: null
    },
    // For image type
    imageAlt: {
        type: String,
        default: 'Image'
    },
    // Empty value placeholder
    emptyText: {
        type: String,
        default: '-'
    }
});

const displayValue = computed(() => {
    if (props.value === null || props.value === undefined || props.value === '') {
        return props.emptyText;
    }
    return props.value;
});

const formattedDateTime = computed(() => {
    if (!props.value) return props.emptyText;
    return formatDateTime(props.value);
});

const formattedCurrencyValue = computed(() => {
    if (props.value === null || props.value === undefined) return props.emptyText;
    return formatCurrency(props.value);
});

const formattedPercentValue = computed(() => {
    if (props.value === null || props.value === undefined) return props.emptyText;
    return formatPercent(props.value);
});

const formattedQtyValue = computed(() => {
    if (props.value === null || props.value === undefined) return props.emptyText;
    return formatQty(props.value);
});
</script>

<template>
    <div class="detail-item flex flex-col gap-1">
        <span class="text-surface-500 text-sm font-medium">{{ label }}</span>

        <!-- Text type (default) -->
        <span v-if="type === 'text'" class="text-surface-900 dark:text-surface-0">
            {{ displayValue }}
        </span>

        <!-- Badge type -->
        <div v-else-if="type === 'badge'">
            <Tag v-if="value" :value="displayValue" :severity="badgeSeverity" />
            <span v-else class="text-surface-900 dark:text-surface-0">{{ emptyText }}</span>
        </div>

        <!-- DateTime type -->
        <div v-else-if="type === 'datetime'" class="text-surface-900 dark:text-surface-0">
            <span>{{ formattedDateTime }}</span>
            <span v-if="user" class="text-surface-500 text-sm ml-1">({{ user }})</span>
        </div>

        <!-- Image type -->
        <div v-else-if="type === 'image'">
            <img v-if="value" :src="value" :alt="imageAlt" class="max-w-32 max-h-32 object-contain rounded border border-surface-200 dark:border-surface-700" />
            <span v-else class="text-surface-500">{{ emptyText }}</span>
        </div>

        <!-- Currency type -->
        <span v-else-if="type === 'currency'" class="text-surface-900 dark:text-surface-0">
            {{ formattedCurrencyValue }}
        </span>

        <!-- Percent type -->
        <span v-else-if="type === 'percent'" class="text-surface-900 dark:text-surface-0">
            {{ formattedPercentValue }}
        </span>

        <!-- Qty type -->
        <span v-else-if="type === 'qty'" class="text-surface-900 dark:text-surface-0">
            {{ formattedQtyValue }}
        </span>
    </div>
</template>

<style scoped>
.detail-item {
    min-height: 3rem;
}
</style>

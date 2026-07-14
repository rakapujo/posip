<script setup>
/**
 * DetailTable - Reusable native HTML table for DetailDialog
 *
 * Usage:
 * <DetailTable :data="items" :columns="columns">
 *     <template #product="{ item }">{{ item.product.name }}</template>
 * </DetailTable>
 *
 * Column format:
 * { field: 'product', header: 'Produk', align: 'left', width: '200px' }
 *
 * Special field '#' for row number
 */

defineProps({
    data: {
        type: Array,
        default: () => []
    },
    columns: {
        type: Array,
        required: true
        // [{ field, header, align?, width? }]
    }
});
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="bg-surface-100 dark:bg-surface-700">
                    <th v-for="col in columns" :key="col.field" class="p-2 border border-surface-200 dark:border-surface-600" :class="col.align === 'right' ? 'text-right' : 'text-left'" :style="col.width ? { width: col.width } : {}">
                        {{ col.header }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(item, index) in data" :key="index" class="hover:bg-surface-50 dark:hover:bg-surface-800">
                    <td v-for="col in columns" :key="col.field" class="p-2 border border-surface-200 dark:border-surface-600" :class="col.align === 'right' ? 'text-right' : ''">
                        <!-- Special # column for row number -->
                        <template v-if="col.field === '#'">
                            {{ index + 1 }}
                        </template>
                        <!-- Slot for custom template -->
                        <slot v-else :name="col.field" :item="item" :index="index">
                            {{ item[col.field] ?? '-' }}
                        </slot>
                    </td>
                </tr>
                <!-- Empty state -->
                <tr v-if="!data || data.length === 0">
                    <td :colspan="columns.length" class="p-4 text-center text-surface-500 border border-surface-200 dark:border-surface-600">Tidak ada data</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

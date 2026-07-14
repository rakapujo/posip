<script setup>
import { ref } from 'vue';
import { reportsApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportAnalytic } from '@/composables/useReportAnalytic';

const { formatCurrency, formatQty } = useFormatters();

const selectedSort = ref('count_desc');
const selectedLimit = ref(50);

const sortOptions = [
    { label: 'Frekuensi Terbanyak', value: 'count_desc' },
    { label: 'Qty Terbesar', value: 'qty_desc' },
    { label: 'Nominal Terbesar', value: 'nominal_desc' }
];

const { canExport, exportingExcel, loading, items, summary, startDate, endDate, getPrimeDateFormatShort, loadData, exportExcel } = useReportAnalytic({
    fetchList: (params) => reportsApi.returPattern.list(params),
    exportFn: reportsApi.returPattern.exportExcel,
    buildParams: ({ date_from, date_to }) => ({
        date_from,
        date_to,
        sort: selectedSort.value,
        limit: selectedLimit.value
    }),
    exportFilename: (params) => `laporan_retur_pattern_${params.date_from}.xlsx`,
    loadErrorLabel: 'retur pattern'
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Pattern Retur Penjualan</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" class="w-48" @change="loadData" />
                    <InputNumber v-model="selectedLimit" :min="10" :max="200" class="w-24" @input="loadData" inputClass="w-24 text-right" />
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                </div>
            </template>
        </Toolbar>

        <!-- Summary -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                <div class="text-xs text-red-600 mb-1">Jumlah Retur</div>
                <div class="text-xl font-bold text-red-700">{{ summary.retur_count || 0 }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                <div class="text-xs text-red-600 mb-1">Qty Diretur</div>
                <div class="text-xl font-bold text-red-700">{{ formatQty(summary.qty_total || 0) }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                <div class="text-xs text-red-600 mb-1">Nominal</div>
                <div class="text-xl font-bold text-red-700">{{ formatCurrency(summary.nominal_total || 0) }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3">
                <div class="text-xs text-surface-500 mb-1">Total Qty Jual</div>
                <div class="text-xl font-bold">{{ formatQty(summary.sales_qty_total || 0) }}</div>
            </div>
            <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-3">
                <div class="text-xs text-orange-600 mb-1">Retur Rate</div>
                <div class="text-xl font-bold text-orange-700">{{ summary.retur_rate_percent || 0 }}%</div>
            </div>
        </div>

        <DataTable :value="items" :loading="loading" stripedRows scrollable>
            <template #empty>
                <div class="py-6 text-center text-surface-500">Belum ada retur dalam periode.</div>
            </template>
            <Column header="#" style="width: 50px" bodyClass="text-center">
                <template #body="{ index }">{{ index + 1 }}</template>
            </Column>
            <Column header="Produk">
                <template #body="{ data: r }">
                    <div class="font-medium">{{ r.kode_produk }}</div>
                    <div class="text-xs text-surface-500">{{ r.nama_produk }}</div>
                </template>
            </Column>
            <Column field="kategori" header="Kategori" style="width: 150px" />
            <Column field="retur_count" header="Frekuensi" bodyClass="text-right" style="width: 110px">
                <template #body="{ data: r }">
                    <Tag :value="r.retur_count" :severity="r.retur_count >= 5 ? 'danger' : 'warn'" />
                </template>
            </Column>
            <Column field="qty_total" header="Qty" bodyClass="text-right" style="width: 110px">
                <template #body="{ data: r }">{{ formatQty(r.qty_total) }}</template>
            </Column>
            <Column field="nominal_total" header="Nominal" bodyClass="text-right" style="width: 140px">
                <template #body="{ data: r }">{{ formatCurrency(r.nominal_total) }}</template>
            </Column>
        </DataTable>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { reportsApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportAnalytic } from '@/composables/useReportAnalytic';

const { formatCurrency } = useFormatters();
const grandTotal = ref(0);

const { canExport, exportingExcel, loading, items, summary, startDate, endDate, getPrimeDateFormatShort, loadData, exportExcel } = useReportAnalytic({
    fetchList: (params) => reportsApi.paymentMethod.breakdown(params),
    exportFn: reportsApi.paymentMethod.exportExcel,
    buildParams: ({ date_from, date_to }) => ({ date_from, date_to }),
    exportFilename: (params) => `laporan_metode_pembayaran_${params.date_from}.xlsx`,
    loadErrorLabel: 'metode pembayaran',
    onListLoaded: (payload) => {
        grandTotal.value = payload.grand_total ?? 0;
    }
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Breakdown Metode Pembayaran</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                </div>
            </template>
        </Toolbar>

        <!-- Summary -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                <div class="text-xs text-green-600 mb-1">Tunai</div>
                <div class="text-xl font-bold text-green-700">{{ formatCurrency(summary.tunai_nominal || 0) }}</div>
                <div class="text-xs text-surface-500 mt-1">{{ summary.tunai_trx || 0 }} trx</div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-xs text-blue-600 mb-1">Non-Tunai</div>
                <div class="text-xl font-bold text-blue-700">{{ formatCurrency(summary.non_tunai_nominal || 0) }}</div>
                <div class="text-xs text-surface-500 mt-1">{{ summary.non_tunai_trx || 0 }} trx</div>
            </div>
            <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4">
                <div class="text-xs text-orange-600 mb-1">Biaya Tambahan</div>
                <div class="text-xl font-bold text-orange-700">{{ formatCurrency(summary.biaya_total || 0) }}</div>
            </div>
            <div class="bg-surface-100 dark:bg-surface-800 rounded-lg p-4 border-2 border-primary">
                <div class="text-xs text-primary mb-1">Grand Total</div>
                <div class="text-xl font-bold text-primary">{{ formatCurrency(grandTotal) }}</div>
            </div>
        </div>

        <DataTable :value="items" :loading="loading" stripedRows>
            <template #empty>
                <div class="py-6 text-center text-surface-500">Tidak ada transaksi.</div>
            </template>
            <Column field="kode_pembayaran" header="Kode" style="width: 100px" />
            <Column field="nama_pembayaran" header="Metode" />
            <Column field="metode" header="Tipe" style="width: 100px">
                <template #body="{ data: r }">
                    <Tag :value="r.metode" :severity="r.metode === 'tunai' ? 'success' : 'info'" />
                </template>
            </Column>
            <Column field="jenis" header="Jenis" style="width: 120px" />
            <Column field="trx_count" header="Jml Trx" bodyClass="text-right" />
            <Column field="nominal_total" header="Nominal" bodyClass="text-right">
                <template #body="{ data: r }">{{ formatCurrency(r.nominal_total) }}</template>
            </Column>
            <Column field="biaya_total" header="Biaya" bodyClass="text-right">
                <template #body="{ data: r }">{{ formatCurrency(r.biaya_total) }}</template>
            </Column>
            <Column field="percent" header="%" bodyClass="text-right" style="width: 80px">
                <template #body="{ data: r }">
                    <span class="font-medium">{{ r.percent }}%</span>
                </template>
            </Column>
        </DataTable>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { reportsApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportAnalytic } from '@/composables/useReportAnalytic';

const { formatCurrency, formatQty, formatDateTime } = useFormatters();

const selectedLimit = ref(50);
const selectedSort = ref('omzet_desc');

const sortOptions = [
    { label: 'Omzet', value: 'omzet_desc' },
    { label: 'Jumlah Transaksi', value: 'trx_desc' },
    { label: 'Rata-rata/Trx', value: 'avg_desc' },
    { label: 'Terakhir Transaksi', value: 'last_desc' }
];

const limitOptions = [
    { label: 'Top 10', value: 10 },
    { label: 'Top 25', value: 25 },
    { label: 'Top 50', value: 50 },
    { label: 'Top 100', value: 100 }
];

const { canExport, exportingExcel, loading, items, startDate, endDate, getPrimeDateFormatShort, loadData, exportExcel } = useReportAnalytic({
    fetchList: (params) => reportsApi.topCustomer.list(params),
    exportFn: reportsApi.topCustomer.exportExcel,
    buildParams: ({ date_from, date_to }) => ({
        date_from,
        date_to,
        limit: selectedLimit.value,
        sort: selectedSort.value
    }),
    exportFilename: (params) => `laporan_top_customer_${params.date_from}.xlsx`,
    loadErrorLabel: 'top customer'
});

function rankIcon(rank) {
    if (rank === 1) return '🥇';
    if (rank === 2) return '🥈';
    if (rank === 3) return '🥉';
    return rank;
}
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Top Customer</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <Select v-model="selectedLimit" :options="limitOptions" optionLabel="label" optionValue="value" class="w-28" @change="loadData" />
                    <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" class="w-44" @change="loadData" />
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                </div>
            </template>
        </Toolbar>

        <DataTable :value="items" :loading="loading" stripedRows>
            <template #empty>
                <div class="py-6 text-center text-surface-500">Belum ada data customer.</div>
            </template>
            <Column field="rank" header="#" style="width: 60px" bodyClass="text-center">
                <template #body="{ data }">
                    <span class="text-lg">{{ rankIcon(data.rank) }}</span>
                </template>
            </Column>
            <Column header="Customer">
                <template #body="{ data }">
                    <div class="font-medium">{{ data.customer_nama }}</div>
                    <div class="text-xs text-surface-500">{{ data.kode_customer }}</div>
                </template>
            </Column>
            <Column header="Klasifikasi" style="width: 160px">
                <template #body="{ data }">
                    <Tag v-if="data.tipe" :value="data.tipe" severity="info" class="mr-1" />
                    <Tag v-if="data.kategori" :value="data.kategori" severity="secondary" />
                </template>
            </Column>
            <Column field="trx_count" header="Jml Trx" bodyClass="text-right" style="width: 100px" />
            <Column field="qty_total" header="Qty" bodyClass="text-right" style="width: 100px">
                <template #body="{ data }">{{ formatQty(data.qty_total) }}</template>
            </Column>
            <Column field="omzet" header="Omzet" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.omzet) }}</template>
            </Column>
            <Column field="avg_per_trx" header="Rata-rata/Trx" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.avg_per_trx) }}</template>
            </Column>
            <Column field="last_trx_at" header="Terakhir" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="text-xs">{{ formatDateTime(data.last_trx_at) }}</span>
                </template>
            </Column>
        </DataTable>
    </div>
</template>

<script setup>
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { reportsApi, salesProductReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportAnalytic } from '@/composables/useReportAnalytic';
import { useNotification } from '@/composables/useNotification';

const { formatCurrency, formatQty } = useFormatters();
const notify = useNotification();
const router = useRouter();

const selectedSort = ref('count_desc');
const selectedLimit = ref(50);
const selectedTerminal = ref(null);
const selectedKategori = ref(null);
const terminals = ref([]);
const kategoris = ref([]);

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
        limit: selectedLimit.value,
        terminal_id: selectedTerminal.value,
        kategori_id: selectedKategori.value
    }),
    exportFilename: (params) => `laporan_retur_pattern_${params.date_from}.xlsx`,
    loadErrorLabel: 'retur pattern'
});

const activeFilterCount = computed(() => {
    let n = 0;
    if (startDate.value) n++;
    if (endDate.value) n++;
    if (selectedTerminal.value) n++;
    if (selectedKategori.value) n++;
    return n;
});

// B3.6 — deep-link opsional ke Kartu Stok untuk investigasi pattern retur produk.
function goToStockCard(row) {
    router.push({ name: 'inventory-kartu-stok', query: { product_id: row.product_ulid } });
}

async function loadDropdowns() {
    try {
        const r = await salesProductReportApi.getDropdowns();
        if (r.data.success) {
            terminals.value = r.data.data.terminals ?? [];
            kategoris.value = r.data.data.kategoris ?? [];
        }
    } catch (e) {
        notify.apiError(e, 'Gagal load filter');
    }
}

onMounted(loadDropdowns);
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Pattern Retur Penjualan</span>
            </template>
            <template #end>
                <ListFiltersSheet :active-count="activeFilterCount">
                    <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" @change="loadData" />
                    <Select v-model="selectedTerminal" :options="terminals" optionLabel="nama_terminal" optionValue="id" placeholder="Terminal" filter showClear @change="loadData" />
                    <Select v-model="selectedKategori" :options="kategoris" optionLabel="nama_kategori" optionValue="id" placeholder="Kategori" filter showClear @change="loadData" />
                    <div class="list-filter-control">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <div class="list-filter-control">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                </ListFiltersSheet>
            </template>
        </Toolbar>

        <!-- Summary -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                <div class="text-xs text-red-600 mb-1">Jumlah Retur</div>
                <div class="summary-money-value text-red-700">{{ summary.retur_count || 0 }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                <div class="text-xs text-red-600 mb-1">Qty Diretur</div>
                <div class="summary-money-value text-red-700">{{ formatQty(summary.qty_total || 0) }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                <div class="text-xs text-red-600 mb-1">Nominal</div>
                <div class="summary-money-value text-red-700">{{ formatCurrency(summary.nominal_total || 0) }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3">
                <div class="text-xs text-surface-500 mb-1">Total Qty Jual</div>
                <div class="summary-money-value">{{ formatQty(summary.sales_qty_total || 0) }}</div>
            </div>
            <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-3">
                <div class="text-xs text-orange-600 mb-1">Retur Rate</div>
                <div class="summary-money-value text-orange-700">{{ summary.retur_rate_percent || 0 }}%</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3" v-tooltip.top="'Rata-rata jarak hari antara tanggal jual dan tanggal retur'">
                <div class="text-xs text-surface-500 mb-1">Avg Hari Jual→Retur</div>
                <div class="summary-money-value">{{ summary.avg_days_sale_to_return ?? '-' }}<span v-if="summary.avg_days_sale_to_return !== null" class="text-sm font-normal"> hari</span></div>
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
            <Column field="avg_days_sale_to_return" header="Avg Hari Jual→Retur" bodyClass="text-right" style="width: 150px">
                <template #body="{ data: r }">{{ r.avg_days_sale_to_return ?? '-' }}</template>
            </Column>
            <Column header="" style="width: 50px">
                <template #body="{ data: r }">
                    <Button icon="pi pi-external-link" text rounded size="small" @click="goToStockCard(r)" v-tooltip.top="'Lihat Kartu Stok'" aria-label="Lihat Kartu Stok" />
                </template>
            </Column>
        </DataTable>
    </div>
</template>

<script setup>
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import DetailDialog from '@/components/common/DetailDialog.vue';
import { ref, computed, onMounted } from 'vue';
import { reportsApi, tipeCustomersApi, kategoriCustomersApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportAnalytic } from '@/composables/useReportAnalytic';
import { useNotification } from '@/composables/useNotification';
import { useSalesDrillDown, drillStatusLabel, drillStatusSeverity } from '@/composables/useSalesDrillDown';

const { formatCurrency, formatQty, formatDateTime } = useFormatters();
const notify = useNotification();

const selectedLimit = ref(50);
const selectedSort = ref('omzet_desc');
const reportMode = ref('bruto');
const selectedTipeCustomer = ref(null);
const selectedKategoriCustomer = ref(null);
const selectedSource = ref('all'); // B3.5 — default 'all' (dulu tidak difilter)
const tipeCustomers = ref([]);
const kategoriCustomers = ref([]);

const sourceOptions = [
    { label: 'Semua', value: 'all' },
    { label: 'POS', value: 'pos' },
    { label: 'Manual (BO)', value: 'manual' }
];

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

const modeOptions = [
    { label: 'Bruto', value: 'bruto' },
    { label: 'Net', value: 'net' }
];

const { canExport, exportingExcel, loading, items, startDate, endDate, getPrimeDateFormatShort, toDateString, loadData, exportExcel } = useReportAnalytic({
    fetchList: (params) => reportsApi.topCustomer.list(params),
    exportFn: reportsApi.topCustomer.exportExcel,
    buildParams: ({ date_from, date_to }) => ({
        date_from,
        date_to,
        limit: selectedLimit.value,
        sort: selectedSort.value,
        mode: reportMode.value,
        tipe_customer_id: selectedTipeCustomer.value,
        kategori_customer_id: selectedKategoriCustomer.value,
        source: selectedSource.value
    }),
    exportFilename: (params) => `laporan_top_customer_${params.date_from}.xlsx`,
    loadErrorLabel: 'top customer'
});

const activeFilterCount = computed(() => {
    let n = 0;
    if (startDate.value) n++;
    if (endDate.value) n++;
    if (selectedTipeCustomer.value) n++;
    if (selectedKategoriCustomer.value) n++;
    if (selectedSource.value !== 'all') n++;
    return n;
});

// B3.5 — drill-down: klik baris customer → daftar nota customer ybs (reuse sales-report list API).
const { drillVisible, drillLoading, drillItems, drillTitle, openDrillDown } = useSalesDrillDown();

function onRowClick(event) {
    const row = event.data;
    openDrillDown(
        {
            date_from: toDateString(startDate.value),
            date_to: toDateString(endDate.value),
            customer_id: row.customer_id,
            source: selectedSource.value !== 'all' ? selectedSource.value : undefined
        },
        `Nota Customer: ${row.customer_nama}`
    );
}

async function loadDropdowns() {
    try {
        const [tipeRes, kategoriRes] = await Promise.all([tipeCustomersApi.getList(), kategoriCustomersApi.getList()]);
        if (tipeRes.data.success) tipeCustomers.value = tipeRes.data.data.tipe_customers ?? [];
        if (kategoriRes.data.success) kategoriCustomers.value = kategoriRes.data.data.kategori_customers ?? [];
    } catch (e) {
        notify.apiError(e, 'Gagal load filter');
    }
}

onMounted(loadDropdowns);

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
                    <SelectButton v-model="reportMode" :options="modeOptions" optionLabel="label" optionValue="value" :allowEmpty="false" @change="loadData" />
                    <ListFiltersSheet :active-count="activeFilterCount">
                        <Select v-model="selectedLimit" :options="limitOptions" optionLabel="label" optionValue="value" @change="loadData" />
                        <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" @change="loadData" />
                        <Select v-model="selectedTipeCustomer" :options="tipeCustomers" optionLabel="nama_tipe" optionValue="id" placeholder="Tipe Customer" filter showClear @change="loadData" />
                        <Select v-model="selectedKategoriCustomer" :options="kategoriCustomers" optionLabel="nama_kategori" optionValue="id" placeholder="Kategori Customer" filter showClear @change="loadData" />
                        <Select v-model="selectedSource" :options="sourceOptions" optionLabel="label" optionValue="value" placeholder="Sumber" @change="loadData" />
                        <div class="list-filter-control">
                            <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                        </div>
                        <div class="list-filter-control">
                            <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                        </div>
                        <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                    </ListFiltersSheet>
                </div>
            </template>
        </Toolbar>

        <Message v-if="reportMode === 'net'" severity="info" :closable="false" class="mb-4">Mode Net: omzet sudah dikurangi retur (lock/approved) milik customer ybs</Message>

        <DataTable :value="items" :loading="loading" stripedRows rowHover @row-click="onRowClick" class="cursor-pointer">
            <template #empty>
                <div class="py-6 text-center text-surface-500">Belum ada data customer.</div>
                <div class="text-center text-xs text-surface-400 pb-4">Omzet = grand_total bruto</div>
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
            <Column field="omzet" bodyClass="text-right">
                <template #header>
                    <span v-tooltip.top="reportMode === 'net' ? 'Omzet Net = grand_total - retur (lock/approved)' : 'Omzet = grand_total bruto (belum potong retur)'">Omzet</span>
                </template>
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

        <!-- B3.5 — Drill-down: nota customer pada periode ybs -->
        <DetailDialog v-model:visible="drillVisible" :title="drillTitle" :loading="drillLoading" :show-audit="false" width="800px">
            <template #content>
                <DataTable :value="drillItems" stripedRows scrollable scrollHeight="420px">
                    <template #empty>
                        <div class="py-6 text-center text-surface-500">Tidak ada nota dalam periode ini.</div>
                    </template>
                    <Column field="nomor_dokumen" header="No. Invoice" />
                    <Column field="tanggal" header="Tanggal">
                        <template #body="{ data }">{{ formatDateTime(data.tanggal) }}</template>
                    </Column>
                    <Column header="Terminal">
                        <template #body="{ data }">{{ data.terminal?.kode_terminal || '-' }}</template>
                    </Column>
                    <Column field="grand_total" header="Grand Total" bodyClass="text-right">
                        <template #body="{ data }">{{ formatCurrency(data.grand_total) }}</template>
                    </Column>
                    <Column header="Status">
                        <template #body="{ data }">
                            <Tag :value="drillStatusLabel(data.receipt_status)" :severity="drillStatusSeverity(data.receipt_status)" />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </DetailDialog>
    </div>
</template>

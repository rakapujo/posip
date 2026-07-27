<script setup>
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import MoneySummaryPanel from '@/components/common/MoneySummaryPanel.vue';
import { ref, computed, onMounted } from 'vue';
import { reportsApi, salesProductReportApi, warehousesApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import { downloadBlob } from '@/utils/downloadBlob';

const authStore = useAuthStore();
const canExport = computed(() => authStore.can('laporan.export'));

const { formatCurrency, getPrimeDateFormatShort, toDateString } = useFormatters();
const notify = useNotification();
const { exporting: exportingPdf, exportListPdf } = useExportPdf();

const exportingExcel = ref(false);

const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const endDate = ref(new Date());
const selectedTerminal = ref(null);
const selectedWarehouse = ref(null);
const terminals = ref([]);
const warehouses = ref([]);

const summary = ref({});

const summaryItems = computed(() => [
    { label: 'Setor Awal', value: formatCurrency(summary.value.setor_awal || 0), tone: 'info', hint: 'Σ setor_awal di laci' },
    { label: 'Kas Masuk', value: formatCurrency(summary.value.kas_masuk || 0), tone: 'success', hint: 'Σ kas_masuk manual ke laci' },
    { label: 'Jual Tunai (Net)', value: formatCurrency(summary.value.penjualan_tunai_net || 0), tone: 'success', hint: 'Payment tunai − kembalian (bukan transfer/QRIS/kartu)' },
    { label: 'Bayar Piutang', value: formatCurrency(summary.value.bayar_piutang_cash || 0), tone: 'success', hint: 'Hanya total_bayar_cash piutang completed' },
    { label: 'Kas Keluar', value: formatCurrency(summary.value.kas_keluar_manual || 0), tone: 'orange', hint: 'Σ kas_keluar selain refund retur' },
    { label: 'Refund Tunai', value: formatCurrency(summary.value.refund_tunai || 0), tone: 'danger', hint: 'Σ kas_keluar “Refund retur…”' },
    { label: 'Net Cash Flow', value: formatCurrency(summary.value.net_cash_flow || 0), tone: 'primary', hint: 'Setor+Masuk+Jual tunai+Bayar piutang − Keluar − Refund' }
]);

const nonTunaiItems = computed(() => {
    const total = summary.value.penjualan_non_tunai;
    const byMetode = summary.value.non_tunai_by_metode;
    if (total == null && (!Array.isArray(byMetode) || !byMetode.length)) return [];
    const items = [
        {
            label: 'Total Non-Tunai',
            value: formatCurrency(total || 0),
            tone: 'info',
            hint: 'Info saja — tidak dihitung ke Net Cash'
        }
    ];
    if (Array.isArray(byMetode)) {
        byMetode.forEach((m) => {
            items.push({
                label: m.nama || m.nama_pembayaran || 'Metode',
                value: formatCurrency(m.nominal || 0),
                hint: 'Penjualan non-tunai'
            });
        });
    }
    return items;
});
const daily = ref({ loading: false, items: [] });

const activeFilterCount = computed(() => {
    let n = 0;
    if (startDate.value) n++;
    if (endDate.value) n++;
    if (selectedTerminal.value) n++;
    if (selectedWarehouse.value) n++;
    return n;
});

async function loadDropdowns() {
    try {
        const [r, whRes] = await Promise.all([salesProductReportApi.getDropdowns(), warehousesApi.getList()]);
        if (r.data.success) terminals.value = r.data.data.terminals ?? [];
        if (whRes.data.success) warehouses.value = whRes.data.data.warehouses ?? [];
    } catch (e) {
        notify.apiError(e, 'Gagal load filter');
    }
}

function baseParams() {
    const params = { date_from: toDateString(startDate.value), date_to: toDateString(endDate.value) };
    if (selectedTerminal.value) params.terminal_id = selectedTerminal.value;
    if (selectedWarehouse.value) params.warehouse_id = selectedWarehouse.value;
    return params;
}

async function loadAll() {
    const params = baseParams();
    await Promise.all([loadSummary(params), loadDaily(params)]);
}

async function loadSummary(params) {
    try {
        const r = await reportsApi.cashFlow.summary(params);
        if (r.data.success) Object.assign(summary.value, r.data.data);
    } catch (e) {
        notify.apiError(e, 'Gagal load summary');
    }
}

async function loadDaily(params) {
    daily.value.loading = true;
    try {
        const r = await reportsApi.cashFlow.daily(params);
        if (r.data.success) daily.value.items = r.data.data.items;
    } catch (e) {
        notify.apiError(e, 'Gagal load daily');
    } finally {
        daily.value.loading = false;
    }
}

onMounted(() => {
    loadDropdowns();
    loadAll();
});

// B3.1 — PDF export tabel harian (client-side, sama pola dengan Per Nota).
function exportDailyPdf() {
    const params = baseParams();
    exportListPdf({
        title: 'Laporan Arus Kas Harian',
        filename: `laporan_arus_kas_${params.date_from}`,
        columns: [
            { header: 'Tanggal', field: 'tanggal', width: 24 },
            { header: 'Setor Awal', width: 28, align: 'right', accessor: (r) => formatCurrency(r.setor_awal) },
            { header: 'Kas Masuk', width: 28, align: 'right', accessor: (r) => formatCurrency(r.kas_masuk) },
            { header: 'Jual Tunai', width: 28, align: 'right', accessor: (r) => formatCurrency(r.penjualan_tunai_net) },
            { header: 'Non-Tunai (info)', width: 30, align: 'right', accessor: (r) => formatCurrency(r.penjualan_non_tunai || 0) },
            { header: 'Bayar Piutang', width: 28, align: 'right', accessor: (r) => formatCurrency(r.bayar_piutang_cash || 0) },
            { header: 'Kas Keluar', width: 26, align: 'right', accessor: (r) => formatCurrency(r.kas_keluar_manual) },
            { header: 'Refund', width: 26, align: 'right', accessor: (r) => formatCurrency(r.refund_tunai) },
            { header: 'Net', width: 28, align: 'right', accessor: (r) => formatCurrency(r.net_cash_flow) }
        ],
        data: daily.value.items,
        totalLabel: `Net Cash Flow periode: ${formatCurrency(summary.value.net_cash_flow || 0)} · Non-Tunai (info): ${formatCurrency(summary.value.penjualan_non_tunai || 0)}`
    });
}

async function exportDailyExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const params = baseParams();
        const response = await reportsApi.cashFlow.exportDaily(params);
        downloadBlob(response.data, `laporan_arus_kas_${params.date_from}.xlsx`);
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Laporan Arus Kas Harian</span>
            </template>
            <template #end>
                <ListFiltersSheet :active-count="activeFilterCount">
                    <div class="list-filter-control">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <div class="list-filter-control">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <div class="list-filter-control">
                        <Select v-model="selectedTerminal" :options="terminals" optionLabel="nama_terminal" optionValue="id" placeholder="Terminal" filter showClear fluid @change="loadAll" />
                    </div>
                    <div class="list-filter-control">
                        <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Warehouse" filter showClear fluid @change="loadAll" />
                    </div>
                    <Button icon="pi pi-refresh" outlined @click="loadAll" aria-label="Refresh" />
                    <Button v-if="canExport" icon="pi pi-file-pdf" severity="secondary" outlined :loading="exportingPdf" @click="exportDailyPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportDailyExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                </ListFiltersSheet>
            </template>
        </Toolbar>

        <MoneySummaryPanel title="Arus Kas Fisik (Laci)" :items="summaryItems" :cols="7" :primary-index="6" />
        <p class="text-xs text-surface-500 -mt-2 mb-4">Hanya uang tunai di laci; transfer/QRIS/kartu lihat Laporan Performa → Metode Pembayaran.</p>
        <MoneySummaryPanel
            v-if="nonTunaiItems.length"
            title="Penjualan Non-Tunai (info)"
            :items="nonTunaiItems"
            :cols="Math.min(nonTunaiItems.length, 6)"
            :primary-index="0"
        />

        <!-- Daily -->
        <DataTable :value="daily.items" :loading="daily.loading" stripedRows scrollable scrollHeight="500px">
            <template #empty>
                <div class="py-6 text-center text-surface-500">Belum ada data.</div>
            </template>
            <Column field="tanggal" header="Tanggal" />
            <Column field="setor_awal" header="Setor Awal" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.setor_awal) }}</template>
            </Column>
            <Column field="kas_masuk" header="Kas Masuk" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.kas_masuk) }}</template>
            </Column>
            <Column field="penjualan_tunai_net" header="Jual Tunai" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.penjualan_tunai_net) }}</template>
            </Column>
            <Column field="penjualan_non_tunai" bodyClass="text-right">
                <template #header>
                    <span v-tooltip.top="'Info saja — tidak dihitung ke Net Cash'">Non-Tunai (info)</span>
                </template>
                <template #body="{ data }">
                    <span class="text-surface-400">{{ formatCurrency(data.penjualan_non_tunai || 0) }}</span>
                </template>
            </Column>
            <Column field="bayar_piutang_cash" header="Bayar Piutang" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.bayar_piutang_cash || 0) }}</template>
            </Column>
            <Column field="kas_keluar_manual" header="Kas Keluar" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="text-orange-600">({{ formatCurrency(data.kas_keluar_manual) }})</span>
                </template>
            </Column>
            <Column field="refund_tunai" header="Refund" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="text-red-600">({{ formatCurrency(data.refund_tunai) }})</span>
                </template>
            </Column>
            <Column field="net_cash_flow" header="Net" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-bold" :class="data.net_cash_flow >= 0 ? 'text-green-700' : 'text-red-700'">
                        {{ formatCurrency(data.net_cash_flow) }}
                    </span>
                </template>
            </Column>
        </DataTable>
    </div>
</template>

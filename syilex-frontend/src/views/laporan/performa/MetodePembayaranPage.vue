<script setup>
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import MoneySummaryPanel from '@/components/common/MoneySummaryPanel.vue';
import DetailDialog from '@/components/common/DetailDialog.vue';
import { ref, computed, onMounted } from 'vue';
import { reportsApi, salesReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportAnalytic } from '@/composables/useReportAnalytic';
import { useNotification } from '@/composables/useNotification';
import { useSalesDrillDown, drillStatusLabel, drillStatusSeverity } from '@/composables/useSalesDrillDown';

const { formatCurrency, formatDateTime } = useFormatters();
const notify = useNotification();
const grandTotal = ref(0);
const selectedTerminal = ref(null);
const selectedSource = ref('all'); // B3.5 — default 'all' (dulu tidak difilter)
const terminals = ref([]);

const sourceOptions = [
    { label: 'Semua', value: 'all' },
    { label: 'POS', value: 'pos' },
    { label: 'Manual (BO)', value: 'manual' }
];

const { canExport, exportingExcel, loading, items, summary, startDate, endDate, getPrimeDateFormatShort, toDateString, loadData, exportExcel } = useReportAnalytic({
    fetchList: (params) => reportsApi.paymentMethod.breakdown(params),
    exportFn: reportsApi.paymentMethod.exportExcel,
    buildParams: ({ date_from, date_to }) => ({ date_from, date_to, terminal_id: selectedTerminal.value, source: selectedSource.value }),
    exportFilename: (params) => `laporan_metode_pembayaran_${params.date_from}.xlsx`,
    loadErrorLabel: 'metode pembayaran',
    onListLoaded: (payload) => {
        grandTotal.value = payload.grand_total ?? 0;
    }
});

// B3.5 — drill-down: klik baris metode → daftar nota dengan metode ybs (piutang tidak bisa drill, no metode_id).
const { drillVisible, drillLoading, drillItems, drillTitle, openDrillDown } = useSalesDrillDown();

function onRowClick(event) {
    const row = event.data;
    if (!row.metode_id) return; // baris "Bayar Piutang" — bukan doc_sales_payments, tidak bisa drill via sales-report
    openDrillDown(
        {
            date_from: toDateString(startDate.value),
            date_to: toDateString(endDate.value),
            terminal_id: selectedTerminal.value,
            metode_bayar_id: row.metode_id,
            source: selectedSource.value !== 'all' ? selectedSource.value : undefined
        },
        `Nota — ${row.nama_pembayaran}`
    );
}

async function loadDropdowns() {
    try {
        const r = await salesReportApi.getDropdowns();
        if (r.data.success) terminals.value = r.data.data.terminals ?? [];
    } catch (e) {
        notify.apiError(e, 'Gagal load filter');
    }
}

onMounted(loadDropdowns);

const summaryItems = computed(() => {
    const s = summary.value || {};
    const diterima = s.tunai_diterima ?? s.tunai_nominal ?? 0;
    const kembalian = s.kembalian ?? 0;
    const tunaiNet = s.tunai_net ?? diterima - kembalian;
    return [
        { label: 'Tunai diterima', value: formatCurrency(diterima), tone: 'success', hint: `Σ nominal tunai · ${s.tunai_trx || 0} trx` },
        { label: 'Kembalian', value: formatCurrency(kembalian), tone: 'warn', hint: 'Σ kembalian ke customer' },
        { label: 'Tunai net', value: formatCurrency(tunaiNet), tone: 'success', hint: 'Diterima − kembalian (= Arus Kas Jual Tunai Net)' },
        { label: 'Non-Tunai', value: formatCurrency(s.non_tunai_nominal || 0), tone: 'info', hint: `${s.non_tunai_trx || 0} trx · transfer/QRIS/kartu` },
        { label: 'Biaya Tambahan', value: formatCurrency(s.biaya_total || 0), tone: 'orange', hint: 'Fee metode pembayaran' },
        { label: 'Grand Total', value: formatCurrency(grandTotal.value), tone: 'primary', hint: 'Σ tender (tunai diterima + non-tunai)' }
    ];
});

const activeFilterCount = computed(() => {
    let n = 0;
    if (startDate.value) n++;
    if (endDate.value) n++;
    if (selectedTerminal.value) n++;
    return n;
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Breakdown Metode Pembayaran</span>
            </template>
            <template #end>
                <ListFiltersSheet :active-count="activeFilterCount">
                    <div class="list-filter-control">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <div class="list-filter-control">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <Select v-model="selectedTerminal" :options="terminals" optionLabel="nama_terminal" optionValue="id" placeholder="Terminal" filter showClear @change="loadData" />
                    <Select v-model="selectedSource" :options="sourceOptions" optionLabel="label" optionValue="value" placeholder="Sumber" @change="loadData" />
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                </ListFiltersSheet>
            </template>
        </Toolbar>

        <MoneySummaryPanel title="Ringkasan Metode Pembayaran" :items="summaryItems" :cols="6" :primary-index="2" />

        <DataTable :value="items" :loading="loading" stripedRows rowHover @row-click="onRowClick" :rowClass="(data) => (!data.metode_id ? 'opacity-60' : 'cursor-pointer')">
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

        <!-- B3.5 — Drill-down: nota dengan metode pembayaran ybs -->
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
                    <Column header="Customer">
                        <template #body="{ data }">{{ data.customer?.nama || 'Walk-in' }}</template>
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

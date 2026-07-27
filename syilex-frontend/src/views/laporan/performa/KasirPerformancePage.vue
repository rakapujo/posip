<script setup>
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import DetailDialog from '@/components/common/DetailDialog.vue';
import { ref, computed, onMounted } from 'vue';
import { reportsApi, salesReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportAnalytic } from '@/composables/useReportAnalytic';
import { useNotification } from '@/composables/useNotification';
import { useSalesDrillDown, drillStatusLabel, drillStatusSeverity } from '@/composables/useSalesDrillDown';

const { formatCurrency, formatDateTime } = useFormatters();
const notify = useNotification();

const selectedSort = ref('omzet_desc');
const reportMode = ref('bruto');
const selectedTerminal = ref(null);
const selectedUser = ref(null);
const selectedSource = ref('pos'); // B3.5 — default 'pos' (backward compat)
const terminals = ref([]);
const users = ref([]);

const sortOptions = [
    { label: 'Omzet Terbesar', value: 'omzet_desc' },
    { label: 'Omzet Terkecil', value: 'omzet_asc' },
    { label: 'Transaksi Terbanyak', value: 'trx_desc' },
    { label: 'Void Terbanyak', value: 'void_desc' },
    { label: 'Retur Terbanyak', value: 'retur_desc' }
];

const modeOptions = [
    { label: 'Bruto', value: 'bruto' },
    { label: 'Net', value: 'net' }
];

const sourceOptions = [
    { label: 'POS', value: 'pos' },
    { label: 'Manual (BO)', value: 'manual' },
    { label: 'Semua', value: 'all' }
];

const { canExport, exportingExcel, loading, items, startDate, endDate, getPrimeDateFormatShort, toDateString, loadData, exportExcel } = useReportAnalytic({
    fetchList: (params) => reportsApi.kasirPerformance.list(params),
    exportFn: reportsApi.kasirPerformance.exportExcel,
    buildParams: ({ date_from, date_to }) => ({
        date_from,
        date_to,
        sort: selectedSort.value,
        mode: reportMode.value,
        terminal_id: selectedTerminal.value,
        user_id: selectedUser.value,
        source: selectedSource.value
    }),
    exportFilename: (params) => `laporan_kasir_performance_${params.date_from}.xlsx`,
    loadErrorLabel: 'performance kasir'
});

const activeFilterCount = computed(() => {
    let n = 0;
    if (startDate.value) n++;
    if (endDate.value) n++;
    if (selectedTerminal.value) n++;
    if (selectedUser.value) n++;
    if (selectedSource.value !== 'pos') n++;
    return n;
});

// B3.5 — drill-down: klik baris kasir → daftar nota periode ybs (reuse sales-report list API).
const { drillVisible, drillLoading, drillItems, drillTitle, openDrillDown } = useSalesDrillDown();

function onRowClick(event) {
    const row = event.data;
    openDrillDown(
        {
            date_from: toDateString(startDate.value),
            date_to: toDateString(endDate.value),
            user_id: row.user_id,
            source: selectedSource.value !== 'all' ? selectedSource.value : undefined
        },
        `Nota Kasir: ${row.user_name}`
    );
}

async function loadDropdowns() {
    try {
        const r = await salesReportApi.getDropdowns();
        if (r.data.success) {
            terminals.value = r.data.data.terminals ?? [];
            users.value = r.data.data.users ?? [];
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
                <span class="text-xl font-semibold">Performance Kasir</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <SelectButton v-model="reportMode" :options="modeOptions" optionLabel="label" optionValue="value" :allowEmpty="false" @change="loadData" />
                    <ListFiltersSheet :active-count="activeFilterCount">
                        <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" @change="loadData" />
                        <Select v-model="selectedTerminal" :options="terminals" optionLabel="nama_terminal" optionValue="id" placeholder="Terminal" filter showClear @change="loadData" />
                        <Select v-model="selectedUser" :options="users" optionLabel="name" optionValue="id" placeholder="Kasir" filter showClear @change="loadData" />
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

        <Message v-if="reportMode === 'net'" severity="info" :closable="false" class="mb-4">Mode Net: omzet sudah dikurangi retur (lock/approved) milik kasir ybs</Message>
        <Message severity="info" :closable="false" class="mb-4">Kolom Diskon = diskon nota + diskon per baris item (stacked), diambil dari komponen diskon grand_total.</Message>

        <DataTable :value="items" :loading="loading" stripedRows scrollable rowHover @row-click="onRowClick" class="cursor-pointer">
            <template #empty>
                <div class="py-6 text-center text-surface-500">Tidak ada data transaksi dalam periode ini.</div>
                <div class="text-center text-xs text-surface-400 pb-4">Omzet = grand_total bruto</div>
            </template>
            <Column header="Kasir">
                <template #body="{ data }">
                    <div class="font-medium">{{ data.user_name }}</div>
                </template>
            </Column>
            <Column field="trx_completed" header="Trx OK" bodyClass="text-right" />
            <Column field="trx_voided" header="Void" bodyClass="text-right">
                <template #body="{ data }">
                    <span :class="data.trx_voided > 5 ? 'text-red-600 font-bold' : ''">{{ data.trx_voided }}</span>
                </template>
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
            <Column field="diskon_total" header="Diskon" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.diskon_total) }}</template>
            </Column>
            <Column field="retur_count" header="Retur">
                <template #body="{ data }">
                    <div class="text-right">{{ data.retur_count }}</div>
                    <div class="text-xs text-right text-surface-500">{{ formatCurrency(data.retur_nominal) }}</div>
                </template>
            </Column>
            <Column header="Shift">
                <template #body="{ data }">
                    <div>{{ data.shift_total }} shift</div>
                    <div v-if="data.shift_paksa > 0" class="text-xs text-orange-600">{{ data.shift_paksa }} paksa</div>
                    <div v-if="data.shift_selisih !== 0" class="text-xs" :class="data.shift_selisih < 0 ? 'text-red-600' : 'text-green-600'">
                        {{ formatCurrency(data.shift_selisih) }}
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- B3.5 — Drill-down: nota kasir pada periode ybs -->
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

<script setup>
import { ref, computed, onMounted } from 'vue';
import { shiftsApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useShiftReport } from '@/composables/useShiftReport';
import { usePrintAdapter } from '@/composables/print/usePrintAdapter';
import { useReceiptEscPos } from '@/composables/useReceiptEscPos';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import RowActionButtons from '@/components/common/RowActionButtons.vue';
import ShiftReportDialog from '@/components/pos/ShiftReportDialog.vue';

const { formatDateTime, formatCurrency, getPrimeDateFormatShort, toDateString } = useFormatters();
const notify = useNotification();
const printAdapter = usePrintAdapter();
const escpos = useReceiptEscPos();

const { shiftReportDialog, shiftReportData, loadingShiftReport, loadShiftReport, printShiftReport: browserPrintShiftReport, downloadShiftReportPdf, closeShiftReport } = useShiftReport();

const printShiftReport = async () => {
    if (!shiftReportData.value) {
        notify.warn('Data laporan shift tidak tersedia');
        return;
    }
    if (printAdapter.isReadyToThermal()) {
        try {
            await printAdapter.reconnect();
            const bytes = escpos.buildShiftReport(shiftReportData.value, { charWidth: 42, feedLines: 4, compact: false });
            const result = await printAdapter.printRaw(bytes);
            if (result.success) return;
        } catch (e) {
            console.warn('[printShiftReport] thermal failed', e);
        }
    }
    await browserPrintShiftReport();
};

const items = ref([]);
const loading = ref(false);
const totalRecords = ref(0);
const searchQuery = ref('');
const selectedStatus = ref(null);
const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const endDate = ref(new Date());

const activeTab = ref('per_shift');
const dailySummary = ref({ loading: false, items: [] });

const statusOptions = ref([
    { label: 'Semua Status', value: null },
    { label: 'Aktif', value: 'active' },
    { label: 'Selesai', value: 'ended' },
    { label: 'Ditutup Paksa', value: 'forced' }
]);

const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'started_at',
    sortOrder: -1
});

const activeFilterCount = computed(() => {
    let n = 0;
    if (selectedStatus.value) n++;
    if (startDate.value && !isDefaultMonthStart(startDate.value)) n++;
    if (endDate.value && !isDefaultToday(endDate.value)) n++;
    return n;
});

function isDefaultMonthStart(d) {
    const now = new Date();
    return toDateString(d) === toDateString(new Date(now.getFullYear(), now.getMonth(), 1));
}

function isDefaultToday(d) {
    return toDateString(d) === toDateString(new Date());
}

async function loadData() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || 'started_at',
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };

        if (searchQuery.value?.trim()) {
            params.search = searchQuery.value.trim();
        }
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }
        if (startDate.value) {
            params.start_date = toDateString(startDate.value);
        }
        if (endDate.value) {
            params.end_date = toDateString(endDate.value);
        }

        const response = await shiftsApi.getAll(params);
        if (response.data.success) {
            items.value = response.data.data.shifts || [];
            totalRecords.value = response.data.data.pagination?.total || 0;
        }
    } catch {
        notify.loadListError('shift');
    } finally {
        loading.value = false;
    }
}

async function loadDailySummary() {
    dailySummary.value.loading = true;
    try {
        const params = {};
        if (startDate.value) params.date_from = toDateString(startDate.value);
        if (endDate.value) params.date_to = toDateString(endDate.value);

        const response = await shiftsApi.getDailySummary(params);
        if (response.data.success) {
            dailySummary.value.items = response.data.data.items || [];
        }
    } catch (error) {
        notify.apiError(error, 'Gagal load ringkasan harian');
    } finally {
        dailySummary.value.loading = false;
    }
}

function onTabChange(tab) {
    activeTab.value = tab;
    if (tab === 'per_tanggal' && dailySummary.value.items.length === 0) {
        loadDailySummary();
    }
}

function doSearch() {
    lazyParams.value.first = 0;
    loadData();
}

function clearSearch() {
    searchQuery.value = '';
    doSearch();
}

function onFilterChange() {
    lazyParams.value.first = 0;
    loadData();
    if (activeTab.value === 'per_tanggal') {
        dailySummary.value.items = [];
        loadDailySummary();
    }
}

function resetFilters() {
    selectedStatus.value = null;
    startDate.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    endDate.value = new Date();
    lazyParams.value.first = 0;
    loadData();
    if (activeTab.value === 'per_tanggal') {
        dailySummary.value.items = [];
        loadDailySummary();
    }
}

function onPage(event) {
    lazyParams.value.first = event.first;
    lazyParams.value.rows = event.rows;
    loadData();
}

function onSort(event) {
    lazyParams.value.sortField = event.sortField;
    lazyParams.value.sortOrder = event.sortOrder;
    loadData();
}

function getShiftStatus(shift) {
    if (!shift.ended_at) return 'Aktif';
    if (shift.ended_by_force) return 'Ditutup Paksa';
    return 'Selesai';
}

function getShiftSeverity(shift) {
    if (!shift.ended_at) return 'info';
    if (shift.ended_by_force) return 'danger';
    return 'success';
}

function getDuration(shift) {
    if (!shift.started_at) return '-';
    try {
        const start = new Date(shift.started_at);
        const end = shift.ended_at ? new Date(shift.ended_at) : new Date();
        if (isNaN(start.getTime()) || isNaN(end.getTime())) return '-';
        const diffMs = end.getTime() - start.getTime();
        if (diffMs < 0) return '-';
        const hours = Math.floor(diffMs / 3600000);
        const minutes = Math.floor((diffMs % 3600000) / 60000);
        if (hours > 0) return `${hours}j ${minutes}m`;
        return `${minutes}m`;
    } catch {
        return '-';
    }
}

function getClosedBy(shift) {
    if (!shift.ended_at) return '-';
    if (shift.ended_by_force) {
        return shift.forced_by_user?.name || 'Admin';
    }
    return shift.user?.name || '-';
}

function viewShiftReport(shift) {
    loadShiftReport(shift.ulid);
}

onMounted(() => {
    loadData();
    printAdapter.checkStatus();
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Riwayat Shift</span>
            </template>
            <template #end>
                <ListFiltersSheet :active-count="activeFilterCount">
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" filter showClear @change="onFilterChange" />
                    <div class="list-filter-control">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilterChange" />
                    </div>
                    <div class="list-filter-control">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilterChange" />
                    </div>
                    <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                </ListFiltersSheet>
            </template>
        </Toolbar>

        <div class="mb-4 border-b border-surface-200 dark:border-surface-700 flex gap-1">
            <button
                class="px-4 py-2 text-sm font-medium border-b-2 transition"
                :class="activeTab === 'per_shift' ? 'border-primary text-primary' : 'border-transparent text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100'"
                type="button"
                @click="onTabChange('per_shift')"
            >
                <i class="pi pi-list mr-1"></i> Per Shift
            </button>
            <button
                class="px-4 py-2 text-sm font-medium border-b-2 transition"
                :class="activeTab === 'per_tanggal' ? 'border-primary text-primary' : 'border-transparent text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100'"
                type="button"
                @click="onTabChange('per_tanggal')"
            >
                <i class="pi pi-calendar mr-1"></i> Per Tanggal (Konsolidasi)
            </button>
        </div>

        <div v-if="activeTab === 'per_tanggal'">
            <DataTable :value="dailySummary.items" :loading="dailySummary.loading" dataKey="tanggal" stripedRows responsiveLayout="scroll">
                <template #empty>
                    <div class="flex items-center justify-center py-8 text-surface-500">
                        <i class="pi pi-calendar mr-2"></i>
                        Belum ada data. Sesuaikan filter tanggal lalu muat ulang.
                    </div>
                </template>
                <Column field="tanggal" header="Tanggal" style="min-width: 130px">
                    <template #body="{ data }">
                        <span class="font-medium">{{ data.tanggal }}</span>
                    </template>
                </Column>
                <Column field="kode_terminal" header="Terminal" style="min-width: 140px">
                    <template #body="{ data }">
                        <div>
                            <div class="font-medium">{{ data.kode_terminal }}</div>
                            <div class="text-xs text-surface-500">{{ data.nama_terminal }}</div>
                        </div>
                    </template>
                </Column>
                <Column field="shift_count" header="Jumlah Shift" style="min-width: 110px" bodyClass="text-right">
                    <template #body="{ data }">
                        {{ data.shift_count }}
                        <Tag v-if="data.shift_paksa_count > 0" :value="`${data.shift_paksa_count} paksa`" severity="warn" class="ml-2" />
                    </template>
                </Column>
                <Column field="omzet_total" header="Omzet" style="min-width: 150px" bodyClass="text-right">
                    <template #body="{ data }">{{ formatCurrency(data.omzet_total) }}</template>
                </Column>
                <Column field="omzet_per_shift" header="Rata-rata/Shift" style="min-width: 150px" bodyClass="text-right">
                    <template #body="{ data }">{{ formatCurrency(data.omzet_per_shift) }}</template>
                </Column>
                <Column field="total_selisih" header="Selisih" style="min-width: 120px" bodyClass="text-right">
                    <template #body="{ data }">
                        <span :class="data.total_selisih < 0 ? 'text-red-600' : data.total_selisih > 0 ? 'text-green-600' : 'text-surface-500'">
                            {{ formatCurrency(data.total_selisih) }}
                        </span>
                    </template>
                </Column>
            </DataTable>
        </div>

        <DataTable
            v-if="activeTab === 'per_shift'"
            :value="items"
            :loading="loading"
            :lazy="true"
            :totalRecords="totalRecords"
            :rows="lazyParams.rows"
            :first="lazyParams.first"
            :sortField="lazyParams.sortField"
            :sortOrder="lazyParams.sortOrder"
            :rowsPerPageOptions="[10, 25, 50]"
            paginator
            dataKey="ulid"
            stripedRows
            responsiveLayout="scroll"
            @page="onPage"
            @sort="onSort"
        >
            <template #header>
                <DataTableHeader v-model="searchQuery" title="Daftar Shift" placeholder="Cari terminal, user..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="flex items-center justify-center py-8 text-surface-500">
                    <i class="pi pi-clock mr-2"></i>
                    Belum ada data shift
                </div>
            </template>

            <Column field="terminal" header="Terminal" :sortable="false">
                <template #body="{ data }">
                    <div class="font-medium">{{ data.terminal?.kode_terminal }}</div>
                    <div class="text-sm text-surface-500">{{ data.terminal?.nama_terminal }}</div>
                </template>
            </Column>

            <Column field="user" header="User" :sortable="false">
                <template #body="{ data }">
                    {{ data.user?.name || '-' }}
                </template>
            </Column>

            <Column field="started_at" header="Mulai" sortable>
                <template #body="{ data }">
                    {{ formatDateTime(data.started_at) }}
                </template>
            </Column>

            <Column field="ended_at" header="Selesai" sortable>
                <template #body="{ data }">
                    {{ data.ended_at ? formatDateTime(data.ended_at) : '-' }}
                </template>
            </Column>

            <Column header="Durasi" :sortable="false">
                <template #body="{ data }">
                    {{ getDuration(data) }}
                </template>
            </Column>

            <Column header="Status" :sortable="false">
                <template #body="{ data }">
                    <Tag :value="getShiftStatus(data)" :severity="getShiftSeverity(data)" />
                </template>
            </Column>

            <Column header="Ditutup Oleh" :sortable="false">
                <template #body="{ data }">
                    <div v-if="data.ended_at">
                        <span>{{ getClosedBy(data) }}</span>
                        <span v-if="data.ended_by_force" class="text-xs text-red-500 ml-1">(paksa)</span>
                    </div>
                    <span v-else class="text-surface-400">-</span>
                </template>
            </Column>

            <Column header="Aksi" :sortable="false" style="min-width: 80px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <RowActionButtons>
                        <Button icon="pi pi-eye" severity="info" text rounded size="small" @click="viewShiftReport(data)" v-tooltip.top="'Lihat Laporan'" aria-label="Lihat Laporan"  />
                    </RowActionButtons>
                </template>
            </Column>
        </DataTable>

        <ShiftReportDialog v-model:visible="shiftReportDialog" :data="shiftReportData" :loading="loadingShiftReport" @print="printShiftReport" @download="downloadShiftReportPdf" @close="closeShiftReport" />
    </div>
</template>

<script setup>
import { customerPiutangsApi, customersApi } from '@/api';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import MoneySummaryPanel from '@/components/common/MoneySummaryPanel.vue';
import AgingBucketPanel from '@/components/common/AgingBucketPanel.vue';
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import RowActionButtons from '@/components/common/RowActionButtons.vue';
import { onMounted, ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';

const notify = useNotification();
const router = useRouter();
const authStore = useAuthStore();
const { formatCurrency, formatDateTime, getPrimeDateFormatShort, toDateString, todayString, now, parseDateTime, isBeforeNow } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();

// Permissions
const canViewNominal = computed(() => authStore.can('piutang.view_nominal'));
const canExport = computed(() => authStore.can('laporan.export') && authStore.can('piutang.view'));
const canCreatePembayaran = computed(() => authStore.can('pembayaran-piutang.create'));

// Data
const items = ref([]);
const loading = ref(false);
const totalRecords = ref(0);
const summary = ref({});

const summaryItems = computed(() => [
    { label: 'Total Piutang Outstanding', value: canViewNominal.value ? formatCurrency(summary.value.total_piutang || 0) : '-', tone: 'info' },
    { label: 'Belum Bayar', value: String(summary.value.total_unpaid || 0), tone: 'danger' },
    { label: 'Sebagian Terbayar', value: String(summary.value.total_partial || 0), tone: 'warn' },
    { label: 'Jatuh Tempo', value: String(summary.value.total_overdue || 0), tone: 'orange' }
]);
const aging = ref({ loading: false, total_piutang_outstanding: 0, total_count: 0, buckets: {} });
const selectedAgingBucket = ref(null); // 'belum_tempo' | 'b1_30' | 'b31_60' | 'b61_90' | 'above_90'

// Filters
const customers = ref([]);
const searchQuery = ref('');
const selectedCustomer = ref(null);
const selectedStatus = ref(null);
const selectedDueWithinDays = ref(null);
const selectedOverdueWithinDays = ref(null);
const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const endDate = ref(new Date());

// Days filter options for "Tempo dalam..."
const dueWithinOptions = [
    { label: 'Semua Belum Tempo', value: 'all' },
    { label: '1 Hari', value: 1 },
    { label: '7 Hari', value: 7 },
    { label: '14 Hari', value: 14 },
    { label: '15 Hari', value: 15 },
    { label: '21 Hari', value: 21 },
    { label: '30 Hari', value: 30 },
    { label: '31 Hari', value: 31 }
];

// Days filter options for "Overdue..."
const overdueOptions = [
    { label: 'Semua Overdue', value: 'all' },
    { label: '1 Hari', value: 1 },
    { label: '7 Hari', value: 7 },
    { label: '14 Hari', value: 14 },
    { label: '15 Hari', value: 15 },
    { label: '21 Hari', value: 21 },
    { label: '30 Hari', value: 30 },
    { label: '31 Hari', value: 31 }
];

// Pagination
const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'tanggal',
    sortOrder: -1
});

// Detail dialog
const detailDialog = ref(false);
const loadingDetail = ref(false);
const detailData = ref({});

// Status options
const statusOptions = [
    { label: 'Outstanding (Belum Lunas)', value: 'outstanding' },
    { label: 'Belum Bayar', value: 'unpaid' },
    { label: 'Sebagian', value: 'partial' },
    { label: 'Lunas', value: 'paid' }
];

onMounted(async () => {
    await loadCustomers();
    await Promise.all([loadData(), loadSummary(), loadAging()]);
});

function isDefaultMonthStart(d) {
    const now = new Date();
    return toDateString(d) === toDateString(new Date(now.getFullYear(), now.getMonth(), 1));
}

function isDefaultToday(d) {
    return toDateString(d) === toDateString(new Date());
}

const activeFilterCount = computed(() => {
    let n = 0;
    if (selectedCustomer.value) n++;
    if (selectedStatus.value) n++;
    if (selectedDueWithinDays.value != null) n++;
    if (selectedOverdueWithinDays.value != null) n++;
    if (startDate.value && !isDefaultMonthStart(startDate.value)) n++;
    if (endDate.value && !isDefaultToday(endDate.value)) n++;
    return n;
});

async function loadCustomers() {
    try {
        const response = await customersApi.getList({ jenis: 'spesifik' });
        if (response.data.success) {
            customers.value = response.data.data.customers;
        }
    } catch (error) {
        console.error('Failed to load customers:', error);
        notify.apiError(error, 'Gagal load customers');
    }
}

async function loadData() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || 'tanggal',
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };

        if (searchQuery.value?.trim()) {
            params.search = searchQuery.value.trim();
        }
        if (selectedCustomer.value) {
            params.customer_id = selectedCustomer.value;
        }
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }
        if (selectedAgingBucket.value) {
            params.aging_bucket = selectedAgingBucket.value;
        } else {
            if (selectedDueWithinDays.value) {
                params.due_within_days = selectedDueWithinDays.value;
            }
            if (selectedOverdueWithinDays.value) {
                params.overdue_within_days = selectedOverdueWithinDays.value;
            }
        }
        if (startDate.value) {
            params.date_from = toDateString(startDate.value);
        }
        if (endDate.value) {
            params.date_to = toDateString(endDate.value);
        }

        const response = await customerPiutangsApi.getAll(params);
        if (response.data.success) {
            items.value = response.data.data.items;
            totalRecords.value = response.data.data.pagination?.total || 0;
        }
    } catch (error) {
        console.error('Failed to load piutang:', error);
        notify.loadListError('piutang');
    } finally {
        loading.value = false;
    }
}

async function loadSummary() {
    try {
        const response = await customerPiutangsApi.getSummary(buildFilterParams());
        if (response.data.success) {
            summary.value = response.data.data.summary;
        }
    } catch (error) {
        console.error('Failed to load summary:', error);
        notify.apiError(error, 'Gagal load summary');
    }
}

async function loadAging() {
    if (!canViewNominal.value) return;
    aging.value.loading = true;
    try {
        const params = buildFilterParams();

        const response = await customerPiutangsApi.getAgingSummary(params);
        if (response.data.success) {
            aging.value.total_piutang_outstanding = response.data.data.total_piutang_outstanding;
            aging.value.total_count = response.data.data.total_count;
            aging.value.buckets = response.data.data.buckets;
        }
    } catch (error) {
        console.error('Failed to load aging:', error);
        notify.apiError(error, 'Gagal load aging summary');
    } finally {
        aging.value.loading = false;
    }
}

function selectAgingBucket(key) {
    const next = selectedAgingBucket.value === key ? null : key;
    selectedAgingBucket.value = next;
    selectedDueWithinDays.value = null;
    selectedOverdueWithinDays.value = null;
    lazyParams.value.first = 0;
    loadData();
}

// Static Tailwind class strings (JIT cannot detect template interpolation)
const agingBucketConfig = [
    {
        key: 'belum_tempo',
        label: 'Belum Tempo',
        bg: 'bg-blue-50 dark:bg-blue-900/20 hover:ring-blue-400',
        ring: 'ring-2 ring-blue-500',
        text: 'text-blue-600 dark:text-blue-400'
    },
    {
        key: 'b1_30',
        label: '1-30 hari',
        bg: 'bg-green-50 dark:bg-green-900/20 hover:ring-green-400',
        ring: 'ring-2 ring-green-500',
        text: 'text-green-600 dark:text-green-400'
    },
    {
        key: 'b31_60',
        label: '31-60 hari',
        bg: 'bg-yellow-50 dark:bg-yellow-900/20 hover:ring-yellow-400',
        ring: 'ring-2 ring-yellow-500',
        text: 'text-yellow-600 dark:text-yellow-400'
    },
    {
        key: 'b61_90',
        label: '61-90 hari',
        bg: 'bg-orange-50 dark:bg-orange-900/20 hover:ring-orange-400',
        ring: 'ring-2 ring-orange-500',
        text: 'text-orange-600 dark:text-orange-400'
    },
    {
        key: 'above_90',
        label: '> 90 hari',
        bg: 'bg-red-50 dark:bg-red-900/20 hover:ring-red-400',
        ring: 'ring-2 ring-red-500',
        text: 'text-red-600 dark:text-red-400'
    }
];

const agingBucketItems = computed(() =>
    agingBucketConfig.map((b) => ({
        ...b,
        value: formatCurrency(aging.value.buckets[b.key]?.nominal || 0),
        meta: `${aging.value.buckets[b.key]?.count || 0} piutang · ${aging.value.buckets[b.key]?.percent || 0}%`
    }))
);

function onPage(event) {
    lazyParams.value = { ...lazyParams.value, ...event };
    loadData();
}

function onSort(event) {
    lazyParams.value = { ...lazyParams.value, ...event };
    loadData();
}

function onSearch() {
    lazyParams.value.first = 0;
    loadData();
}

function onFilterChange() {
    lazyParams.value.first = 0;
    loadData();
    loadSummary();
    loadAging();
}

function clearSearch() {
    searchQuery.value = '';
    lazyParams.value.first = 0;
    loadData();
}

function resetFilters() {
    searchQuery.value = '';
    selectedCustomer.value = null;
    selectedStatus.value = null;
    selectedDueWithinDays.value = null;
    selectedOverdueWithinDays.value = null;
    selectedAgingBucket.value = null;
    startDate.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    endDate.value = new Date();
    lazyParams.value.first = 0;
    loadData();
    loadSummary();
    loadAging();
}

function onDueWithinChange() {
    selectedAgingBucket.value = null;
    // Clear overdue filter when selecting due within (mutually exclusive)
    if (selectedDueWithinDays.value) {
        selectedOverdueWithinDays.value = null;
    }
    onFilterChange();
}

function onOverdueWithinChange() {
    selectedAgingBucket.value = null;
    // Clear due within filter when selecting overdue (mutually exclusive)
    if (selectedOverdueWithinDays.value) {
        selectedDueWithinDays.value = null;
    }
    onFilterChange();
}

async function viewDetail(data) {
    detailDialog.value = true;
    loadingDetail.value = true;

    try {
        const response = await customerPiutangsApi.get(data.ulid);
        if (response.data.success) {
            detailData.value = response.data.data.piutang;
        }
    } catch (error) {
        console.error('Failed to load detail:', error);
        notify.loadDetailError('piutang');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

function getStatusSeverity(status) {
    switch (status) {
        case 'unpaid':
            return 'danger';
        case 'partial':
            return 'warn';
        case 'paid':
            return 'success';
        default:
            return 'secondary';
    }
}

function getStatusLabel(status) {
    switch (status) {
        case 'unpaid':
            return 'Belum Bayar';
        case 'partial':
            return 'Sebagian';
        case 'paid':
            return 'Lunas';
        default:
            return status;
    }
}

function isOverdue(item) {
    if (!item.tanggal_jatuh_tempo || item.status === 'paid') return false;
    const due = toDateString(item.tanggal_jatuh_tempo);
    return due != null && due < todayString();
}

function getDaysUntilDue(item) {
    if (!item.tanggal_jatuh_tempo) return null;
    const today = now();
    const dueDate = parseDateTime(item.tanggal_jatuh_tempo);
    const diffTime = dueDate - today;
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return diffDays;
}

function buildFilterParams() {
    const params = {};
    if (searchQuery.value?.trim()) params.search = searchQuery.value.trim();
    if (selectedCustomer.value) params.customer_id = selectedCustomer.value;
    if (selectedStatus.value) params.status = selectedStatus.value;
    if (selectedAgingBucket.value) {
        params.aging_bucket = selectedAgingBucket.value;
    } else {
        if (selectedDueWithinDays.value) params.due_within_days = selectedDueWithinDays.value;
        if (selectedOverdueWithinDays.value) params.overdue_within_days = selectedOverdueWithinDays.value;
    }
    if (startDate.value) params.date_from = toDateString(startDate.value);
    if (endDate.value) params.date_to = toDateString(endDate.value);
    return params;
}

function payPiutang(data) {
    if (data.status === 'paid') return;
    const customerId = customers.value.find((c) => c.ulid === data.customer?.ulid)?.id;
    if (!customerId) return;
    router.push({
        name: 'penjualan-pembayaran-piutang-create',
        query: {
            customer_id: String(customerId),
            piutang_ulid: data.ulid
        }
    });
}

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'tanggal', sort_order: 'desc' };
    let allData;
    try {
        const response = await customerPiutangsApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'No. Dokumen', width: 26, accessor: (row) => row.sales?.nomor_dokumen || '-' },
        { header: 'Sumber', width: 14, align: 'center', accessor: (row) => (row.sales ? 'Penjualan' : '-') },
        { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.tanggal) },
        { header: 'Customer', width: 30, accessor: (row) => row.customer?.nama || '-' }
    ];
    if (canViewNominal.value) {
        columns.push(
            { header: 'Nominal Awal', width: 22, align: 'right', accessor: (row) => formatCurrency(row.nominal_awal) },
            { header: 'Terbayar', width: 22, align: 'right', accessor: (row) => formatCurrency(row.nominal_terbayar) },
            { header: 'Sisa Piutang', width: 22, align: 'right', accessor: (row) => formatCurrency(row.sisa_piutang) }
        );
    }
    columns.push({ header: 'Jatuh Tempo', width: 22, accessor: (row) => (row.tanggal_jatuh_tempo ? formatDateTime(row.tanggal_jatuh_tempo) : '-') }, { header: 'Status', width: 16, accessor: (row) => getStatusLabel(row.status) });

    exportListPdf({
        title: 'Daftar Piutang Customer',
        filename: `piutang_customer_${todayString()}`,
        columns,
        data: allData,
        totalLabel: `Total: ${allData.length} piutang`
    });
}

const exportingExcel = ref(false);
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const response = await customerPiutangsApi.export(buildFilterParams());
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `piutang_customer_${todayString()}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
        notify.exportSuccess();
    } catch {
        notify.exportError();
    } finally {
        exportingExcel.value = false;
    }
}
</script>

<template>
    <div class="card">
        <MoneySummaryPanel title="Ringkasan Piutang" :items="summaryItems" :cols="4" />

        <AgingBucketPanel
            v-if="canViewNominal"
            title="Aging Bucket"
            total-label="Total Outstanding"
            :total-value="formatCurrency(aging.total_piutang_outstanding)"
            :total-meta="`${aging.total_count} piutang`"
            :buckets="agingBucketItems"
            :selected-key="selectedAgingBucket"
            @select="selectAgingBucket"
        />

        <Toolbar class="mb-6">
            <template #end>
                <ListFiltersSheet :active-count="activeFilterCount">
                    <Select v-model="selectedCustomer" :options="customers" optionLabel="nama" optionValue="id" placeholder="Customer" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedDueWithinDays" :options="dueWithinOptions" optionLabel="label" optionValue="value" placeholder="Tempo dalam..." showClear @change="onDueWithinChange" />
                    <Select v-model="selectedOverdueWithinDays" :options="overdueOptions" optionLabel="label" optionValue="value" placeholder="Overdue..." showClear @change="onOverdueWithinChange" />
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

        <!-- DataTable -->
        <DataTable
            :value="items"
            :loading="loading"
            :lazy="true"
            :paginator="true"
            :rows="lazyParams.rows"
            :totalRecords="totalRecords"
            :rowsPerPageOptions="[10, 25, 50]"
            :first="lazyParams.first"
            :sortField="lazyParams.sortField"
            :sortOrder="lazyParams.sortOrder"
            @page="onPage"
            @sort="onSort"
            removableSort
            dataKey="ulid"
            stripedRows
            showGridlines
            scrollable
            :rowClass="(data) => (isOverdue(data) ? 'bg-red-50' : '')"
        >
            <template #header>
                <DataTableHeader v-model="searchQuery" title="Daftar Piutang Customer" placeholder="Cari no. sales, customer..." @search="onSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <div class="flex gap-2">
                            <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                            <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                        </div>
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data piutang</p>
                </div>
            </template>

            <Column header="No. Dokumen" style="min-width: 170px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.sales?.nomor_dokumen || '-' }}</span>
                    <Tag :value="data.sales ? 'Sales' : 'Serial'" :severity="data.sales ? 'info' : 'help'" class="ml-2 text-xs" />
                </template>
            </Column>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <Column header="Customer" style="min-width: 200px">
                <template #body="{ data }">
                    <div>
                        <span class="font-medium">{{ data.customer?.nama }}</span>
                        <div class="text-sm text-surface-500">{{ data.customer?.kode_customer }}</div>
                    </div>
                </template>
            </Column>

            <Column v-if="canViewNominal" field="nominal_awal" header="Nominal" sortable style="min-width: 130px" bodyClass="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.nominal_awal) }}
                </template>
            </Column>

            <Column v-if="canViewNominal" field="nominal_terbayar" header="Terbayar" style="min-width: 130px" bodyClass="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.nominal_terbayar) }}
                </template>
            </Column>

            <Column v-if="canViewNominal" field="sisa_piutang" header="Sisa" sortable style="min-width: 130px" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-semibold" :class="{ 'text-red-500': data.sisa_piutang > 0 }">
                        {{ formatCurrency(data.sisa_piutang) }}
                    </span>
                </template>
            </Column>

            <Column field="tanggal_jatuh_tempo" header="Jatuh Tempo" sortable style="min-width: 140px">
                <template #body="{ data }">
                    <div v-if="data.tanggal_jatuh_tempo">
                        <span :class="{ 'text-red-500 font-medium': isOverdue(data) }">
                            {{ formatDateTime(data.tanggal_jatuh_tempo) }}
                        </span>
                        <div v-if="data.status !== 'paid'" class="text-xs" :class="isOverdue(data) ? 'text-red-500' : 'text-surface-500'">
                            {{ getDaysUntilDue(data) > 0 ? `${getDaysUntilDue(data)} hari lagi` : `${Math.abs(getDaysUntilDue(data))} hari lewat` }}
                        </div>
                    </div>
                    <span v-else class="text-surface-400">-</span>
                </template>
            </Column>

            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                </template>
            </Column>

            <Column header="Aksi" style="min-width: 80px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <RowActionButtons>
                        <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" />
                        <Button
                            v-if="canCreatePembayaran && data.status !== 'paid'"
                            icon="pi pi-wallet"
                            severity="success"
                            text
                            rounded
                            @click="payPiutang(data)"
                            v-tooltip.top="'Bayar'"
                        />
                    </RowActionButtons>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog v-model:visible="detailDialog" title="Detail Piutang Customer" :loading="loadingDetail" :created-at="detailData.created_at" width="700px">
            <template #content>
                <div v-if="detailData.ulid">
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <DetailItem label="No. Dokumen" :value="detailData.sales?.nomor_dokumen || '-'" />
                        <DetailItem label="Sumber" :value="detailData.sales ? 'Penjualan' : '-'" />
                        <DetailItem label="Customer" :value="detailData.customer?.nama" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                        <DetailItem label="Tanggal Piutang" :value="formatDateTime(detailData.tanggal)" />
                        <DetailItem label="Jatuh Tempo" :value="formatDateTime(detailData.tanggal_jatuh_tempo)" />
                    </div>

                    <div v-if="canViewNominal" class="border border-surface-200 rounded-lg p-4 space-y-3">
                        <div class="flex justify-between">
                            <span>Nominal Awal</span>
                            <span class="font-medium">{{ formatCurrency(detailData.nominal_awal) }}</span>
                        </div>
                        <div class="flex justify-between text-green-600">
                            <span>Terbayar</span>
                            <span class="font-medium">{{ formatCurrency(detailData.nominal_terbayar) }}</span>
                        </div>
                        <div v-if="Number(detailData.nominal_retur) > 0" class="flex justify-between text-orange-600">
                            <span>Retur</span>
                            <span class="font-medium">{{ formatCurrency(detailData.nominal_retur) }}</span>
                        </div>
                        <Divider />
                        <div class="flex justify-between font-bold text-lg">
                            <span>Sisa Piutang</span>
                            <span :class="{ 'text-red-500': detailData.sisa_piutang > 0 }">
                                {{ formatCurrency(detailData.sisa_piutang) }}
                            </span>
                        </div>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button label="Tutup" severity="secondary" outlined @click="detailDialog = false" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

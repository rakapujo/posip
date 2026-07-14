<script setup>
import { supplierHutangsApi, suppliersApi } from '@/api';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import { onMounted, ref, computed } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';

const notify = useNotification();
const authStore = useAuthStore();
const { formatCurrency, formatDateTime, getPrimeDateFormatShort, toDateString, todayString, now, parseDateTime, isBeforeNow } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();

// Permissions
const canViewNominal = computed(() => authStore.can('hutang.view_nominal'));
const canExport = computed(() => authStore.can('laporan.export'));

// Data
const items = ref([]);
const loading = ref(false);
const totalRecords = ref(0);
const summary = ref({});
const aging = ref({ loading: false, total_hutang_outstanding: 0, total_count: 0, buckets: {} });
const selectedAgingBucket = ref(null); // 'belum_tempo' | 'b1_30' | 'b31_60' | 'b61_90' | 'above_90'

// Filters
const suppliers = ref([]);
const searchQuery = ref('');
const selectedSupplier = ref(null);
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
    await loadSuppliers();
    await Promise.all([loadData(), loadSummary(), loadAging()]);
});

async function loadSuppliers() {
    try {
        const response = await suppliersApi.getList();
        if (response.data.success) {
            suppliers.value = response.data.data.suppliers;
        }
    } catch (error) {
        console.error('Failed to load suppliers:', error);
        notify.apiError(error, 'Gagal load suppliers');
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
        if (selectedSupplier.value) {
            params.supplier_id = selectedSupplier.value;
        }
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }
        if (selectedDueWithinDays.value) {
            // 'all' means all non-overdue outstanding, number means within X days
            params.due_within_days = selectedDueWithinDays.value;
        }
        if (selectedOverdueWithinDays.value) {
            // 'all' means all overdue, number means overdue within X days
            params.overdue_within_days = selectedOverdueWithinDays.value;
        }
        if (startDate.value) {
            params.date_from = toDateString(startDate.value);
        }
        if (endDate.value) {
            params.date_to = toDateString(endDate.value);
        }

        const response = await supplierHutangsApi.getAll(params);
        if (response.data.success) {
            items.value = response.data.data.items;
            totalRecords.value = response.data.data.pagination?.total || 0;
        }
    } catch (error) {
        console.error('Failed to load hutang:', error);
        notify.loadListError('hutang');
    } finally {
        loading.value = false;
    }
}

async function loadSummary() {
    try {
        const params = {};
        if (selectedSupplier.value) {
            params.supplier_id = selectedSupplier.value;
        }

        const response = await supplierHutangsApi.getSummary(params);
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
        const params = {};
        if (selectedSupplier.value) params.supplier_id = selectedSupplier.value;

        const response = await supplierHutangsApi.getAgingSummary(params);
        if (response.data.success) {
            aging.value.total_hutang_outstanding = response.data.data.total_hutang_outstanding;
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
    selectedAgingBucket.value = selectedAgingBucket.value === key ? null : key;
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
}

function clearSearch() {
    searchQuery.value = '';
    lazyParams.value.first = 0;
    loadData();
}

function resetFilters() {
    searchQuery.value = '';
    selectedSupplier.value = null;
    selectedStatus.value = null;
    selectedDueWithinDays.value = null;
    selectedOverdueWithinDays.value = null;
    startDate.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    endDate.value = new Date();
    lazyParams.value.first = 0;
    loadData();
    loadSummary();
}

function onDueWithinChange() {
    // Clear overdue filter when selecting due within (mutually exclusive)
    if (selectedDueWithinDays.value) {
        selectedOverdueWithinDays.value = null;
    }
    onFilterChange();
}

function onOverdueWithinChange() {
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
        const response = await supplierHutangsApi.get(data.ulid);
        if (response.data.success) {
            detailData.value = response.data.data.hutang;
        }
    } catch (error) {
        console.error('Failed to load detail:', error);
        notify.loadDetailError('hutang');
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
    return isBeforeNow(item.tanggal_jatuh_tempo);
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
    if (selectedSupplier.value) params.supplier_id = selectedSupplier.value;
    if (selectedStatus.value) params.status = selectedStatus.value;
    if (selectedDueWithinDays.value) params.due_within_days = selectedDueWithinDays.value;
    if (selectedOverdueWithinDays.value) params.overdue_within_days = selectedOverdueWithinDays.value;
    if (startDate.value) params.date_from = toDateString(startDate.value);
    if (endDate.value) params.date_to = toDateString(endDate.value);
    return params;
}

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'tanggal', sort_order: 'desc' };
    let allData;
    try {
        const response = await supplierHutangsApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'No. Dokumen', width: 26, accessor: (row) => row.purchase_order?.nomor_dokumen || row.serial_intake?.nomor_dokumen || '-' },
        { header: 'Sumber', width: 14, align: 'center', accessor: (row) => (row.purchase_order ? 'PO' : row.serial_intake ? 'Serial' : '-') },
        { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.tanggal) },
        { header: 'Supplier', width: 30, accessor: (row) => row.supplier?.nama_supplier || '-' }
    ];
    if (canViewNominal.value) {
        columns.push(
            { header: 'Nominal Awal', width: 22, align: 'right', accessor: (row) => formatCurrency(row.nominal_awal) },
            { header: 'Terbayar', width: 22, align: 'right', accessor: (row) => formatCurrency(row.nominal_terbayar) },
            { header: 'Sisa Hutang', width: 22, align: 'right', accessor: (row) => formatCurrency(row.sisa_hutang) }
        );
    }
    columns.push({ header: 'Jatuh Tempo', width: 22, accessor: (row) => (row.tanggal_jatuh_tempo ? formatDateTime(row.tanggal_jatuh_tempo) : '-') }, { header: 'Status', width: 16, accessor: (row) => getStatusLabel(row.status) });

    exportListPdf({
        title: 'Daftar Hutang Supplier',
        filename: `hutang_supplier_${todayString()}`,
        columns,
        data: allData,
        totalLabel: `Total: ${allData.length} hutang`
    });
}

const exportingExcel = ref(false);
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const response = await supplierHutangsApi.export(buildFilterParams());
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `hutang_supplier_${todayString()}.xlsx`);
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
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="border border-surface-200 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Hutang Outstanding</div>
                <div v-if="canViewNominal" class="text-2xl font-bold text-blue-600">
                    {{ formatCurrency(summary.total_hutang || 0) }}
                </div>
                <div v-else class="text-2xl font-bold text-surface-400">-</div>
            </div>
            <div class="border border-surface-200 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Belum Bayar</div>
                <div class="text-2xl font-bold text-red-500">{{ summary.total_unpaid || 0 }}</div>
            </div>
            <div class="border border-surface-200 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Sebagian Terbayar</div>
                <div class="text-2xl font-bold text-yellow-500">{{ summary.total_partial || 0 }}</div>
            </div>
            <div class="border border-surface-200 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Jatuh Tempo</div>
                <div class="text-2xl font-bold text-orange-500">{{ summary.total_overdue || 0 }}</div>
            </div>
        </div>

        <!-- E3: Aging Bucket Summary -->
        <div v-if="canViewNominal" class="mb-6 bg-surface-0 dark:bg-surface-900 rounded-lg border border-surface-200 dark:border-surface-700 p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-surface-800 dark:text-surface-100">Aging Bucket</h3>
                <span class="text-sm text-surface-500">
                    Total Outstanding:
                    <span class="font-bold text-surface-900 dark:text-surface-0 ml-1">{{ formatCurrency(aging.total_hutang_outstanding) }}</span>
                    <span class="text-surface-400 ml-2">({{ aging.total_count }} hutang)</span>
                </span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div
                    v-for="b in agingBucketConfig"
                    :key="b.key"
                    class="rounded-lg p-3 cursor-pointer transition hover:ring-2"
                    :class="[b.bg, selectedAgingBucket === b.key ? b.ring : '']"
                    @click="selectAgingBucket(b.key)"
                    role="button"
                    tabindex="0"
                    @keydown.enter="selectAgingBucket(b.key)"
                    :aria-label="`Filter aging ${b.label}`"
                >
                    <div :class="[b.text, 'text-xs font-medium mb-1 flex items-center gap-1']">
                        {{ b.label }}
                        <i v-if="selectedAgingBucket === b.key" class="pi pi-filter-fill text-xs"></i>
                    </div>
                    <div :class="[b.text, 'text-lg font-bold']">
                        {{ formatCurrency(aging.buckets[b.key]?.nominal || 0) }}
                    </div>
                    <div class="text-xs text-surface-500 mt-1">{{ aging.buckets[b.key]?.count || 0 }} hutang · {{ aging.buckets[b.key]?.percent || 0 }}%</div>
                </div>
            </div>
        </div>

        <Toolbar class="mb-6">
            <template #end>
                <div class="flex flex-wrap gap-2 w-full">
                    <Select v-model="selectedSupplier" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Supplier" class="flex-1 min-w-36" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="flex-1 min-w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedDueWithinDays" :options="dueWithinOptions" optionLabel="label" optionValue="value" placeholder="Tempo dalam..." class="flex-1 min-w-40" showClear @change="onDueWithinChange" />
                    <Select v-model="selectedOverdueWithinDays" :options="overdueOptions" optionLabel="label" optionValue="value" placeholder="Overdue..." class="flex-1 min-w-36" showClear @change="onOverdueWithinChange" />
                    <div class="flex-1 min-w-36">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilterChange" />
                    </div>
                    <div class="flex-1 min-w-36">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilterChange" />
                    </div>
                    <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                </div>
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
                <DataTableHeader v-model="searchQuery" title="Daftar Hutang Supplier" placeholder="Cari no. PO, supplier..." @search="onSearch" @clear="clearSearch">
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
                    <p class="text-surface-500 m-0">Tidak ada data hutang</p>
                </div>
            </template>

            <Column header="No. Dokumen" style="min-width: 170px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.purchase_order?.nomor_dokumen || data.serial_intake?.nomor_dokumen || '-' }}</span>
                    <Tag :value="data.purchase_order ? 'PO' : 'Serial'" :severity="data.purchase_order ? 'info' : 'help'" class="ml-2 text-xs" />
                </template>
            </Column>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <Column header="Supplier" style="min-width: 200px">
                <template #body="{ data }">
                    <div>
                        <span class="font-medium">{{ data.supplier?.nama_supplier }}</span>
                        <div class="text-sm text-surface-500">{{ data.supplier?.kode_supplier }}</div>
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

            <Column v-if="canViewNominal" field="sisa_hutang" header="Sisa" sortable style="min-width: 130px" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-semibold" :class="{ 'text-red-500': data.sisa_hutang > 0 }">
                        {{ formatCurrency(data.sisa_hutang) }}
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
                    <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" />
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog v-model:visible="detailDialog" title="Detail Hutang Supplier" :loading="loadingDetail" :created-at="detailData.created_at" width="700px">
            <template #content>
                <div v-if="detailData.ulid">
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <DetailItem label="No. Dokumen" :value="detailData.purchase_order?.nomor_dokumen || detailData.serial_intake?.nomor_dokumen" />
                        <DetailItem label="Sumber" :value="detailData.purchase_order ? 'Purchase Order' : 'Pembelian Serial'" />
                        <DetailItem label="Supplier" :value="detailData.supplier?.nama_supplier" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                        <DetailItem label="Tanggal Hutang" :value="formatDateTime(detailData.tanggal)" />
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
                        <Divider />
                        <div class="flex justify-between font-bold text-lg">
                            <span>Sisa Hutang</span>
                            <span :class="{ 'text-red-500': detailData.sisa_hutang > 0 }">
                                {{ formatCurrency(detailData.sisa_hutang) }}
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

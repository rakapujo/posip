<script setup>
import { supplierDepositsApi, suppliersApi } from '@/api';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import { useConfirm } from 'primevue/useconfirm';
import { onMounted, ref, computed } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';

const notify = useNotification();
const confirm = useConfirm();
const authStore = useAuthStore();
const { formatCurrency, formatDateTime, getPrimeDateFormatShort, toDateString, todayString, shouldUppercase, currencySettings, getLocale, getCurrencyMinFractionDigits, getCurrencyMaxFractionDigits } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();

// Permissions
const canCreate = computed(() => authStore.can('deposit-supplier.create'));
const canUpdate = computed(() => authStore.can('deposit-supplier.update'));
const canDelete = computed(() => authStore.can('deposit-supplier.delete'));
const canExport = computed(() => authStore.can('laporan.export'));

// Data
const items = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

// Summary
const summary = ref({
    total_deposit: 0,
    total_used: 0,
    total_balance: 0,
    deposit_count: 0,
    available_count: 0
});

// Filters
const suppliers = ref([]);
const searchQuery = ref('');
const selectedSupplier = ref(null);
const selectedStatus = ref(null);
const hasBalanceOnly = ref(false);
const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const endDate = ref(new Date());

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

// Form dialog
const formDialog = ref(false);
const formMode = ref('create'); // 'create' or 'edit'
const formLoading = ref(false);
const formData = ref({
    supplier_id: null,
    tanggal: new Date(),
    nominal_awal: 0,
    no_referensi: '',
    keterangan: ''
});
const editUlid = ref(null);

// Status options
const statusOptions = [
    { label: 'Available', value: 'available' },
    { label: 'Used Partial', value: 'used_partial' },
    { label: 'Used All', value: 'used_all' }
];

onMounted(async () => {
    await loadSuppliers();
    await Promise.all([loadData(), loadSummary()]);
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
        if (hasBalanceOnly.value) {
            params.has_balance_only = true;
        }
        if (startDate.value) {
            params.date_from = toDateString(startDate.value);
        }
        if (endDate.value) {
            params.date_to = toDateString(endDate.value);
        }

        const response = await supplierDepositsApi.getAll(params);
        if (response.data.success) {
            items.value = response.data.data.items;
            totalRecords.value = response.data.data.pagination?.total || 0;
        }
    } catch (error) {
        console.error('Failed to load deposits:', error);
        notify.loadListError('deposit supplier');
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

        const response = await supplierDepositsApi.getSummary(params);
        if (response.data.success) {
            summary.value = response.data.data.summary;
        }
    } catch (error) {
        console.error('Failed to load summary:', error);
        notify.apiError(error, 'Gagal load summary');
    }
}

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
    hasBalanceOnly.value = false;
    startDate.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    endDate.value = new Date();
    lazyParams.value.first = 0;
    loadData();
    loadSummary();
}

// E4: Usage history state
const activeDepositTab = ref('ringkasan');
const usageHistory = ref({ loading: false, items: [], usage_count: 0, total_used_from_history: 0 });

async function viewDetail(data) {
    detailDialog.value = true;
    loadingDetail.value = true;
    activeDepositTab.value = 'ringkasan';
    usageHistory.value = { loading: false, items: [], usage_count: 0, total_used_from_history: 0 };

    try {
        const response = await supplierDepositsApi.get(data.ulid);
        if (response.data.success) {
            detailData.value = response.data.data.deposit;
        }
    } catch (error) {
        console.error('Failed to load detail:', error);
        notify.loadDetailError('deposit');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

async function loadUsageHistory() {
    if (!detailData.value?.ulid) return;
    if (usageHistory.value.items.length > 0) return; // already loaded

    usageHistory.value.loading = true;
    try {
        const response = await supplierDepositsApi.getUsage(detailData.value.ulid);
        if (response.data.success) {
            usageHistory.value.items = response.data.data.items;
            usageHistory.value.usage_count = response.data.data.usage_count;
            usageHistory.value.total_used_from_history = response.data.data.total_used_from_history;
        }
    } catch (error) {
        console.error('Failed to load usage history:', error);
        notify.apiError(error, 'Gagal load riwayat pemakaian');
    } finally {
        usageHistory.value.loading = false;
    }
}

function onDepositTabChange(tabName) {
    activeDepositTab.value = tabName;
    if (tabName === 'pemakaian') {
        loadUsageHistory();
    }
}

function getStatusSeverity(status) {
    switch (status) {
        case 'available':
            return 'success';
        case 'used_partial':
            return 'warn';
        case 'used_all':
            return 'secondary';
        default:
            return 'secondary';
    }
}

function getStatusLabel(status) {
    switch (status) {
        case 'available':
            return 'Available';
        case 'used_partial':
            return 'Sebagian';
        case 'used_all':
            return 'Habis';
        default:
            return status;
    }
}

// Helper to check if deposit is manual (not from retur)
function isManual(data) {
    return !data.purchase_return;
}

// Form functions
function openCreateForm() {
    formMode.value = 'create';
    editUlid.value = null;
    formData.value = {
        supplier_id: null,
        tanggal: new Date(),
        nominal_awal: 0,
        no_referensi: '',
        keterangan: ''
    };
    formDialog.value = true;
}

async function openEditForm(data) {
    formMode.value = 'edit';
    editUlid.value = data.ulid;

    // Load full detail to get supplier_id
    try {
        const response = await supplierDepositsApi.get(data.ulid);
        if (response.data.success) {
            const deposit = response.data.data.deposit;
            formData.value = {
                supplier_id: deposit.supplier?.id || null,
                tanggal: new Date(deposit.tanggal),
                nominal_awal: parseFloat(deposit.nominal_awal) || 0,
                no_referensi: deposit.no_referensi || '',
                keterangan: deposit.keterangan || ''
            };
            formDialog.value = true;
        }
    } catch (error) {
        console.error('Failed to load deposit for edit:', error);
        notify.loadDetailError('deposit');
    }
}

function closeForm() {
    formDialog.value = false;
}

async function submitForm() {
    // Validation
    if (!formData.value.supplier_id) {
        notify.selectFirst('supplier');
        return;
    }
    if (!formData.value.nominal_awal || formData.value.nominal_awal <= 0) {
        notify.warn('Validasi', 'Nominal harus lebih dari 0');
        return;
    }

    formLoading.value = true;

    try {
        const payload = {
            supplier_id: formData.value.supplier_id,
            tanggal: toDateString(formData.value.tanggal),
            nominal_awal: formData.value.nominal_awal,
            no_referensi: formData.value.no_referensi || null,
            keterangan: formData.value.keterangan || null
        };

        let response;
        if (formMode.value === 'create') {
            response = await supplierDepositsApi.create(payload);
        } else {
            response = await supplierDepositsApi.update(editUlid.value, payload);
        }

        if (response.data.success) {
            notify.saveSuccess('Deposit', formMode.value === 'edit');
            closeForm();
            loadData();
            loadSummary();
        }
    } catch (error) {
        console.error('Failed to save deposit:', error);
        notify.saveError(error);
    } finally {
        formLoading.value = false;
    }
}

function confirmDelete(data) {
    confirm.require({
        message: 'Apakah Anda yakin ingin menghapus deposit ini?',
        header: 'Konfirmasi Hapus',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Batal',
        acceptLabel: 'Hapus',
        rejectClass: 'p-button-secondary p-button-outlined',
        acceptClass: 'p-button-danger',
        accept: () => deleteItem(data)
    });
}

async function deleteItem(data) {
    try {
        const response = await supplierDepositsApi.delete(data.ulid);
        if (response.data.success) {
            notify.deleted('deposit');
            loadData();
            loadSummary();
        }
    } catch (error) {
        console.error('Failed to delete deposit:', error);
        notify.deleteError(error);
    }
}

// Computed for form dialog title
const formDialogTitle = computed(() => {
    return formMode.value === 'create' ? 'Tambah Deposit Manual' : 'Edit Deposit';
});

function buildFilterParams() {
    const params = {};
    if (searchQuery.value?.trim()) params.search = searchQuery.value.trim();
    if (selectedSupplier.value) params.supplier_id = selectedSupplier.value;
    if (selectedStatus.value) params.status = selectedStatus.value;
    if (hasBalanceOnly.value) params.has_balance_only = true;
    if (startDate.value) params.date_from = toDateString(startDate.value);
    if (endDate.value) params.date_to = toDateString(endDate.value);
    return params;
}

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'tanggal', sort_order: 'desc' };
    let allData;
    try {
        const response = await supplierDepositsApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Supplier', width: 28, accessor: (row) => row.supplier?.nama_supplier || '-' },
        { header: 'Sumber', width: 24, accessor: (row) => (row.purchase_return ? `Retur - ${row.purchase_return.nomor_dokumen}` : 'Manual') },
        { header: 'No. Referensi', width: 20, accessor: (row) => row.no_referensi || '-' },
        { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.tanggal) },
        { header: 'Nominal Awal', width: 22, align: 'right', accessor: (row) => formatCurrency(row.nominal_awal) },
        { header: 'Terpakai', width: 22, align: 'right', accessor: (row) => formatCurrency(row.nominal_terpakai) },
        { header: 'Sisa Deposit', width: 22, align: 'right', accessor: (row) => formatCurrency(row.sisa_deposit) },
        { header: 'Status', width: 16, accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Deposit Supplier',
        filename: `deposit_supplier_${todayString()}`,
        columns,
        data: allData,
        totalLabel: `Total: ${allData.length} deposit`
    });
}

const exportingExcel = ref(false);
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const response = await supplierDepositsApi.export(buildFilterParams());
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `deposit_supplier_${todayString()}.xlsx`);
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
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-sm text-surface-500 dark:text-surface-400 mb-1">Total Deposit</div>
                <div class="text-xl font-bold">{{ formatCurrency(summary.total_deposit) }}</div>
            </div>
            <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4">
                <div class="text-sm text-orange-600 dark:text-orange-400 mb-1">Sudah Terpakai</div>
                <div class="text-xl font-bold text-orange-600 dark:text-orange-400">{{ formatCurrency(summary.total_used) }}</div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                <div class="text-sm text-green-600 dark:text-green-400 mb-1">Sisa Deposit</div>
                <div class="text-xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(summary.total_balance) }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-sm text-surface-500 dark:text-surface-400 mb-1">Jumlah Deposit</div>
                <div class="text-xl font-bold">{{ summary.deposit_count }}</div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-sm text-blue-600 dark:text-blue-400 mb-1">Available</div>
                <div class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ summary.available_count }}</div>
            </div>
        </div>

        <Toolbar class="mb-6">
            <template #start>
                <div class="flex items-center gap-4">
                    <Button v-if="canCreate" label="Tambah Deposit" icon="pi pi-plus" severity="primary" @click="openCreateForm" />
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="hasBalanceOnly" :binary="true" inputId="hasBalance" @change="onFilterChange" />
                        <label for="hasBalance" class="text-sm cursor-pointer">Hanya tampilkan yang ada saldo</label>
                    </div>
                </div>
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="selectedSupplier" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Supplier" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-36" filter showClear @change="onFilterChange" />
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilterChange" />
                    </div>
                    <div class="w-40">
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
        >
            <template #header>
                <DataTableHeader v-model="searchQuery" title="Daftar Deposit Supplier" placeholder="Cari supplier, no. dokumen..." @search="onSearch" @clear="clearSearch">
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
                    <p class="text-surface-500 m-0">Tidak ada data deposit supplier</p>
                </div>
            </template>

            <Column header="Supplier" style="min-width: 200px">
                <template #body="{ data }">
                    <div>
                        <span class="font-medium">{{ data.supplier?.nama_supplier }}</span>
                        <div class="text-sm text-surface-500">{{ data.supplier?.kode_supplier }}</div>
                    </div>
                </template>
            </Column>

            <Column header="Sumber" style="min-width: 150px">
                <template #body="{ data }">
                    <div v-if="data.purchase_return">
                        <Tag value="Retur" severity="info" class="mb-1" />
                        <div class="text-sm text-surface-500">{{ data.purchase_return?.nomor_dokumen }}</div>
                    </div>
                    <div v-else>
                        <Tag value="Manual" severity="secondary" />
                    </div>
                </template>
            </Column>

            <Column header="No. Referensi" style="min-width: 130px">
                <template #body="{ data }">
                    <span v-if="data.no_referensi" class="font-medium">{{ data.no_referensi }}</span>
                    <span v-else class="text-surface-400">-</span>
                </template>
            </Column>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 120px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <Column field="nominal_awal" header="Nominal Awal" sortable style="min-width: 140px" bodyClass="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.nominal_awal) }}
                </template>
            </Column>

            <Column header="Terpakai" style="min-width: 140px" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="text-orange-600 dark:text-orange-400">{{ formatCurrency(data.nominal_terpakai) }}</span>
                </template>
            </Column>

            <Column field="sisa_deposit" header="Sisa" sortable style="min-width: 140px" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(data.sisa_deposit) }}</span>
                </template>
            </Column>

            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                </template>
            </Column>

            <Column header="Aksi" style="min-width: 150px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <div class="flex gap-1">
                        <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" />
                        <Button v-if="canUpdate && isManual(data)" icon="pi pi-pencil" severity="warning" text rounded @click="openEditForm(data)" v-tooltip.top="'Edit'" />
                        <Button v-if="canDelete && isManual(data) && data.nominal_terpakai == 0" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Deposit Supplier"
            :loading="loadingDetail"
            :created-at="detailData.is_manual ? detailData.created_at : null"
            :created-by="detailData.is_manual ? detailData.created_by?.name : null"
            :updated-at="detailData.is_manual ? detailData.updated_at : null"
            :updated-by="detailData.is_manual ? detailData.updated_by?.name : null"
            width="600px"
        >
            <template #content>
                <div v-if="detailData.ulid">
                    <!-- E4: Tab navigation -->
                    <div class="mb-4 border-b border-surface-200 dark:border-surface-700 flex gap-1">
                        <button
                            class="px-4 py-2 text-sm font-medium border-b-2 transition"
                            :class="activeDepositTab === 'ringkasan' ? 'border-primary text-primary' : 'border-transparent text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100'"
                            @click="onDepositTabChange('ringkasan')"
                            type="button"
                        >
                            Ringkasan
                        </button>
                        <button
                            class="px-4 py-2 text-sm font-medium border-b-2 transition flex items-center gap-2"
                            :class="activeDepositTab === 'pemakaian' ? 'border-primary text-primary' : 'border-transparent text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100'"
                            @click="onDepositTabChange('pemakaian')"
                            type="button"
                        >
                            Pemakaian
                            <Badge v-if="usageHistory.usage_count > 0" :value="usageHistory.usage_count" severity="info" />
                        </button>
                    </div>

                    <!-- Tab: Pemakaian -->
                    <div v-show="activeDepositTab === 'pemakaian'">
                        <div v-if="usageHistory.loading" class="py-8 text-center text-surface-500">
                            <i class="pi pi-spin pi-spinner text-2xl"></i>
                            <div class="mt-2 text-sm">Memuat riwayat...</div>
                        </div>
                        <div v-else-if="usageHistory.items.length === 0" class="py-8 text-center text-surface-500">Deposit ini belum pernah dipakai.</div>
                        <div v-else>
                            <div class="mb-3 p-3 bg-surface-50 dark:bg-surface-800 rounded-lg flex items-center justify-between">
                                <div>
                                    <div class="text-xs text-surface-500">Total Terpakai (dari history)</div>
                                    <div class="font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(usageHistory.total_used_from_history) }}</div>
                                </div>
                                <div class="text-sm text-surface-500">{{ usageHistory.usage_count }} transaksi</div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-surface-50 dark:bg-surface-800">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-medium">Tanggal</th>
                                            <th class="px-3 py-2 text-left font-medium">No. Pembayaran</th>
                                            <th class="px-3 py-2 text-left font-medium">Status</th>
                                            <th class="px-3 py-2 text-right font-medium">Nominal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="u in usageHistory.items" :key="u.id" class="border-b border-surface-100 dark:border-surface-800">
                                            <td class="px-3 py-2">{{ formatDateTime(u.tanggal) }}</td>
                                            <td class="px-3 py-2 font-medium">{{ u.nomor_dokumen }}</td>
                                            <td class="px-3 py-2">
                                                <Tag :value="u.status" :severity="u.status === 'completed' ? 'success' : 'secondary'" />
                                            </td>
                                            <td class="px-3 py-2 text-right font-medium text-orange-600">{{ formatCurrency(u.nominal_digunakan) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Ringkasan (existing content) -->
                    <div v-show="activeDepositTab === 'ringkasan'">
                        <div class="grid grid-cols-2 gap-4 mb-6">
                            <DetailItem label="Supplier" :value="detailData.supplier?.nama_supplier" />
                            <DetailItem label="Kode Supplier" :value="detailData.supplier?.kode_supplier" />
                            <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal)" />
                            <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                            <DetailItem label="Sumber">
                                <template #value>
                                    <Tag v-if="detailData.purchase_return" value="Retur Pembelian" severity="info" />
                                    <Tag v-else value="Manual" severity="secondary" />
                                </template>
                            </DetailItem>
                            <DetailItem v-if="detailData.no_referensi" label="No. Referensi" :value="detailData.no_referensi" />
                        </div>

                        <!-- Keterangan (for manual deposits) -->
                        <div v-if="detailData.keterangan" class="mb-6 p-4 bg-surface-50 dark:bg-surface-800 rounded-lg">
                            <h5 class="font-medium mb-2 text-surface-600 dark:text-surface-400">Keterangan</h5>
                            <p class="m-0 text-sm">{{ detailData.keterangan }}</p>
                        </div>

                        <!-- Source Return -->
                        <div v-if="detailData.purchase_return" class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                            <h5 class="font-medium mb-2 text-blue-700 dark:text-blue-300">Dari Retur Pembelian</h5>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <span class="text-surface-500 dark:text-surface-400">No. Dokumen:</span>
                                    <span class="font-medium ml-2">{{ detailData.purchase_return.nomor_dokumen }}</span>
                                </div>
                                <div>
                                    <span class="text-surface-500 dark:text-surface-400">Tanggal:</span>
                                    <span class="ml-2">{{ formatDateTime(detailData.purchase_return.tanggal) }}</span>
                                </div>
                                <div>
                                    <span class="text-surface-500 dark:text-surface-400">Nilai Kalkulasi:</span>
                                    <span class="ml-2">{{ formatCurrency(detailData.purchase_return.nilai_kalkulasi) }}</span>
                                </div>
                                <div>
                                    <span class="text-surface-500 dark:text-surface-400">Nilai Diakui:</span>
                                    <span class="font-medium ml-2 text-green-600 dark:text-green-400">{{ formatCurrency(detailData.purchase_return.nilai_diakui) }}</span>
                                </div>
                                <div v-if="detailData.purchase_return.selisih" class="col-span-2">
                                    <span class="text-surface-500 dark:text-surface-400">Selisih:</span>
                                    <span class="ml-2" :class="detailData.purchase_return.selisih > 0 ? 'text-green-500 dark:text-green-400' : detailData.purchase_return.selisih < 0 ? 'text-red-500 dark:text-red-400' : ''">
                                        {{ detailData.purchase_return.selisih > 0 ? '+' : '' }}{{ formatCurrency(detailData.purchase_return.selisih) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Deposit Summary -->
                        <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                            <h5 class="font-medium mb-3">Ringkasan Deposit</h5>
                            <div class="space-y-3">
                                <div class="flex justify-between">
                                    <span class="text-surface-600 dark:text-surface-400">Nominal Awal</span>
                                    <span class="font-medium">{{ formatCurrency(detailData.nominal_awal) }}</span>
                                </div>
                                <div class="flex justify-between text-orange-500 dark:text-orange-400">
                                    <span>Sudah Terpakai</span>
                                    <span>{{ formatCurrency(detailData.nominal_terpakai) }}</span>
                                </div>
                                <Divider />
                                <div class="flex justify-between text-lg font-bold">
                                    <span>Sisa Deposit</span>
                                    <span class="text-green-600 dark:text-green-400">{{ formatCurrency(detailData.sisa_deposit) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- /ringkasan tab -->
                </div>
            </template>

            <template #footer-extra>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="canUpdate && detailData.can_edit"
                        label="Edit"
                        icon="pi pi-pencil"
                        severity="warning"
                        @click="
                            openEditForm(detailData);
                            detailDialog = false;
                        "
                    />
                    <Button
                        v-if="canDelete && detailData.can_delete"
                        label="Hapus"
                        icon="pi pi-trash"
                        severity="danger"
                        @click="
                            confirmDelete(detailData);
                            detailDialog = false;
                        "
                    />
                </div>
            </template>
        </DetailDialog>

        <!-- Form Dialog -->
        <Dialog v-model:visible="formDialog" :header="formDialogTitle" modal :style="{ width: '500px' }" :closable="!formLoading">
            <div class="space-y-4">
                <!-- Supplier -->
                <div>
                    <label class="block text-sm font-medium text-surface-600 dark:text-surface-400 mb-1"> Supplier <span class="text-red-500">*</span> </label>
                    <Select v-model="formData.supplier_id" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Pilih Supplier" class="w-full" filter :disabled="formMode === 'edit'" />
                </div>

                <!-- Tanggal -->
                <div>
                    <label class="block text-sm font-medium text-surface-600 dark:text-surface-400 mb-1"> Tanggal <span class="text-red-500">*</span> </label>
                    <DatePicker v-model="formData.tanggal" :manualInput="false" showIcon :dateFormat="getPrimeDateFormatShort" class="w-full" showButtonBar />
                </div>

                <!-- No. Referensi -->
                <div>
                    <label class="block text-sm font-medium text-surface-600 dark:text-surface-400 mb-1"> No. Referensi </label>
                    <InputText v-model="formData.no_referensi" class="w-full" placeholder="No. voucher, loyalty, dll" maxlength="50" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                </div>

                <!-- Nominal -->
                <div>
                    <label class="block text-sm font-medium text-surface-600 dark:text-surface-400 mb-1"> Nominal <span class="text-red-500">*</span> </label>
                    <InputNumber
                        v-select-on-focus
                        v-model="formData.nominal_awal"
                        :min="0"
                        :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                        :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                        :locale="getLocale"
                        :minFractionDigits="getCurrencyMinFractionDigits"
                        :maxFractionDigits="getCurrencyMaxFractionDigits"
                        class="w-full"
                    />
                </div>

                <!-- Keterangan -->
                <div>
                    <label class="block text-sm font-medium text-surface-600 dark:text-surface-400 mb-1"> Keterangan </label>
                    <Textarea v-model="formData.keterangan" rows="3" class="w-full" placeholder="Deskripsi deposit (voucher, loyalty program, dll)" maxlength="500" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                </div>
            </div>

            <template #footer>
                <Button label="Batal" severity="secondary" outlined @click="closeForm" :disabled="formLoading" />
                <Button :label="formMode === 'create' ? 'Simpan' : 'Update'" icon="pi pi-check" severity="primary" @click="submitForm" :loading="formLoading" />
            </template>
        </Dialog>
    </div>
</template>

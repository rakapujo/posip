<script setup>
import { salesReturnsApi, warehousesApi, customersApi } from '@/api';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import RowActionButtons from '@/components/common/RowActionButtons.vue';
import { useConfirm } from 'primevue/useconfirm';
import { useRouter } from 'vue-router';
import { onMounted, ref, computed } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useAuthStore } from '@/stores/auth';
import { useExportPdf } from '@/composables/useExportPdf';

const notify = useNotification();
const { exporting, exportDocumentPdf } = useExportPdf();
const confirm = useConfirm();
const router = useRouter();
const authStore = useAuthStore();
const { formatCurrency, formatQty, formatDateTime, getPrimeDateFormatShort, getLocale, getCurrencyMinFractionDigits, getCurrencyMaxFractionDigits, currencySettings, toDateString } = useFormatters();

// Permissions
const canCreate = computed(() => authStore.can('retur-jual.create'));
const canUpdate = computed(() => authStore.can('retur-jual.update'));
const canDelete = computed(() => authStore.can('retur-jual.delete'));
const canLock = computed(() => authStore.can('retur-jual.lock'));
const canApprove = computed(() => authStore.can('retur-jual.approve'));

// Data
const items = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

// Filters
const customers = ref([]);
const warehouses = ref([]);
const searchQuery = ref('');
const selectedCustomer = ref(null);
const selectedWarehouse = ref(null);
const selectedStatus = ref(null);
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
const processingAction = ref(false);

// Approve dialog
const approveDialog = ref(false);
const approveForm = ref({
    nilai_diakui: 0,
    catatan_approval: ''
});
const approveTarget = ref(null);

// Status options
const statusOptions = [
    { label: 'Draft', value: 'draft' },
    { label: 'Lock', value: 'lock' },
    { label: 'Approved', value: 'approved' }
];

// Detail table columns
const detailColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'product', header: 'Produk' },
    { field: 'unit_used', header: 'Satuan', width: '80px' },
    { field: 'qty', header: 'Qty', align: 'right', width: '80px' },
    { field: 'harga', header: 'Harga', align: 'right', width: '120px' },
    { field: 'diskon', header: 'Diskon', align: 'right', width: '100px' },
    { field: 'subtotal', header: 'Subtotal', align: 'right', width: '120px' }
];

onMounted(async () => {
    await Promise.all([loadCustomers(), loadWarehouses()]);
    await loadData();
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
    if (selectedWarehouse.value) n++;
    if (selectedStatus.value) n++;
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

async function loadWarehouses() {
    try {
        const response = await warehousesApi.getList();
        if (response.data.success) {
            warehouses.value = response.data.data.warehouses;
        }
    } catch (error) {
        console.error('Failed to load warehouses:', error);
        notify.apiError(error, 'Gagal load warehouses');
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
        if (selectedWarehouse.value) {
            params.warehouse_id = selectedWarehouse.value;
        }
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }
        if (startDate.value) {
            params.date_from = toDateString(startDate.value);
        }
        if (endDate.value) {
            params.date_to = toDateString(endDate.value);
        }

        const response = await salesReturnsApi.getAll(params);
        if (response.data.success) {
            items.value = response.data.data.items;
            totalRecords.value = response.data.data.pagination?.total || 0;
        }
    } catch (error) {
        console.error('Failed to load sales returns:', error);
        notify.loadListError('retur penjualan');
    } finally {
        loading.value = false;
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
}

function clearSearch() {
    searchQuery.value = '';
    lazyParams.value.first = 0;
    loadData();
}

function resetFilters() {
    searchQuery.value = '';
    selectedCustomer.value = null;
    selectedWarehouse.value = null;
    selectedStatus.value = null;
    startDate.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    endDate.value = new Date();
    lazyParams.value.first = 0;
    loadData();
}

function createNew() {
    router.push({ name: 'penjualan-retur-create' });
}

function editItem(data) {
    router.push({ name: 'penjualan-retur-edit', params: { ulid: data.ulid } });
}

async function viewDetail(data) {
    detailDialog.value = true;
    loadingDetail.value = true;

    try {
        const response = await salesReturnsApi.get(data.ulid);
        if (response.data.success) {
            detailData.value = response.data.data.sales_return;
        }
    } catch (error) {
        console.error('Failed to load detail:', error);
        notify.loadDetailError('retur penjualan');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

function confirmDelete(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin menghapus retur "${data.nomor_dokumen}"?`,
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
        const response = await salesReturnsApi.delete(data.ulid);
        if (response.data.success) {
            notify.deleted('retur penjualan');
            loadData();
        }
    } catch (error) {
        console.error('Failed to delete:', error);
        notify.deleteError(error);
    }
}

function confirmLock(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin mengunci retur "${data.nomor_dokumen}"? Stok akan dikembalikan/bertambah dan dokumen tidak dapat diedit lagi.`,
        header: 'Konfirmasi Lock',
        icon: 'pi pi-lock',
        rejectLabel: 'Batal',
        acceptLabel: 'Lock',
        rejectClass: 'p-button-secondary p-button-outlined',
        acceptClass: 'p-button-warning',
        accept: () => lockItem(data)
    });
}

async function lockItem(data) {
    processingAction.value = true;
    try {
        const response = await salesReturnsApi.lock(data.ulid);
        if (response.data.success) {
            notify.success('Retur penjualan berhasil dikunci. Stok telah dikembalikan.');
            loadData();
            // Update detail dialog if open
            if (detailDialog.value && detailData.value.ulid === data.ulid) {
                detailData.value = response.data.data.sales_return;
            }
        }
    } catch (error) {
        console.error('Failed to lock:', error);
        notify.approveError(error, 'Gagal Lock');
    } finally {
        processingAction.value = false;
    }
}

function openApproveDialog(data) {
    approveTarget.value = data;
    approveForm.value = {
        nilai_diakui: parseFloat(data.grand_total) || 0,
        catatan_approval: ''
    };
    approveDialog.value = true;
}

async function submitApprove() {
    if (!approveTarget.value) return;

    processingAction.value = true;
    try {
        const response = await salesReturnsApi.approve(approveTarget.value.ulid, {
            nilai_diakui: approveForm.value.nilai_diakui,
            catatan_approval: approveForm.value.catatan_approval || null
        });

        if (response.data.success) {
            notify.success('Retur penjualan berhasil disetujui. Piutang/deposit telah diproses.');
            approveDialog.value = false;
            loadData();
            // Update detail dialog if open
            if (detailDialog.value && detailData.value.ulid === approveTarget.value.ulid) {
                detailData.value = response.data.data.sales_return;
            }
        }
    } catch (error) {
        console.error('Failed to approve:', error);
        notify.approveError(error);
    } finally {
        processingAction.value = false;
    }
}

function getStatusSeverity(status) {
    switch (status) {
        case 'draft':
            return 'warn';
        case 'lock':
            return 'info';
        case 'approved':
            return 'success';
        default:
            return 'secondary';
    }
}

function getStatusLabel(status) {
    switch (status) {
        case 'draft':
            return 'Draft';
        case 'lock':
            return 'Lock';
        case 'approved':
            return 'Approved';
        default:
            return status;
    }
}

// Export document PDF
async function exportDocPdf(item) {
    let data = item;
    if (!data.details) {
        try {
            const response = await salesReturnsApi.get(data.ulid);
            data = response.data.data.sales_return || response.data.data;
        } catch {
            return;
        }
    }

    const info = [
        { label: 'No. Dokumen', value: data.nomor_dokumen },
        { label: 'Tanggal', value: formatDateTime(data.tanggal) },
        { label: 'Customer', value: data.customer?.nama || '-' },
        { label: 'Warehouse', value: data.warehouse?.nama_warehouse || '-' },
        { label: 'Status', value: getStatusLabel(data.status) }
    ];
    if (data.sales) {
        info.push({ label: 'Ref. Sales', value: data.sales?.nomor_dokumen || '-' });
    }

    const columns = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'Kode Produk', width: 22, accessor: (row) => row.product?.kode_produk || '' },
        {
            header: 'Nama Produk',
            accessor: (row) => {
                let s = row.product?.nama_produk || '';
                if (row.serial_units?.length) {
                    s += '\n' + row.serial_units.map((u) => `• ${u.kode_internal || '-'} / SN ${u.serial_number || '-'}`).join('\n');
                }
                return s;
            }
        },
        { header: 'Satuan', field: 'unit_used', width: 16 },
        { header: 'Qty', width: 14, align: 'right', accessor: (row) => formatQty(row.qty_in_unit) },
        { header: 'Harga', width: 22, align: 'right', accessor: (row) => formatCurrency(row.harga_per_unit) },
        { header: 'Diskon', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_diskon_item) },
        { header: 'Subtotal', width: 22, align: 'right', accessor: (row) => formatCurrency(row.subtotal) }
    ];

    const summary = [{ label: 'Subtotal', value: formatCurrency(data.subtotal) }];
    if (Number(data.total_diskon_header) > 0) {
        summary.push({ label: 'Diskon', value: `-${formatCurrency(data.total_diskon_header)}` });
    }
    if (Number(data.pajak_nominal) > 0) {
        summary.push({ label: 'DPP', value: formatCurrency(data.dpp) }, { label: `${data.pajak_nama} (${data.pajak_persen}%)`, value: formatCurrency(data.pajak_nominal) });
    }
    if (data.pembulatan && Number(data.pembulatan) !== 0) {
        summary.push({ label: 'Pembulatan', value: formatCurrency(data.pembulatan) });
    }
    summary.push({ separator: true }, { label: 'Nilai Kalkulasi', value: formatCurrency(data.grand_total), bold: true });
    if (data.status === 'approved') {
        summary.push({ label: 'Nilai Diakui', value: formatCurrency(data.nilai_diakui), bold: true }, { label: 'Selisih', value: formatCurrency(data.selisih) });
    }

    const audit = [];
    if (data.created_by?.name) {
        audit.push({ label: 'Dibuat oleh', value: data.created_by.name, date: formatDateTime(data.created_at) });
    }
    if ((data.status === 'lock' || data.status === 'approved') && data.locked_by?.name) {
        audit.push({ label: 'Dikunci oleh', value: data.locked_by.name, date: formatDateTime(data.locked_at) });
    }
    if (data.status === 'approved' && data.approved_by?.name) {
        audit.push({ label: 'Disetujui oleh', value: data.approved_by.name, date: formatDateTime(data.approved_at) });
    }

    const notesParts = [];
    if (data.notes) notesParts.push(data.notes);
    if (data.catatan_approval) notesParts.push(`Catatan Approval: ${data.catatan_approval}`);

    exportDocumentPdf({
        title: 'Retur Penjualan',
        filename: data.nomor_dokumen || 'retur_penjualan',
        info,
        table: { columns, data: data.details || [] },
        summary,
        audit,
        notes: notesParts.length > 0 ? notesParts.join('\n') : null
    });
}

// Computed for selisih
const selisihValue = computed(() => {
    if (!approveTarget.value) return 0;
    return (approveForm.value.nilai_diakui || 0) - (parseFloat(approveTarget.value.grand_total) || 0);
});

const selisihClass = computed(() => {
    if (selisihValue.value > 0) return 'text-green-500';
    if (selisihValue.value < 0) return 'text-red-500';
    return 'text-surface-500';
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Buat Retur" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <ListFiltersSheet :active-count="activeFilterCount">
                    <Select v-model="selectedCustomer" :options="customers" optionLabel="nama" optionValue="id" placeholder="Customer" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Warehouse" filter showClear @change="onFilterChange" />
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
                <DataTableHeader v-model="searchQuery" title="Daftar Retur Penjualan" placeholder="Cari no. dokumen, customer..." @search="onSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data retur penjualan</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="No. Dokumen" sortable style="min-width: 150px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
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

            <Column header="Warehouse" style="min-width: 150px">
                <template #body="{ data }">
                    {{ data.warehouse?.nama_warehouse || '-' }}
                </template>
            </Column>

            <Column header="Item" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.details_count" severity="secondary" />
                </template>
            </Column>

            <Column field="grand_total" header="Nilai Kalkulasi" sortable style="min-width: 140px" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-semibold">{{ formatCurrency(data.grand_total) }}</span>
                </template>
            </Column>

            <Column field="nilai_diakui" header="Nilai Diakui" sortable style="min-width: 140px" bodyClass="text-right">
                <template #body="{ data }">
                    <span v-if="data.status === 'approved'" class="font-semibold">{{ formatCurrency(data.nilai_diakui) }}</span>
                    <span v-else class="text-surface-400">-</span>
                </template>
            </Column>

            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                </template>
            </Column>

            <Column header="Aksi" style="min-width: 250px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <RowActionButtons>
                        <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'"  />
                        <Button icon="pi pi-file-pdf" severity="help" text rounded :loading="exporting" @click="exportDocPdf(data)" v-tooltip.top="'Export PDF'"  />
                        <Button v-if="canUpdate && data.status === 'draft'" icon="pi pi-pencil" severity="warning" text rounded @click="editItem(data)" v-tooltip.top="'Edit'"  />
                        <Button v-if="canDelete && data.status === 'draft'" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'"  />
                        <Button v-if="canLock && data.status === 'draft'" icon="pi pi-lock" severity="warning" text rounded @click="confirmLock(data)" v-tooltip.top="'Lock'"  />
                        <Button v-if="canApprove && data.status === 'lock'" icon="pi pi-check" severity="success" text rounded @click="openApproveDialog(data)" v-tooltip.top="'Approve'"  />
                    </RowActionButtons>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Retur Penjualan"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="900px"
        >
            <template #content>
                <div v-if="detailData.ulid">
                    <!-- Header Info -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <DetailItem label="No. Dokumen" :value="detailData.nomor_dokumen" />
                        <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal)" />
                        <DetailItem label="Customer" :value="detailData.customer?.nama" />
                        <DetailItem label="Warehouse" :value="detailData.warehouse?.nama_warehouse" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                        <DetailItem v-if="detailData.sales" label="Ref. Sales" :value="detailData.sales?.nomor_dokumen" />
                    </div>

                    <!-- Details Table -->
                    <div class="mt-4">
                        <h4 class="text-lg font-medium mb-3">Detail Produk ({{ detailData.details?.length || 0 }} item)</h4>
                        <DetailTable :data="detailData.details" :columns="detailColumns">
                            <template #product="{ item }">
                                <span class="font-medium">{{ item.product?.kode_produk }}</span>
                                <br />
                                <span class="text-surface-500 text-sm">{{ item.product?.nama_produk }}</span>
                                <div v-if="item.serial_units?.length" class="mt-1 space-y-0.5">
                                    <div v-for="(u, ui) in item.serial_units" :key="ui" class="text-xs font-mono text-surface-500">
                                        {{ u.kode_internal || u.serial_number }}<span v-if="u.serial_number"> · SN {{ u.serial_number }}</span
                                        ><span v-if="u.grade"> · {{ u.grade }}</span>
                                    </div>
                                </div>
                            </template>
                            <template #qty="{ item }">{{ formatQty(item.qty_in_unit) }}</template>
                            <template #harga="{ item }">{{ formatCurrency(item.harga_per_unit) }}</template>
                            <template #diskon="{ item }">{{ formatCurrency(item.total_diskon_item) }}</template>
                            <template #subtotal="{ item }">
                                <span class="font-medium">{{ formatCurrency(item.subtotal) }}</span>
                            </template>
                        </DetailTable>
                    </div>

                    <!-- Totals -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                        <div></div>
                        <div class="border border-surface-200 rounded-lg p-4 space-y-2">
                            <div class="flex justify-between">
                                <span>Subtotal</span>
                                <span>{{ formatCurrency(detailData.subtotal) }}</span>
                            </div>
                            <div v-if="detailData.total_diskon_header > 0" class="flex justify-between text-red-500">
                                <span>Diskon</span>
                                <span>-{{ formatCurrency(detailData.total_diskon_header) }}</span>
                            </div>
                            <div v-if="Number(detailData.pajak_nominal) > 0" class="flex justify-between">
                                <span>DPP</span>
                                <span>{{ formatCurrency(detailData.dpp) }}</span>
                            </div>
                            <div v-if="Number(detailData.pajak_nominal) > 0" class="flex justify-between">
                                <span>{{ detailData.pajak_nama }} ({{ detailData.pajak_persen }}%)</span>
                                <span>{{ formatCurrency(detailData.pajak_nominal) }}</span>
                            </div>
                            <div v-if="detailData.pembulatan && detailData.pembulatan !== 0" class="flex justify-between">
                                <span>Pembulatan</span>
                                <span :class="detailData.pembulatan > 0 ? 'text-green-600' : 'text-red-500'"> {{ detailData.pembulatan > 0 ? '+' : '' }}{{ formatCurrency(detailData.pembulatan) }} </span>
                            </div>
                            <Divider />
                            <div class="flex justify-between font-bold text-lg">
                                <span>Nilai Kalkulasi</span>
                                <span>{{ formatCurrency(detailData.grand_total) }}</span>
                            </div>
                            <template v-if="detailData.status === 'approved'">
                                <Divider />
                                <div class="flex justify-between font-bold text-lg text-green-600">
                                    <span>Nilai Diakui</span>
                                    <span>{{ formatCurrency(detailData.nilai_diakui) }}</span>
                                </div>
                                <div class="flex justify-between" :class="detailData.selisih > 0 ? 'text-green-500' : detailData.selisih < 0 ? 'text-red-500' : 'text-surface-500'">
                                    <span>Selisih</span>
                                    <span>{{ detailData.selisih > 0 ? '+' : '' }}{{ formatCurrency(detailData.selisih) }}</span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Locked info -->
                    <div class="mt-4 pt-4 border-t border-surface-200" v-if="(detailData.status === 'lock' || detailData.status === 'approved') && detailData.locked_by">
                        <div class="flex items-center gap-2 text-surface-500 text-sm">
                            <i class="pi pi-lock text-blue-500"></i>
                            <span>Dikunci: {{ formatDateTime(detailData.locked_at) }} oleh {{ detailData.locked_by?.name }}</span>
                        </div>
                    </div>

                    <!-- Approved info -->
                    <div class="mt-2" v-if="detailData.status === 'approved' && detailData.approved_by">
                        <div class="flex items-center gap-2 text-surface-500 text-sm">
                            <i class="pi pi-check-circle text-green-500"></i>
                            <span>Disetujui: {{ formatDateTime(detailData.approved_at) }} oleh {{ detailData.approved_by?.name }}</span>
                        </div>
                    </div>

                    <!-- Approval notes -->
                    <div v-if="detailData.catatan_approval" class="mt-4">
                        <span class="text-surface-500 text-sm block mb-1">Catatan Approval</span>
                        <p class="m-0">{{ detailData.catatan_approval }}</p>
                    </div>

                    <!-- Notes -->
                    <div v-if="detailData.notes" class="mt-4">
                        <span class="text-surface-500 text-sm block mb-1">Catatan</span>
                        <p class="m-0">{{ detailData.notes }}</p>
                    </div>

                    <!-- Deposit info -->
                    <div v-if="detailData.deposit" class="mt-4 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                        <div class="flex items-center justify-between mb-2">
                            <h5 class="font-medium text-green-700 dark:text-green-300 m-0">Deposit Customer Terbentuk</h5>
                            <RouterLink
                                v-if="detailData.deposit.ulid"
                                :to="{ name: 'penjualan-deposit', query: { detail: detailData.deposit.ulid } }"
                                class="text-sm text-primary hover:underline"
                            >
                                Lihat deposit
                            </RouterLink>
                        </div>
                        <div class="text-sm text-green-600 dark:text-green-400">
                            <div>Nominal: {{ formatCurrency(detailData.deposit.nominal_awal) }}</div>
                            <div>Sisa: {{ formatCurrency(detailData.deposit.sisa_deposit) }}</div>
                            <div>Status: {{ detailData.deposit.status }}</div>
                        </div>
                    </div>
                </div>
            </template>

            <template #footer-extra>
                <div class="flex flex-wrap gap-2">
                    <Button label="Export PDF" icon="pi pi-file-pdf" severity="help" outlined :loading="exporting" @click="exportDocPdf(detailData)" />
                    <Button v-if="canUpdate && detailData.status === 'draft'" label="Edit" icon="pi pi-pencil" severity="warning" @click=" editItem(detailData); detailDialog = false; " text rounded />
                    <Button v-if="canDelete && detailData.status === 'draft'" label="Hapus" icon="pi pi-trash" severity="danger" @click=" confirmDelete(detailData); detailDialog = false; " text rounded />
                    <Button v-if="canLock && detailData.status === 'draft'" label="Lock" icon="pi pi-lock" severity="warning" :loading="processingAction" @click="confirmLock(detailData)" />
                    <Button v-if="canApprove && detailData.status === 'lock'" label="Approve" icon="pi pi-check" severity="success" @click="openApproveDialog(detailData)" text rounded />
                </div>
            </template>
        </DetailDialog>

        <!-- Approve Dialog -->
        <Dialog v-model:visible="approveDialog" header="Approve Retur Penjualan" modal :style="{ width: '450px' }" :closable="!processingAction">
            <div v-if="approveTarget" class="space-y-4">
                <!-- Info -->
                <div class="bg-surface-50 rounded-lg p-4">
                    <div class="font-medium mb-2">{{ approveTarget.nomor_dokumen }}</div>
                    <div class="text-sm text-surface-500">{{ approveTarget.customer?.nama }} - {{ formatDateTime(approveTarget.tanggal) }}</div>
                </div>

                <!-- Nilai Kalkulasi (read-only) -->
                <div>
                    <label class="block text-sm font-medium text-surface-600 mb-1">Nilai Kalkulasi</label>
                    <div class="font-semibold text-lg">{{ formatCurrency(approveTarget.grand_total) }}</div>
                </div>

                <!-- Nilai Diakui -->
                <div>
                    <label class="block text-sm font-medium text-surface-600 mb-1">Nilai Diakui <span class="text-red-500">*</span></label>
                    <InputNumber
                        v-select-on-focus
                        v-model="approveForm.nilai_diakui"
                        :min="0"
                        :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                        :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                        :locale="getLocale"
                        :minFractionDigits="getCurrencyMinFractionDigits"
                        :maxFractionDigits="getCurrencyMaxFractionDigits"
                        class="w-full"
                    />
                    <small class="text-surface-500">Nilai yang diakui oleh customer</small>
                </div>

                <!-- Selisih (calculated) -->
                <div>
                    <label class="block text-sm font-medium text-surface-600 mb-1">Selisih</label>
                    <div class="font-semibold text-lg" :class="selisihClass">{{ selisihValue > 0 ? '+' : '' }}{{ formatCurrency(selisihValue) }}</div>
                </div>

                <!-- Catatan -->
                <div>
                    <label class="block text-sm font-medium text-surface-600 mb-1">Catatan Approval</label>
                    <Textarea v-model="approveForm.catatan_approval" rows="2" class="w-full" placeholder="Catatan persetujuan (opsional)" />
                </div>

                <!-- Warning -->
                <Message severity="info" :closable="false">
                    Setelah disetujui, piutang digesek dulu (jika ada); sisa nilai diakui menjadi deposit customer.
                </Message>
            </div>

            <template #footer>
                <Button label="Batal" severity="secondary" outlined @click="approveDialog = false" :disabled="processingAction" />
                <Button label="Approve" icon="pi pi-check" severity="success" @click="submitApprove" :loading="processingAction" :disabled="approveForm.nilai_diakui < 0" text rounded />
            </template>
        </Dialog>
    </div>
</template>

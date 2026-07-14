<script setup>
import { customersApi, tipeCustomersApi, kategoriCustomersApi } from '@/api';
import { onMounted, ref, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useConfirm } from 'primevue/useconfirm';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import CustomerFormDialog from '@/components/common/CustomerFormDialog.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';

const notify = useNotification();
const confirm = useConfirm();
const authStore = useAuthStore();
const { todayString } = useFormatters();

// Permissions
const canCreate = computed(() => authStore.can('customer.create'));
const canUpdate = computed(() => authStore.can('customer.update'));
const canDelete = computed(() => authStore.can('customer.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const { exporting, exportListPdf } = useExportPdf();
const exportingExcel = ref(false);

// Data
const dt = ref();
const customers = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

// Dropdown options
const tipeCustomerOptions = ref([]);
const kategoriCustomerOptions = ref([]);

// Search
const searchQuery = ref('');

// Pagination & Sort
const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'created_at',
    sortOrder: -1
});

// Filters
const selectedStatus = ref(null);
const selectedJenis = ref(null);
const selectedTipeCustomer = ref(null);
const selectedKategoriCustomer = ref(null);

const statusOptions = ref([
    { label: 'Semua Status', value: null },
    { label: 'Aktif', value: 'active' },
    { label: 'Nonaktif', value: 'inactive' }
]);

const jenisOptions = ref([
    { label: 'Semua Jenis', value: null },
    { label: 'Walk-in', value: 'walk_in' },
    { label: 'Spesifik', value: 'spesifik' }
]);

// Dialog states
const customerDialog = ref(false);
const detailDialog = ref(false);
const loadingDetail = ref(false);
const detailData = ref({});

// Customer yang sedang diedit (null = tambah baru) — diteruskan ke CustomerFormDialog (DRY)
const editTarget = ref(null);

onMounted(async () => {
    await Promise.all([loadCustomers(), loadTipeCustomers(), loadKategoriCustomers()]);
});

async function loadCustomers() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || 'created_at',
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };

        // Search
        if (searchQuery.value?.trim()) {
            params.search = searchQuery.value.trim();
        }

        // Filter by status
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }

        // Filter by jenis
        if (selectedJenis.value) {
            params.jenis = selectedJenis.value;
        }

        // Filter by tipe customer
        if (selectedTipeCustomer.value) {
            params.tipe_customer_ulid = selectedTipeCustomer.value;
        }

        // Filter by kategori customer
        if (selectedKategoriCustomer.value) {
            params.kategori_customer_ulid = selectedKategoriCustomer.value;
        }

        const response = await customersApi.getAll(params);
        if (response.data.success) {
            customers.value = response.data.data.customers;
            totalRecords.value = response.data.data.pagination.total;
        }
    } catch (error) {
        notify.loadListError('customer');
    } finally {
        loading.value = false;
    }
}

async function loadTipeCustomers() {
    try {
        const response = await tipeCustomersApi.getList();
        if (response.data.success) {
            tipeCustomerOptions.value = response.data.data.tipe_customers;
        }
    } catch (error) {
        console.error('Failed to load tipe customers:', error);
        notify.apiError(error, 'Gagal load tipe customers');
    }
}

async function loadKategoriCustomers() {
    try {
        const response = await kategoriCustomersApi.getList();
        if (response.data.success) {
            kategoriCustomerOptions.value = response.data.data.kategori_customers;
        }
    } catch (error) {
        console.error('Failed to load kategori customers:', error);
        notify.apiError(error, 'Gagal load kategori customers');
    }
}

function onPage(event) {
    lazyParams.value.first = event.first;
    lazyParams.value.rows = event.rows;
    loadCustomers();
}

function onSort(event) {
    lazyParams.value.sortField = event.sortField;
    lazyParams.value.sortOrder = event.sortOrder;
    loadCustomers();
}

function onFilter() {
    lazyParams.value.first = 0;
    loadCustomers();
}

function clearSearch() {
    searchQuery.value = '';
    lazyParams.value.first = 0;
    loadCustomers();
}

function doSearch() {
    lazyParams.value.first = 0;
    loadCustomers();
}

// Reset all filters
function resetFilters() {
    selectedStatus.value = null;
    selectedJenis.value = null;
    selectedTipeCustomer.value = null;
    selectedKategoriCustomer.value = null;
    lazyParams.value.first = 0;
    loadCustomers();
}

function openNew() {
    editTarget.value = null;
    customerDialog.value = true;
}

function editCustomer(customerData) {
    editTarget.value = customerData;
    customerDialog.value = true;
}

// Setelah CustomerFormDialog berhasil simpan → refresh daftar
function onCustomerSaved() {
    loadCustomers();
}

// Export Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedStatus.value) params.status = selectedStatus.value;
        if (selectedJenis.value) params.jenis = selectedJenis.value;
        if (selectedTipeCustomer.value) params.tipe_customer_ulid = selectedTipeCustomer.value;
        if (selectedKategoriCustomer.value) params.kategori_customer_ulid = selectedKategoriCustomer.value;

        const response = await customersApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_customer_${todayString()}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);

        notify.exportSuccess();
    } catch (error) {
        notify.exportError();
    } finally {
        exportingExcel.value = false;
    }
}

function confirmToggleStatus(data) {
    const isActive = data.status === 'active';
    const action = isActive ? 'menonaktifkan' : 'mengaktifkan';

    confirm.require({
        message: `Apakah Anda yakin ingin ${action} customer "${data.nama}"?`,
        header: isActive ? 'Konfirmasi Nonaktifkan' : 'Konfirmasi Aktifkan',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Batal',
            severity: 'secondary',
            outlined: true
        },
        acceptProps: {
            label: isActive ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan',
            severity: isActive ? 'warn' : 'success'
        },
        accept: () => toggleStatus(data)
    });
}

async function toggleStatus(data) {
    try {
        const response = await customersApi.toggleStatus(data.ulid);
        if (response.data.success) {
            notify.success(response.data.message);
            await loadCustomers();
        }
    } catch (error) {
        notify.statusChangeError('customer', error);
    }
}

function confirmDelete(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin menghapus customer "${data.nama}"? Data yang dihapus tidak dapat dikembalikan.`,
        header: 'Konfirmasi Hapus',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Batal',
            severity: 'secondary',
            outlined: true
        },
        acceptProps: {
            label: 'Ya, Hapus',
            severity: 'danger'
        },
        accept: () => deleteCustomer(data)
    });
}

async function deleteCustomer(data) {
    try {
        const response = await customersApi.delete(data.ulid);
        if (response.data.success) {
            notify.deleted('customer');
            await loadCustomers();
        }
    } catch (error) {
        notify.deleteError(error);
    }
}

function getStatusSeverity(status) {
    return status === 'active' ? 'success' : 'danger';
}

function getStatusLabel(status) {
    return status === 'active' ? 'Aktif' : 'Nonaktif';
}

function getJenisLabel(jenis) {
    return jenis === 'walk_in' ? 'Walk-in' : 'Spesifik';
}

function getJenisSeverity(jenis) {
    return jenis === 'walk_in' ? 'info' : 'secondary';
}

function getToggleLabel(status) {
    return status === 'active' ? 'Nonaktifkan' : 'Aktifkan';
}

function getToggleSeverity(status) {
    return status === 'active' ? 'warn' : 'success';
}

// Check if customer is walk_in (for disabling actions)
function isWalkIn(customerData) {
    return customerData.jenis === 'walk_in';
}

// Export PDF
async function exportPdf() {
    const filterParts = [];
    const params = {
        page: 1,
        per_page: 999999,
        sort_field: lazyParams.value.sortField || 'created_at',
        sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
    };

    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedStatus.value) {
        params.status = selectedStatus.value;
        filterParts.push(`Status: ${selectedStatus.value === 'active' ? 'Aktif' : 'Nonaktif'}`);
    }
    if (selectedJenis.value) {
        params.jenis = selectedJenis.value;
        filterParts.push(`Jenis: ${selectedJenis.value === 'walk_in' ? 'Walk-in' : 'Spesifik'}`);
    }
    if (selectedTipeCustomer.value) {
        params.tipe_customer_ulid = selectedTipeCustomer.value;
        const tc = tipeCustomerOptions.value.find((t) => t.ulid === selectedTipeCustomer.value);
        if (tc) filterParts.push(`Tipe: ${tc.nama_tipe}`);
    }
    if (selectedKategoriCustomer.value) {
        params.kategori_customer_ulid = selectedKategoriCustomer.value;
        const kc = kategoriCustomerOptions.value.find((k) => k.ulid === selectedKategoriCustomer.value);
        if (kc) filterParts.push(`Kategori: ${kc.nama_kategori}`);
    }
    if (searchQuery.value) filterParts.push(`Search: "${searchQuery.value}"`);

    let allData;
    try {
        const response = await customersApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.customers;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode', field: 'kode_customer', width: 22 },
        { header: 'Nama', field: 'nama' },
        { header: 'Telepon', field: 'telepon', width: 26 },
        { header: 'Jenis', width: 16, align: 'center', accessor: (row) => getJenisLabel(row.jenis) },
        { header: 'Tipe Customer', accessor: (row) => row.tipe_customer?.nama_tipe || '-' },
        { header: 'Kategori Customer', accessor: (row) => row.kategori_customer?.nama_kategori || '-' },
        { header: 'Status', width: 16, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Customer',
        filename: 'daftar_customer',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} customer`
    });
}

async function viewDetail(data) {
    detailDialog.value = true;
    loadingDetail.value = true;
    detailData.value = {};
    try {
        const response = await customersApi.get(data.ulid);
        if (response.data.success) {
            detailData.value = response.data.data.customer;
        }
    } catch (error) {
        notify.loadDetailError('customer');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Customer" icon="pi pi-plus" severity="primary" @click="openNew" />
                </template>

                <template #end>
                    <div class="flex gap-2 flex-wrap">
                        <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Filter Status" class="w-36" filter filterPlaceholder="Cari..." @change="onFilter" />
                        <Select v-model="selectedJenis" :options="jenisOptions" optionLabel="label" optionValue="value" placeholder="Filter Jenis" class="w-36" filter filterPlaceholder="Cari..." @change="onFilter" />
                        <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                    </div>
                </template>
            </Toolbar>

            <DataTable
                ref="dt"
                :value="customers"
                :lazy="true"
                :paginator="true"
                :rows="lazyParams.rows"
                :totalRecords="totalRecords"
                :loading="loading"
                :rowsPerPageOptions="[10, 25, 50]"
                :first="lazyParams.first"
                dataKey="ulid"
                @page="onPage"
                @sort="onSort"
                stripedRows
                showGridlines
                scrollable
            >
                <template #header>
                    <DataTableHeader v-model="searchQuery" title="Customer" placeholder="Cari kode, nama, telepon, email..." search-width="w-80" @search="doSearch" @clear="clearSearch">
                        <template #extra>
                            <div class="flex gap-2">
                                <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                                <Button v-if="canExport" icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                            </div>
                        </template>
                    </DataTableHeader>
                </template>

                <template #empty>
                    <div class="text-center py-4">
                        <i class="pi pi-user text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data customer</p>
                    </div>
                </template>

                <Column field="kode_customer" header="Kode" sortable style="min-width: 120px"></Column>
                <Column field="nama" header="Nama" sortable style="min-width: 180px"></Column>
                <Column field="telepon" header="Telepon" sortable style="min-width: 130px"></Column>
                <Column field="jenis" header="Jenis" sortable style="min-width: 100px">
                    <template #body="slotProps">
                        <Tag :value="getJenisLabel(slotProps.data.jenis)" :severity="getJenisSeverity(slotProps.data.jenis)" />
                    </template>
                </Column>
                <Column header="Tipe" style="min-width: 130px">
                    <template #body="slotProps">
                        {{ slotProps.data.tipe_customer?.nama_tipe || '-' }}
                    </template>
                </Column>
                <Column header="Kategori" style="min-width: 130px">
                    <template #body="slotProps">
                        {{ slotProps.data.kategori_customer?.nama_kategori || '-' }}
                    </template>
                </Column>
                <Column field="status" header="Status" sortable style="min-width: 100px">
                    <template #body="slotProps">
                        <Tag :value="getStatusLabel(slotProps.data.status)" :severity="getStatusSeverity(slotProps.data.status)" />
                    </template>
                </Column>
                <Column :exportable="false" style="min-width: 220px" alignFrozen="right" frozen>
                    <template #body="slotProps">
                        <Button icon="pi pi-eye" outlined rounded class="mr-2" severity="info" @click="viewDetail(slotProps.data)" v-tooltip.top="'Lihat Detail'" />
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editCustomer(slotProps.data)" v-tooltip.top="'Edit'" />
                        <Button
                            v-if="canUpdate"
                            icon="pi pi-power-off"
                            outlined
                            rounded
                            class="mr-2"
                            :severity="getToggleSeverity(slotProps.data.status)"
                            :disabled="isWalkIn(slotProps.data)"
                            @click="confirmToggleStatus(slotProps.data)"
                            v-tooltip.top="isWalkIn(slotProps.data) ? 'Walk-in tidak dapat dinonaktifkan' : getToggleLabel(slotProps.data.status)"
                        />
                        <Button
                            v-if="canDelete"
                            icon="pi pi-trash"
                            outlined
                            rounded
                            severity="danger"
                            :disabled="isWalkIn(slotProps.data)"
                            @click="confirmDelete(slotProps.data)"
                            v-tooltip.top="isWalkIn(slotProps.data) ? 'Walk-in tidak dapat dihapus' : 'Hapus'"
                        />
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- Customer Dialog (reusable, DRY — dipakai juga di POS) -->
        <CustomerFormDialog v-model:visible="customerDialog" :customer="editTarget" @saved="onCustomerSaved" />

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Customer"
            :loading="loadingDetail"
            width="700px"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <!-- Informasi Dasar -->
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Kode Customer" :value="detailData.kode_customer" />
                    <DetailItem label="Nama" :value="detailData.nama" />
                    <DetailItem label="Telepon" :value="detailData.telepon" />
                    <DetailItem label="Email" :value="detailData.email" />
                </div>

                <Divider />

                <!-- Informasi Identitas -->
                <h6 class="text-surface-600 font-medium mb-3">Informasi Identitas</h6>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="NIK" :value="detailData.nik" />
                    <DetailItem label="NPWP" :value="detailData.npwp" />
                </div>

                <Divider />

                <!-- Alamat -->
                <h6 class="text-surface-600 font-medium mb-3">Alamat</h6>
                <DetailItem label="Alamat Lengkap" :value="detailData.alamat" />

                <Divider />

                <!-- Klasifikasi -->
                <h6 class="text-surface-600 font-medium mb-3">Klasifikasi</h6>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Tipe Customer" :value="detailData.tipe_customer?.nama_tipe" />
                    <DetailItem label="Kategori Customer" :value="detailData.kategori_customer?.nama_kategori" />
                </div>

                <Divider />

                <!-- Status -->
                <h6 class="text-surface-600 font-medium mb-3">Status</h6>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Jenis" :value="getJenisLabel(detailData.jenis)" type="badge" :badge-severity="getJenisSeverity(detailData.jenis)" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

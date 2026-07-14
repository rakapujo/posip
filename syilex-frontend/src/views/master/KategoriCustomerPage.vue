<script setup>
import { ref, computed } from 'vue';
import { kategoriCustomersApi } from '@/api';
import { useMasterCrud } from '@/composables/useMasterCrud';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const notify = useNotification();
const authStore = useAuthStore();
const { shouldUppercase, todayString } = useFormatters();

// Permissions
const canCreate = computed(() => authStore.can('kategori-customer.create'));
const canUpdate = computed(() => authStore.can('kategori-customer.update'));
const canDelete = computed(() => authStore.can('kategori-customer.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const { exporting, exportListPdf } = useExportPdf();
const exportingExcel = ref(false);

// Initialize CRUD composable
const {
    // State
    dt,
    items: kategoriCustomers,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
    statusOptions,

    // Dialog states
    itemDialog: kategoriCustomerDialog,
    detailDialog,

    // Form states
    submitted,
    saving,
    loadingDetail,
    item: kategoriCustomer,
    detailData,
    isEdit,

    // Pagination & Sort
    onPage,
    onSort,
    onFilter,

    // Search
    doSearch,
    clearSearch,

    // Filters
    resetFilters,

    // Dialog management
    openNew,
    hideDialog,
    editItem: editKategoriCustomer,
    viewDetail,

    // CRUD operations
    saveItem,
    confirmToggleStatus,
    confirmDelete,

    // Status helpers
    getStatusSeverity,
    getStatusLabel,
    getToggleLabel,
    getToggleSeverity
} = useMasterCrud(kategoriCustomersApi, {
    entityName: 'kategori_customer',
    dataKey: 'kategori_customers',
    emptyForm: {
        kode_kategori: '',
        nama_kategori: '',
        diskon_tipe: 'none',
        diskon_nilai: 0,
        keterangan: '',
        status: 'active'
    },
    transformFormData: (data, isEditMode) => {
        const result = {
            nama_kategori: data.nama_kategori?.trim(),
            diskon_tipe: data.diskon_tipe || 'none',
            diskon_nilai: data.diskon_tipe === 'none' ? 0 : Number(data.diskon_nilai) || 0,
            keterangan: data.keterangan?.trim() || null,
            status: data.status
        };
        if (!isEditMode) {
            result.kode_kategori = data.kode_kategori?.trim();
        }
        return result;
    }
});

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
    if (searchQuery.value) filterParts.push(`Search: "${searchQuery.value}"`);

    let allData;
    try {
        const response = await kategoriCustomersApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.kategori_customers;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode Kategori', field: 'kode_kategori' },
        { header: 'Nama Kategori', field: 'nama_kategori' },
        { header: 'Keterangan', accessor: (row) => row.keterangan || '-' },
        { header: 'Status', width: 20, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Kategori Customer',
        filename: 'daftar_kategori_customer',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} kategori customer`
    });
}

// Export Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedStatus.value) params.status = selectedStatus.value;

        const response = await kategoriCustomersApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_kategori_customer_${todayString()}.xlsx`);
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

// Custom save dengan validasi
async function saveKategoriCustomer() {
    submitted.value = true;

    if (!kategoriCustomer.value.kode_kategori?.trim()) return;
    if (!kategoriCustomer.value.nama_kategori?.trim()) return;
    if (!kategoriCustomer.value.status) return;

    await saveItem();
}
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Kategori Customer" icon="pi pi-plus" severity="primary" @click="openNew" />
                </template>

                <template #end>
                    <div class="flex gap-2">
                        <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Filter Status" class="w-40" filter @change="onFilter" />
                        <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                    </div>
                </template>
            </Toolbar>

            <DataTable
                ref="dt"
                :value="kategoriCustomers"
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
                    <DataTableHeader v-model="searchQuery" title="Kategori Customer" placeholder="Cari kode, nama..." @search="doSearch" @clear="clearSearch">
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
                        <i class="pi pi-users text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data kategori customer</p>
                    </div>
                </template>

                <Column field="kode_kategori" header="Kode" sortable style="min-width: 120px"></Column>
                <Column field="nama_kategori" header="Nama Kategori" sortable style="min-width: 200px"></Column>
                <Column field="keterangan" header="Keterangan" style="min-width: 250px">
                    <template #body="slotProps">
                        <span class="text-surface-500">{{ slotProps.data.keterangan || '-' }}</span>
                    </template>
                </Column>
                <Column field="status" header="Status" sortable style="min-width: 100px">
                    <template #body="slotProps">
                        <Tag :value="getStatusLabel(slotProps.data.status)" :severity="getStatusSeverity(slotProps.data.status)" />
                    </template>
                </Column>
                <Column :exportable="false" style="min-width: 220px" alignFrozen="right" frozen>
                    <template #body="slotProps">
                        <Button icon="pi pi-eye" outlined rounded class="mr-2" severity="info" @click="viewDetail(slotProps.data)" v-tooltip.top="'Lihat Detail'" aria-label="Lihat Detail" />
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editKategoriCustomer(slotProps.data)" v-tooltip.top="'Edit'" aria-label="Edit" />
                        <Button
                            v-if="canUpdate"
                            icon="pi pi-power-off"
                            outlined
                            rounded
                            class="mr-2"
                            :severity="getToggleSeverity(slotProps.data.status)"
                            @click="confirmToggleStatus(slotProps.data)"
                            v-tooltip.top="getToggleLabel(slotProps.data.status)"
                            :aria-label="getToggleLabel(slotProps.data.status)"
                        />
                        <Button v-if="canDelete" icon="pi pi-trash" outlined rounded severity="danger" @click="confirmDelete(slotProps.data)" v-tooltip.top="'Hapus'" aria-label="Hapus" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- Kategori Customer Dialog -->
        <Dialog v-model:visible="kategoriCustomerDialog" :style="{ width: '500px' }" :header="isEdit ? 'Edit Kategori Customer' : 'Tambah Kategori Customer'" :modal="true" :closable="!saving">
            <div class="flex flex-col gap-4">
                <div>
                    <label class="block font-medium mb-2">
                        Kode Kategori <span class="text-red-500">*</span>
                        <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                    </label>
                    <InputText
                        v-model.trim="kategoriCustomer.kode_kategori"
                        :invalid="submitted && !kategoriCustomer.kode_kategori"
                        :disabled="isEdit"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan kode kategori"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !kategoriCustomer.kode_kategori" class="text-red-500">Kode wajib diisi</small>
                    <small v-else-if="!isEdit" class="text-surface-500">Kode tidak dapat diubah setelah disimpan</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama Kategori <span class="text-red-500">*</span></label>
                    <InputText
                        v-model.trim="kategoriCustomer.nama_kategori"
                        :invalid="submitted && !kategoriCustomer.nama_kategori"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan nama kategori"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !kategoriCustomer.nama_kategori" class="text-red-500">Nama wajib diisi</small>
                </div>

                <div v-if="authStore.can('customer-discount.manage')">
                    <label class="block font-medium mb-2">Diskon Otomatis</label>
                    <div class="flex gap-2 items-end">
                        <Select
                            v-model="kategoriCustomer.diskon_tipe"
                            :options="[
                                { label: 'Tidak Ada', value: 'none' },
                                { label: 'Persen (%)', value: 'percent' },
                                { label: 'Nominal (Rp)', value: 'nominal' }
                            ]"
                            optionLabel="label"
                            optionValue="value"
                            class="w-40"
                        />
                        <InputNumber v-if="kategoriCustomer.diskon_tipe !== 'none'" v-model="kategoriCustomer.diskon_nilai" :min="0" :max="kategoriCustomer.diskon_tipe === 'percent' ? 100 : 999999999" fluid />
                        <span v-if="kategoriCustomer.diskon_tipe === 'percent'" class="text-sm text-surface-500 mb-2">%</span>
                    </div>
                    <small class="text-surface-400">Diskon otomatis di POS untuk customer berkategori ini (slot nota 2)</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Keterangan</label>
                    <Textarea v-model="kategoriCustomer.keterangan" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid rows="3" placeholder="Keterangan (opsional)" autoResize />
                </div>

                <div>
                    <label class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="kategoriCustomer.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="submitted && !kategoriCustomer.status"
                        fluid
                        filter
                    />
                    <small v-if="submitted && !kategoriCustomer.status" class="text-red-500">Status wajib dipilih</small>
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveKategoriCustomer" :loading="saving" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Kategori Customer"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Kode Kategori" :value="detailData.kode_kategori" />
                    <DetailItem label="Nama Kategori" :value="detailData.nama_kategori" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                </div>
                <div class="mt-4">
                    <DetailItem label="Keterangan" :value="detailData.keterangan" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

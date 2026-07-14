<script setup>
import { ref, computed } from 'vue';
import { suppliersApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useMasterCrud } from '@/composables/useMasterCrud';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const notify = useNotification();
const authStore = useAuthStore();
const { shouldUppercase, todayString } = useFormatters();

// Permissions
const canCreate = computed(() => authStore.can('supplier.create'));
const canUpdate = computed(() => authStore.can('supplier.update'));
const canDelete = computed(() => authStore.can('supplier.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const { exporting, exportListPdf } = useExportPdf();
const exportingExcel = ref(false);

// Initialize CRUD composable
const {
    // State
    dt,
    items: suppliers,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
    statusOptions,

    // Dialog states
    itemDialog: supplierDialog,
    detailDialog,

    // Form states
    submitted,
    saving,
    loadingDetail,
    item: supplier,
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
    editItem: editSupplier,
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
} = useMasterCrud(suppliersApi, {
    entityName: 'supplier',
    dataKey: 'suppliers',
    emptyForm: {
        kode_supplier: '',
        nama_supplier: '',
        nama_pic: '',
        telepon: '',
        email: '',
        alamat: '',
        npwp: '',
        bank_nama: '',
        bank_rekening: '',
        bank_atas_nama: '',
        tempo_default: 0,
        status: 'active'
    },
    transformFormData: (data, isEditMode) => {
        const result = {
            nama_supplier: data.nama_supplier?.trim(),
            nama_pic: data.nama_pic?.trim(),
            telepon: data.telepon?.trim(),
            email: data.email?.trim() || null,
            alamat: data.alamat?.trim() || null,
            npwp: data.npwp?.trim() || null,
            bank_nama: data.bank_nama?.trim() || null,
            bank_rekening: data.bank_rekening?.trim() || null,
            bank_atas_nama: data.bank_atas_nama?.trim() || null,
            tempo_default: data.tempo_default || 0,
            status: data.status
        };
        if (!isEditMode) {
            result.kode_supplier = data.kode_supplier?.trim();
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
        const response = await suppliersApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.suppliers;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode', field: 'kode_supplier', width: 22 },
        { header: 'Nama Supplier', field: 'nama_supplier' },
        { header: 'PIC', field: 'nama_pic' },
        { header: 'Telepon', field: 'telepon', width: 28 },
        { header: 'Tempo', width: 16, align: 'center', accessor: (row) => `${row.tempo_default} hari` },
        { header: 'Status', width: 16, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Supplier',
        filename: 'daftar_supplier',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} supplier`
    });
}

// Export Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedStatus.value) params.status = selectedStatus.value;

        const response = await suppliersApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_supplier_${todayString()}.xlsx`);
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
async function saveSupplier() {
    submitted.value = true;

    if (!supplier.value.kode_supplier?.trim()) return;
    if (!supplier.value.nama_supplier?.trim()) return;
    if (!supplier.value.nama_pic?.trim()) return;
    if (!supplier.value.telepon?.trim()) return;
    if (!supplier.value.status) return;

    await saveItem();
}
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Supplier" icon="pi pi-plus" severity="primary" @click="openNew" />
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
                :value="suppliers"
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
                    <DataTableHeader v-model="searchQuery" title="Supplier" placeholder="Cari kode, nama, PIC, telepon..." search-width="w-80" @search="doSearch" @clear="clearSearch">
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
                        <i class="pi pi-truck text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data supplier</p>
                    </div>
                </template>

                <Column field="kode_supplier" header="Kode" sortable style="min-width: 120px"></Column>
                <Column field="nama_supplier" header="Nama Supplier" sortable style="min-width: 200px"></Column>
                <Column field="nama_pic" header="PIC" sortable style="min-width: 150px"></Column>
                <Column field="telepon" header="Telepon" sortable style="min-width: 130px"></Column>
                <Column field="tempo_default" header="Tempo (Hari)" sortable style="min-width: 120px">
                    <template #body="slotProps"> {{ slotProps.data.tempo_default }} hari </template>
                </Column>
                <Column field="status" header="Status" sortable style="min-width: 100px">
                    <template #body="slotProps">
                        <Tag :value="getStatusLabel(slotProps.data.status)" :severity="getStatusSeverity(slotProps.data.status)" />
                    </template>
                </Column>
                <Column :exportable="false" style="min-width: 220px" alignFrozen="right" frozen>
                    <template #body="slotProps">
                        <Button icon="pi pi-eye" outlined rounded class="mr-2" severity="info" @click="viewDetail(slotProps.data)" v-tooltip.top="'Lihat Detail'" aria-label="Lihat Detail" />
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editSupplier(slotProps.data)" v-tooltip.top="'Edit'" aria-label="Edit" />
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

        <!-- Supplier Dialog -->
        <Dialog v-model:visible="supplierDialog" :style="{ width: '700px' }" :header="isEdit ? 'Edit Supplier' : 'Tambah Supplier'" :modal="true" :closable="!saving">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block font-medium mb-2">
                        Kode Supplier <span class="text-red-500">*</span>
                        <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                    </label>
                    <InputText
                        v-model.trim="supplier.kode_supplier"
                        :invalid="submitted && !supplier.kode_supplier"
                        :disabled="isEdit"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan kode supplier"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !supplier.kode_supplier" class="text-red-500">Kode wajib diisi</small>
                    <small v-else-if="!isEdit" class="text-surface-500">Kode tidak dapat diubah setelah disimpan</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama Supplier <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="supplier.nama_supplier" :invalid="submitted && !supplier.nama_supplier" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan nama supplier" autocomplete="off" />
                    <small v-if="submitted && !supplier.nama_supplier" class="text-red-500">Nama wajib diisi</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama PIC <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="supplier.nama_pic" :invalid="submitted && !supplier.nama_pic" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Person In Charge" autocomplete="off" />
                    <small v-if="submitted && !supplier.nama_pic" class="text-red-500">Nama PIC wajib diisi</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Telepon <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="supplier.telepon" :invalid="submitted && !supplier.telepon" fluid placeholder="Nomor telepon" autocomplete="off" />
                    <small v-if="submitted && !supplier.telepon" class="text-red-500">Telepon wajib diisi</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Email</label>
                    <InputText v-model.trim="supplier.email" fluid placeholder="Email (opsional)" autocomplete="off" />
                </div>

                <div>
                    <label class="block font-medium mb-2">NPWP</label>
                    <InputText v-model.trim="supplier.npwp" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Nomor NPWP (opsional)" autocomplete="off" />
                </div>

                <div class="col-span-2">
                    <label class="block font-medium mb-2">Alamat</label>
                    <Textarea v-model="supplier.alamat" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid rows="2" placeholder="Alamat lengkap (opsional)" autoResize />
                </div>

                <!-- Section: Informasi Bank -->
                <div class="col-span-2 border-t pt-4 mt-2">
                    <h5 class="text-surface-600 font-medium mb-3">Informasi Bank</h5>
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama Bank</label>
                    <InputText v-model.trim="supplier.bank_nama" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Nama bank (opsional)" autocomplete="off" />
                </div>

                <div>
                    <label class="block font-medium mb-2">Nomor Rekening</label>
                    <InputText v-model.trim="supplier.bank_rekening" fluid placeholder="Nomor rekening (opsional)" autocomplete="off" />
                </div>

                <div>
                    <label class="block font-medium mb-2">Atas Nama</label>
                    <InputText v-model.trim="supplier.bank_atas_nama" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Nama pemilik rekening (opsional)" autocomplete="off" />
                </div>

                <div>
                    <label class="block font-medium mb-2">Tempo Default (Hari)</label>
                    <InputNumber v-select-on-focus v-model="supplier.tempo_default" fluid :min="0" :max="365" placeholder="0" suffix=" hari" />
                    <small class="text-surface-500">Jangka waktu pembayaran default</small>
                </div>

                <div class="col-span-2 border-t pt-4 mt-2">
                    <label class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="supplier.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="submitted && !supplier.status"
                        class="w-48"
                        filter
                    />
                    <small v-if="submitted && !supplier.status" class="text-red-500">Status wajib dipilih</small>
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveSupplier" :loading="saving" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Supplier"
            width="600px"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <div class="flex flex-col gap-4">
                    <!-- Informasi Dasar -->
                    <div class="grid grid-cols-2 gap-4">
                        <DetailItem label="Kode Supplier" :value="detailData.kode_supplier" />
                        <DetailItem label="Nama Supplier" :value="detailData.nama_supplier" />
                        <DetailItem label="Nama PIC" :value="detailData.nama_pic" />
                        <DetailItem label="Telepon" :value="detailData.telepon" />
                        <DetailItem label="Email" :value="detailData.email" />
                        <DetailItem label="NPWP" :value="detailData.npwp" />
                    </div>
                    <DetailItem label="Alamat" :value="detailData.alamat" />

                    <!-- Informasi Bank -->
                    <Divider />
                    <h4 class="text-sm font-semibold text-surface-600 dark:text-surface-400 mb-2 flex items-center gap-2">
                        <i class="pi pi-building"></i>
                        Informasi Bank
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <DetailItem label="Nama Bank" :value="detailData.bank_nama" />
                        <DetailItem label="No. Rekening" :value="detailData.bank_rekening" />
                        <DetailItem label="Atas Nama" :value="detailData.bank_atas_nama" />
                    </div>

                    <!-- Pengaturan -->
                    <Divider />
                    <h4 class="text-sm font-semibold text-surface-600 dark:text-surface-400 mb-2 flex items-center gap-2">
                        <i class="pi pi-cog"></i>
                        Pengaturan
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <DetailItem label="Tempo Default" :value="detailData.tempo_default ? `${detailData.tempo_default} hari` : '-'" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                    </div>
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

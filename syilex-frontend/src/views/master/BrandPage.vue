<script setup>
import { ref, computed } from 'vue';
import { brandsApi } from '@/api';
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
const canCreate = computed(() => authStore.can('brand.create'));
const canUpdate = computed(() => authStore.can('brand.update'));
const canDelete = computed(() => authStore.can('brand.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const { exporting, exportListPdf } = useExportPdf();
const exportingExcel = ref(false);

// Initialize CRUD composable
const {
    // State
    dt,
    items: brands,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
    statusOptions,

    // Dialog states
    itemDialog: brandDialog,
    detailDialog,

    // Form states
    submitted,
    saving,
    loadingDetail,
    item: brand,
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
    editItem: editBrand,
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
} = useMasterCrud(brandsApi, {
    entityName: 'brand',
    dataKey: 'brands',
    emptyForm: {
        kode_brand: '',
        nama_brand: '',
        status: 'active'
    },
    transformFormData: (data, isEditMode) => {
        const result = {
            nama_brand: data.nama_brand?.trim(),
            status: data.status
        };
        // kode_brand only for create
        if (!isEditMode) {
            result.kode_brand = data.kode_brand?.trim();
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
        const response = await brandsApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.brands;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode Brand', field: 'kode_brand' },
        { header: 'Nama Brand', field: 'nama_brand' },
        { header: 'Status', width: 20, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Brand',
        filename: 'daftar_brand',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} brand`
    });
}

// Export Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedStatus.value) params.status = selectedStatus.value;

        const response = await brandsApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_brand_${todayString()}.xlsx`);
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
async function saveBrand() {
    submitted.value = true;

    // Validate required fields
    if (!brand.value.kode_brand?.trim()) return;
    if (!brand.value.nama_brand?.trim()) return;
    if (!brand.value.status) return;

    await saveItem();
}
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Brand" icon="pi pi-plus" severity="primary" @click="openNew" />
                </template>

                <template #end>
                    <div class="flex gap-2">
                        <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Filter Status" class="w-40" filter filterPlaceholder="Cari..." @change="onFilter" />
                        <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                    </div>
                </template>
            </Toolbar>

            <DataTable
                ref="dt"
                :value="brands"
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
                    <DataTableHeader v-model="searchQuery" title="Brand Produk" placeholder="Cari kode, nama..." @search="doSearch" @clear="clearSearch">
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
                        <i class="pi pi-tag text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data brand</p>
                    </div>
                </template>

                <Column field="kode_brand" header="Kode" sortable style="min-width: 120px"></Column>
                <Column field="nama_brand" header="Nama Brand" sortable style="min-width: 200px"></Column>
                <Column field="status" header="Status" sortable style="min-width: 100px">
                    <template #body="slotProps">
                        <Tag :value="getStatusLabel(slotProps.data.status)" :severity="getStatusSeverity(slotProps.data.status)" />
                    </template>
                </Column>
                <Column :exportable="false" style="min-width: 220px" alignFrozen="right" frozen>
                    <template #body="slotProps">
                        <Button icon="pi pi-eye" outlined rounded class="mr-2" severity="info" @click="viewDetail(slotProps.data)" v-tooltip.top="'Lihat Detail'" />
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editBrand(slotProps.data)" v-tooltip.top="'Edit'" />
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
                        <Button v-if="canDelete" icon="pi pi-trash" outlined rounded severity="danger" @click="confirmDelete(slotProps.data)" v-tooltip.top="'Hapus'" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- Brand Dialog -->
        <Dialog v-model:visible="brandDialog" :style="{ width: '500px' }" :header="isEdit ? 'Edit Brand' : 'Tambah Brand'" :modal="true" :closable="!saving">
            <div class="flex flex-col gap-4">
                <!-- Kode Brand -->
                <div>
                    <label class="block font-medium mb-2">
                        Kode Brand <span class="text-red-500">*</span>
                        <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                    </label>
                    <InputText
                        v-model.trim="brand.kode_brand"
                        :invalid="submitted && !brand.kode_brand"
                        :disabled="isEdit"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan kode brand"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !brand.kode_brand" class="text-red-500">Kode wajib diisi</small>
                    <small v-else-if="!isEdit" class="text-surface-500">Kode tidak dapat diubah setelah disimpan</small>
                </div>

                <!-- Nama Brand -->
                <div>
                    <label class="block font-medium mb-2">Nama Brand <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="brand.nama_brand" :invalid="submitted && !brand.nama_brand" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan nama brand" autocomplete="off" />
                    <small v-if="submitted && !brand.nama_brand" class="text-red-500">Nama wajib diisi</small>
                </div>

                <!-- Status -->
                <div>
                    <label for="status" class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        id="status"
                        v-model="brand.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="submitted && !brand.status"
                        fluid
                        filter
                        filterPlaceholder="Cari..."
                    />
                    <small v-if="submitted && !brand.status" class="text-red-500">Status wajib dipilih</small>
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveBrand" :loading="saving" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Brand"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Kode Brand" :value="detailData.kode_brand" />
                    <DetailItem label="Nama Brand" :value="detailData.nama_brand" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

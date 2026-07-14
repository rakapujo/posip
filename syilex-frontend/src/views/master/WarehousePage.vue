<script setup>
import { ref, computed } from 'vue';
import { warehousesApi, inventoryStocksApi } from '@/api';
import { useRouter } from 'vue-router';
import { useMasterCrud } from '@/composables/useMasterCrud';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const notify = useNotification();
const { exporting, exportListPdf } = useExportPdf();
const router = useRouter();
const authStore = useAuthStore();
const { shouldUppercase, formatCurrency, formatQty, todayString } = useFormatters();
const exportingExcel = ref(false);

// Permissions
const canCreate = computed(() => authStore.can('warehouse.create'));
const canUpdate = computed(() => authStore.can('warehouse.update'));
const canDelete = computed(() => authStore.can('warehouse.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));

// Custom state for stock summary
const stockSummary = ref(null);

// Saleable filter options (custom, not in composable)
const saleableOptions = ref([
    { label: 'Semua Tipe', value: null },
    { label: 'Saleable (POS)', value: true },
    { label: 'Non-Saleable', value: false }
]);

// Initialize CRUD composable
const {
    // State
    dt,
    items: warehouses,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
    statusOptions,
    additionalFilters,

    // Dialog states
    itemDialog: warehouseDialog,
    detailDialog,

    // Form states
    submitted,
    saving,
    loadingDetail,
    item: warehouse,
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
    editItem: editWarehouse,

    // CRUD operations
    saveItem,
    confirmToggleStatus,
    confirmDelete,

    // Status helpers
    getStatusSeverity,
    getStatusLabel,
    getToggleLabel,
    getToggleSeverity
} = useMasterCrud(warehousesApi, {
    entityName: 'warehouse',
    dataKey: 'warehouses',
    emptyForm: {
        kode_warehouse: '',
        nama_warehouse: '',
        alamat: '',
        pic_name: '',
        pic_phone: '',
        is_saleable: true,
        status: 'active'
    },
    filters: [{ key: 'is_saleable', default: null }],
    transformFormData: (data, isEditMode) => {
        const result = {
            nama_warehouse: data.nama_warehouse?.trim(),
            alamat: data.alamat?.trim() || null,
            pic_name: data.pic_name?.trim() || null,
            pic_phone: data.pic_phone?.trim() || null,
            is_saleable: data.is_saleable,
            status: data.status
        };
        if (!isEditMode) {
            result.kode_warehouse = data.kode_warehouse?.trim();
        }
        return result;
    }
});

// Custom saleable helpers
function getSaleableSeverity(isSaleable) {
    return isSaleable ? 'info' : 'warn';
}

function getSaleableLabel(isSaleable) {
    return isSaleable ? 'Saleable' : 'Non-Saleable';
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
    if (additionalFilters.value.is_saleable !== null && additionalFilters.value.is_saleable !== undefined) {
        params.is_saleable = additionalFilters.value.is_saleable;
        filterParts.push(`Tipe: ${additionalFilters.value.is_saleable ? 'Saleable' : 'Non-Saleable'}`);
    }
    if (searchQuery.value) filterParts.push(`Search: "${searchQuery.value}"`);

    let allData;
    try {
        const response = await warehousesApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.warehouses;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode', field: 'kode_warehouse', width: 22 },
        { header: 'Nama Warehouse', field: 'nama_warehouse' },
        { header: 'Alamat', accessor: (row) => row.alamat || '-' },
        { header: 'PIC', accessor: (row) => (row.pic_name ? `${row.pic_name}${row.pic_phone ? ` (${row.pic_phone})` : ''}` : '-') },
        { header: 'Tipe', width: 22, align: 'center', accessor: (row) => getSaleableLabel(row.is_saleable) },
        { header: 'Status', width: 16, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Warehouse',
        filename: 'daftar_warehouse',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} warehouse`
    });
}

// Export Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedStatus.value) params.status = selectedStatus.value;
        if (additionalFilters.value.is_saleable !== null && additionalFilters.value.is_saleable !== undefined) {
            params.is_saleable = additionalFilters.value.is_saleable;
        }

        const response = await warehousesApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_warehouse_${todayString()}.xlsx`);
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
async function saveWarehouse() {
    submitted.value = true;

    if (!warehouse.value.kode_warehouse?.trim()) return;
    if (!warehouse.value.nama_warehouse?.trim()) return;
    if (warehouse.value.is_saleable === null || warehouse.value.is_saleable === undefined) return;
    if (!warehouse.value.status) return;

    await saveItem();
}

// Custom viewDetail dengan stock summary
async function viewDetail(data) {
    detailDialog.value = true;
    loadingDetail.value = true;
    detailData.value = {};
    stockSummary.value = null;

    try {
        const warehouseRes = await warehousesApi.get(data.ulid);

        if (warehouseRes.data.success) {
            detailData.value = warehouseRes.data.data.warehouse;

            // Fetch stock summary if user can view HPP
            if (canViewHpp.value && detailData.value.id) {
                const stockRes = await inventoryStocksApi.getSummary({ warehouse_id: detailData.value.id });
                if (stockRes?.data?.success) {
                    stockSummary.value = stockRes.data.data.summary;
                }
            }
        }
    } catch (error) {
        notify.loadDetailError('warehouse');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

// Navigate to Stock page with warehouse filter
function viewWarehouseStock(warehouseId) {
    detailDialog.value = false;
    router.push({
        name: 'inventory-stok',
        query: { warehouse_id: warehouseId }
    });
}
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Warehouse" icon="pi pi-plus" severity="primary" @click="openNew" />
                </template>

                <template #end>
                    <div class="flex gap-2">
                        <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Filter Status" class="w-40" filter @change="onFilter" />
                        <Select v-model="additionalFilters.is_saleable" :options="saleableOptions" optionLabel="label" optionValue="value" placeholder="Filter Tipe" class="w-44" filter @change="onFilter" />
                        <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                    </div>
                </template>
            </Toolbar>

            <DataTable
                ref="dt"
                :value="warehouses"
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
                    <DataTableHeader v-model="searchQuery" title="Master Warehouse" placeholder="Cari kode, nama, alamat..." @search="doSearch" @clear="clearSearch">
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
                        <i class="pi pi-warehouse text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data warehouse</p>
                    </div>
                </template>

                <Column field="kode_warehouse" header="Kode" sortable style="min-width: 120px"></Column>
                <Column field="nama_warehouse" header="Nama Warehouse" sortable style="min-width: 180px"></Column>
                <Column field="alamat" header="Alamat" style="min-width: 200px">
                    <template #body="slotProps">
                        {{ slotProps.data.alamat || '-' }}
                    </template>
                </Column>
                <Column header="PIC" style="min-width: 150px">
                    <template #body="slotProps">
                        <div v-if="slotProps.data.pic_name">
                            <div>{{ slotProps.data.pic_name }}</div>
                            <small class="text-surface-500">{{ slotProps.data.pic_phone || '-' }}</small>
                        </div>
                        <span v-else>-</span>
                    </template>
                </Column>
                <Column header="Tipe" style="min-width: 120px">
                    <template #body="slotProps">
                        <Tag :value="getSaleableLabel(slotProps.data.is_saleable)" :severity="getSaleableSeverity(slotProps.data.is_saleable)" />
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
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editWarehouse(slotProps.data)" v-tooltip.top="'Edit'" aria-label="Edit" />
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

        <!-- Warehouse Dialog -->
        <Dialog v-model:visible="warehouseDialog" :style="{ width: '500px' }" :header="isEdit ? 'Edit Warehouse' : 'Tambah Warehouse'" :modal="true" :closable="!saving">
            <div class="flex flex-col gap-4">
                <div>
                    <label class="block font-medium mb-2">
                        Kode Warehouse <span class="text-red-500">*</span>
                        <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                    </label>
                    <InputText
                        v-model.trim="warehouse.kode_warehouse"
                        :invalid="submitted && !warehouse.kode_warehouse"
                        :disabled="isEdit"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan kode warehouse"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !warehouse.kode_warehouse" class="text-red-500">Kode wajib diisi</small>
                    <small v-else-if="!isEdit" class="text-surface-500">Kode tidak dapat diubah setelah disimpan</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama Warehouse <span class="text-red-500">*</span></label>
                    <InputText
                        v-model.trim="warehouse.nama_warehouse"
                        :invalid="submitted && !warehouse.nama_warehouse"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan nama warehouse"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !warehouse.nama_warehouse" class="text-red-500">Nama wajib diisi</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Alamat</label>
                    <Textarea v-model.trim="warehouse.alamat" rows="3" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan alamat warehouse" autoResize />
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama PIC</label>
                    <InputText v-model.trim="warehouse.pic_name" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan nama PIC" autocomplete="off" />
                </div>

                <div>
                    <label class="block font-medium mb-2">Telepon PIC</label>
                    <InputText v-model.trim="warehouse.pic_phone" fluid placeholder="Masukkan telepon PIC" autocomplete="off" />
                </div>

                <div>
                    <label class="block font-medium mb-2">Tipe Warehouse <span class="text-red-500">*</span></label>
                    <div class="flex gap-4">
                        <div class="flex items-center">
                            <RadioButton v-model="warehouse.is_saleable" inputId="saleable_yes" name="is_saleable" :value="true" />
                            <label for="saleable_yes" class="ml-2">Saleable (untuk POS)</label>
                        </div>
                        <div class="flex items-center">
                            <RadioButton v-model="warehouse.is_saleable" inputId="saleable_no" name="is_saleable" :value="false" />
                            <label for="saleable_no" class="ml-2">Non-Saleable (internal/BS)</label>
                        </div>
                    </div>
                    <small class="text-surface-500">Warehouse saleable dapat digunakan untuk transaksi POS</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="warehouse.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="submitted && !warehouse.status"
                        fluid
                        filter
                    />
                    <small v-if="submitted && !warehouse.status" class="text-red-500">Status wajib dipilih</small>
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveWarehouse" :loading="saving" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Warehouse"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Kode Warehouse" :value="detailData.kode_warehouse" />
                    <DetailItem label="Nama Warehouse" :value="detailData.nama_warehouse" />
                    <DetailItem label="Tipe" :value="getSaleableLabel(detailData.is_saleable)" type="badge" :badge-severity="getSaleableSeverity(detailData.is_saleable)" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                </div>

                <Divider />

                <h6 class="text-surface-600 font-medium mb-3">Alamat</h6>
                <DetailItem label="Alamat Lengkap" :value="detailData.alamat" />

                <Divider />

                <h6 class="text-surface-600 font-medium mb-3">Person In Charge (PIC)</h6>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Nama PIC" :value="detailData.pic_name" />
                    <DetailItem label="Telepon PIC" :value="detailData.pic_phone" />
                </div>

                <!-- Stock Summary (if can view HPP) -->
                <template v-if="canViewHpp && stockSummary">
                    <Divider />
                    <h6 class="text-surface-600 font-medium mb-3">Ringkasan Stok</h6>
                    <div class="grid grid-cols-2 gap-4">
                        <DetailItem label="Total Qty (Base Unit)" :value="formatQty(stockSummary.total_qty)" />
                        <div class="flex flex-col gap-1">
                            <span class="text-surface-500 text-sm">Total Nilai Inventory</span>
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ formatCurrency(stockSummary.total_value) }}</span>
                                <Button icon="pi pi-box" size="small" rounded outlined severity="info" @click="viewWarehouseStock(detailData.id)" v-tooltip.top="'Lihat Detail Stok'" aria-label="Lihat Detail Stok" />
                            </div>
                        </div>
                    </div>
                </template>
            </template>
        </DetailDialog>
    </div>
</template>

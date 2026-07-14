<script setup>
import { ref, computed, onMounted } from 'vue';
import { kategorisApi, tipesApi } from '@/api';
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
const canCreate = computed(() => authStore.can('kategori.create'));
const canUpdate = computed(() => authStore.can('kategori.update'));
const canDelete = computed(() => authStore.can('kategori.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const { exporting, exportListPdf } = useExportPdf();
const exportingExcel = ref(false);

// Tipes list for dropdown (manual - not in composable)
const tipesList = ref([]);
const tipesLoading = ref(false);

// Initialize CRUD composable with tipe_ulid filter
const {
    // State
    dt,
    items: kategoris,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
    statusOptions,
    additionalFilters,

    // Dialog states
    itemDialog: kategoriDialog,
    detailDialog,

    // Form states
    submitted,
    saving,
    loadingDetail,
    item: kategori,
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
    viewDetail,

    // CRUD operations
    saveItem,
    confirmToggleStatus,
    confirmDelete,

    // Status helpers
    getStatusSeverity,
    getStatusLabel,
    getToggleLabel,
    getToggleSeverity,

    // Data loading
    loadData
} = useMasterCrud(kategorisApi, {
    entityName: 'kategori',
    dataKey: 'kategoris',
    emptyForm: {
        tipe_ulid: '',
        kode_kategori: '',
        nama_kategori: '',
        status: 'active'
    },
    filters: [{ key: 'tipe_ulid', default: null }],
    transformFormData: (data, isEditMode) => {
        const result = {
            tipe_ulid: data.tipe_ulid,
            nama_kategori: data.nama_kategori?.trim(),
            status: data.status
        };
        if (!isEditMode) {
            result.kode_kategori = data.kode_kategori?.trim();
        }
        return result;
    },
    autoLoad: false // Manual load after tipesList loaded
});

// Load tipes list for dropdown
async function loadTipesList() {
    tipesLoading.value = true;
    try {
        const response = await tipesApi.getList();
        if (response.data.success) {
            tipesList.value = response.data.data.tipes.map((t) => ({
                label: `${t.kode_tipe} - ${t.nama_tipe}`,
                value: t.ulid
            }));
        }
    } catch (error) {
        notify.loadListError('tipe produk');
    } finally {
        tipesLoading.value = false;
    }
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
    if (additionalFilters.value.tipe_ulid) {
        params.tipe_ulid = additionalFilters.value.tipe_ulid;
        const tipe = tipesList.value.find((t) => t.value === additionalFilters.value.tipe_ulid);
        if (tipe) filterParts.push(`Tipe: ${tipe.label}`);
    }
    if (selectedStatus.value) {
        params.status = selectedStatus.value;
        filterParts.push(`Status: ${selectedStatus.value === 'active' ? 'Aktif' : 'Nonaktif'}`);
    }
    if (searchQuery.value) filterParts.push(`Search: "${searchQuery.value}"`);

    let allData;
    try {
        const response = await kategorisApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.kategoris;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode Kategori', field: 'kode_kategori' },
        { header: 'Nama Kategori', field: 'nama_kategori' },
        { header: 'Tipe', accessor: (row) => (row.tipe ? `[${row.tipe.kode_tipe}] ${row.tipe.nama_tipe}` : '-') },
        { header: 'Status', width: 20, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Kategori Produk',
        filename: 'daftar_kategori_produk',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} kategori`
    });
}

// Custom edit to map tipe object to tipe_ulid
function editKategori(kategoriData) {
    kategori.value = {
        ulid: kategoriData.ulid,
        tipe_ulid: kategoriData.tipe?.ulid || '',
        kode_kategori: kategoriData.kode_kategori,
        nama_kategori: kategoriData.nama_kategori,
        status: kategoriData.status
    };
    submitted.value = false;
    kategoriDialog.value = true;
}

// Export Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedStatus.value) params.status = selectedStatus.value;
        if (additionalFilters.value.tipe_ulid) params.tipe_ulid = additionalFilters.value.tipe_ulid;

        const response = await kategorisApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_kategori_${todayString()}.xlsx`);
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
async function saveKategori() {
    submitted.value = true;

    if (!kategori.value.tipe_ulid) return;
    if (!kategori.value.kode_kategori?.trim()) return;
    if (!kategori.value.nama_kategori?.trim()) return;
    if (!kategori.value.status) return;

    await saveItem();
}

// Mount - load both tipesList and data
onMounted(async () => {
    await Promise.all([loadTipesList(), loadData()]);
});
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Kategori" icon="pi pi-plus" severity="primary" @click="openNew" />
                </template>

                <template #end>
                    <div class="flex gap-2">
                        <Select v-model="additionalFilters.tipe_ulid" :options="[{ label: 'Semua Tipe', value: null }, ...tipesList]" optionLabel="label" optionValue="value" placeholder="Filter Tipe" class="w-48" filter @change="onFilter" />
                        <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Filter Status" class="w-40" filter @change="onFilter" />
                        <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                    </div>
                </template>
            </Toolbar>

            <DataTable
                ref="dt"
                :value="kategoris"
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
                    <DataTableHeader v-model="searchQuery" title="Kategori Produk" placeholder="Cari kode, nama..." @search="doSearch" @clear="clearSearch">
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
                        <i class="pi pi-folder text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data kategori produk</p>
                    </div>
                </template>

                <Column field="kode_kategori" header="Kode" sortable style="min-width: 120px"></Column>
                <Column field="nama_kategori" header="Nama Kategori" sortable style="min-width: 200px"></Column>
                <Column field="tipe_nama" header="Tipe" sortable style="min-width: 150px">
                    <template #body="slotProps">
                        {{ slotProps.data.tipe?.nama_tipe || '-' }}
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
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editKategori(slotProps.data)" v-tooltip.top="'Edit'" aria-label="Edit" />
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

        <!-- Kategori Dialog -->
        <Dialog v-model:visible="kategoriDialog" :style="{ width: '500px' }" :header="isEdit ? 'Edit Kategori Produk' : 'Tambah Kategori Produk'" :modal="true" :closable="!saving">
            <div class="flex flex-col gap-4">
                <div>
                    <label class="block font-medium mb-2">Tipe Produk <span class="text-red-500">*</span></label>
                    <Select v-model="kategori.tipe_ulid" :options="tipesList" optionLabel="label" optionValue="value" :invalid="submitted && !kategori.tipe_ulid" :loading="tipesLoading" fluid filter placeholder="Pilih tipe produk" />
                    <small v-if="submitted && !kategori.tipe_ulid" class="text-red-500">Tipe wajib dipilih</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">
                        Kode Kategori <span class="text-red-500">*</span>
                        <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                    </label>
                    <InputText
                        v-model.trim="kategori.kode_kategori"
                        :invalid="submitted && !kategori.kode_kategori"
                        :disabled="isEdit"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan kode kategori"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !kategori.kode_kategori" class="text-red-500">Kode wajib diisi</small>
                    <small v-else-if="!isEdit" class="text-surface-500">Kode tidak dapat diubah setelah disimpan</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama Kategori <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="kategori.nama_kategori" :invalid="submitted && !kategori.nama_kategori" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan nama kategori" autocomplete="off" />
                    <small v-if="submitted && !kategori.nama_kategori" class="text-red-500">Nama wajib diisi</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="kategori.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="submitted && !kategori.status"
                        fluid
                        filter
                    />
                    <small v-if="submitted && !kategori.status" class="text-red-500">Status wajib dipilih</small>
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveKategori" :loading="saving" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Kategori Produk"
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
                    <DetailItem label="Tipe Produk" :value="detailData.tipe ? `${detailData.tipe.kode_tipe} - ${detailData.tipe.nama_tipe}` : '-'" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

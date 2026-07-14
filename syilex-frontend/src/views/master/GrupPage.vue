<script setup>
import { ref, computed, onMounted } from 'vue';
import { grupsApi, kategorisApi } from '@/api';
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
const canCreate = computed(() => authStore.can('grup.create'));
const canUpdate = computed(() => authStore.can('grup.update'));
const canDelete = computed(() => authStore.can('grup.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const { exporting, exportListPdf } = useExportPdf();
const exportingExcel = ref(false);

// Kategoris list for dropdown (manual - not in composable)
const kategorisList = ref([]);
const kategorisLoading = ref(false);

// Initialize CRUD composable with kategori_ulid filter
const {
    // State
    dt,
    items: grups,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
    statusOptions,
    additionalFilters,

    // Dialog states
    itemDialog: grupDialog,
    detailDialog,

    // Form states
    submitted,
    saving,
    loadingDetail,
    item: grup,
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
} = useMasterCrud(grupsApi, {
    entityName: 'grup',
    dataKey: 'grups',
    emptyForm: {
        kategori_ulid: '',
        kode_grup: '',
        nama_grup: '',
        status: 'active'
    },
    filters: [{ key: 'kategori_ulid', default: null }],
    transformFormData: (data, isEditMode) => {
        const result = {
            kategori_ulid: data.kategori_ulid,
            nama_grup: data.nama_grup?.trim(),
            status: data.status
        };
        if (!isEditMode) {
            result.kode_grup = data.kode_grup?.trim();
        }
        return result;
    },
    autoLoad: false // Manual load after kategorisList loaded
});

// Load kategoris list for dropdown
async function loadKategorisList() {
    kategorisLoading.value = true;
    try {
        const response = await kategorisApi.getList();
        if (response.data.success) {
            kategorisList.value = response.data.data.kategoris.map((k) => ({
                label: `${k.kode_kategori} - ${k.nama_kategori}`,
                sublabel: k.tipe?.nama_tipe || '',
                value: k.ulid
            }));
        }
    } catch (error) {
        notify.loadListError('kategori produk');
    } finally {
        kategorisLoading.value = false;
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
    if (additionalFilters.value.kategori_ulid) {
        params.kategori_ulid = additionalFilters.value.kategori_ulid;
        const kat = kategorisList.value.find((k) => k.value === additionalFilters.value.kategori_ulid);
        if (kat) filterParts.push(`Kategori: ${kat.label}`);
    }
    if (selectedStatus.value) {
        params.status = selectedStatus.value;
        filterParts.push(`Status: ${selectedStatus.value === 'active' ? 'Aktif' : 'Nonaktif'}`);
    }
    if (searchQuery.value) filterParts.push(`Search: "${searchQuery.value}"`);

    let allData;
    try {
        const response = await grupsApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.grups;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode Grup', field: 'kode_grup' },
        { header: 'Nama Grup', field: 'nama_grup' },
        { header: 'Kategori', accessor: (row) => (row.kategori ? `[${row.kategori.kode_kategori}] ${row.kategori.nama_kategori}` : '-') },
        { header: 'Tipe', accessor: (row) => (row.kategori?.tipe ? `[${row.kategori.tipe.kode_tipe}] ${row.kategori.tipe.nama_tipe}` : '-') },
        { header: 'Status', width: 20, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Grup Produk',
        filename: 'daftar_grup_produk',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} grup`
    });
}

// Custom edit to map kategori object to kategori_ulid
function editGrup(grupData) {
    grup.value = {
        ulid: grupData.ulid,
        kategori_ulid: grupData.kategori?.ulid || '',
        kode_grup: grupData.kode_grup,
        nama_grup: grupData.nama_grup,
        status: grupData.status
    };
    submitted.value = false;
    grupDialog.value = true;
}

// Export Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedStatus.value) params.status = selectedStatus.value;
        if (additionalFilters.value.kategori_ulid) params.kategori_ulid = additionalFilters.value.kategori_ulid;

        const response = await grupsApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_grup_${todayString()}.xlsx`);
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
async function saveGrup() {
    submitted.value = true;

    if (!grup.value.kategori_ulid) return;
    if (!grup.value.kode_grup?.trim()) return;
    if (!grup.value.nama_grup?.trim()) return;
    if (!grup.value.status) return;

    await saveItem();
}

// Mount - load both kategorisList and data
onMounted(async () => {
    await Promise.all([loadKategorisList(), loadData()]);
});
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Grup" icon="pi pi-plus" severity="primary" @click="openNew" />
                </template>

                <template #end>
                    <div class="flex gap-2">
                        <Select
                            v-model="additionalFilters.kategori_ulid"
                            :options="[{ label: 'Semua Kategori', value: null }, ...kategorisList]"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Filter Kategori"
                            class="w-52"
                            filter
                            @change="onFilter"
                        />
                        <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Filter Status" class="w-40" filter @change="onFilter" />
                        <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                    </div>
                </template>
            </Toolbar>

            <DataTable
                ref="dt"
                :value="grups"
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
                    <DataTableHeader v-model="searchQuery" title="Grup Produk" placeholder="Cari kode, nama..." @search="doSearch" @clear="clearSearch">
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
                        <i class="pi pi-th-large text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data grup produk</p>
                    </div>
                </template>

                <Column field="kode_grup" header="Kode" sortable style="min-width: 120px"></Column>
                <Column field="nama_grup" header="Nama Grup" sortable style="min-width: 200px"></Column>
                <Column field="kategori_nama" header="Kategori" sortable style="min-width: 150px">
                    <template #body="slotProps">
                        {{ slotProps.data.kategori?.nama_kategori || '-' }}
                    </template>
                </Column>
                <Column field="tipe_nama" header="Tipe" sortable style="min-width: 150px">
                    <template #body="slotProps">
                        {{ slotProps.data.kategori?.tipe?.nama_tipe || '-' }}
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
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editGrup(slotProps.data)" v-tooltip.top="'Edit'" aria-label="Edit" />
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

        <!-- Grup Dialog -->
        <Dialog v-model:visible="grupDialog" :style="{ width: '500px' }" :header="isEdit ? 'Edit Grup Produk' : 'Tambah Grup Produk'" :modal="true" :closable="!saving">
            <div class="flex flex-col gap-4">
                <div>
                    <label class="block font-medium mb-2">Kategori Produk <span class="text-red-500">*</span></label>
                    <Select v-model="grup.kategori_ulid" :options="kategorisList" optionLabel="label" optionValue="value" :invalid="submitted && !grup.kategori_ulid" :loading="kategorisLoading" fluid filter placeholder="Pilih kategori produk">
                        <template #option="slotProps">
                            <div>
                                <div>{{ slotProps.option.label }}</div>
                                <small v-if="slotProps.option.sublabel" class="text-surface-500"> Tipe: {{ slotProps.option.sublabel }} </small>
                            </div>
                        </template>
                    </Select>
                    <small v-if="submitted && !grup.kategori_ulid" class="text-red-500">Kategori wajib dipilih</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">
                        Kode Grup <span class="text-red-500">*</span>
                        <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                    </label>
                    <InputText v-model.trim="grup.kode_grup" :invalid="submitted && !grup.kode_grup" :disabled="isEdit" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan kode grup" autocomplete="off" />
                    <small v-if="submitted && !grup.kode_grup" class="text-red-500">Kode wajib diisi</small>
                    <small v-else-if="!isEdit" class="text-surface-500">Kode tidak dapat diubah setelah disimpan</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama Grup <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="grup.nama_grup" :invalid="submitted && !grup.nama_grup" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan nama grup" autocomplete="off" />
                    <small v-if="submitted && !grup.nama_grup" class="text-red-500">Nama wajib diisi</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="grup.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="submitted && !grup.status"
                        fluid
                        filter
                    />
                    <small v-if="submitted && !grup.status" class="text-red-500">Status wajib dipilih</small>
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveGrup" :loading="saving" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Grup Produk"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Kode Grup" :value="detailData.kode_grup" />
                    <DetailItem label="Nama Grup" :value="detailData.nama_grup" />
                    <DetailItem label="Kategori Produk" :value="detailData.kategori ? `${detailData.kategori.kode_kategori} - ${detailData.kategori.nama_kategori}` : '-'" />
                    <DetailItem label="Tipe Produk" :value="detailData.kategori?.tipe ? `${detailData.kategori.tipe.kode_tipe} - ${detailData.kategori.tipe.nama_tipe}` : '-'" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

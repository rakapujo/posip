<script setup>
import { ref, computed } from 'vue';
import { tipeCustomersApi } from '@/api';
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
const canCreate = computed(() => authStore.can('tipe-customer.create'));
const canUpdate = computed(() => authStore.can('tipe-customer.update'));
const canDelete = computed(() => authStore.can('tipe-customer.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const { exporting, exportListPdf } = useExportPdf();
const exportingExcel = ref(false);

// Initialize CRUD composable
const {
    // State
    dt,
    items: tipeCustomers,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
    statusOptions,

    // Dialog states
    itemDialog: tipeCustomerDialog,
    detailDialog,

    // Form states
    submitted,
    saving,
    loadingDetail,
    item: tipeCustomer,
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
    editItem: editTipeCustomer,
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
} = useMasterCrud(tipeCustomersApi, {
    entityName: 'tipe_customer',
    dataKey: 'tipe_customers',
    emptyForm: {
        kode_tipe: '',
        nama_tipe: '',
        diskon_tipe: 'none',
        diskon_nilai: 0,
        keterangan: '',
        status: 'active'
    },
    transformFormData: (data, isEditMode) => {
        const result = {
            nama_tipe: data.nama_tipe?.trim(),
            diskon_tipe: data.diskon_tipe || 'none',
            diskon_nilai: data.diskon_tipe === 'none' ? 0 : Number(data.diskon_nilai) || 0,
            keterangan: data.keterangan?.trim() || null,
            status: data.status
        };
        if (!isEditMode) {
            result.kode_tipe = data.kode_tipe?.trim();
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
        const response = await tipeCustomersApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.tipe_customers;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode Tipe', field: 'kode_tipe' },
        { header: 'Nama Tipe', field: 'nama_tipe' },
        { header: 'Keterangan', accessor: (row) => row.keterangan || '-' },
        { header: 'Status', width: 20, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Tipe Customer',
        filename: 'daftar_tipe_customer',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} tipe customer`
    });
}

// Export Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedStatus.value) params.status = selectedStatus.value;

        const response = await tipeCustomersApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_tipe_customer_${todayString()}.xlsx`);
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
async function saveTipeCustomer() {
    submitted.value = true;

    if (!tipeCustomer.value.kode_tipe?.trim()) return;
    if (!tipeCustomer.value.nama_tipe?.trim()) return;
    if (!tipeCustomer.value.status) return;

    await saveItem();
}
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Tipe Customer" icon="pi pi-plus" severity="primary" @click="openNew" />
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
                :value="tipeCustomers"
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
                    <DataTableHeader v-model="searchQuery" title="Tipe Customer" placeholder="Cari kode, nama..." @search="doSearch" @clear="clearSearch">
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
                        <p class="text-surface-500">Tidak ada data tipe customer</p>
                    </div>
                </template>

                <Column field="kode_tipe" header="Kode" sortable style="min-width: 120px"></Column>
                <Column field="nama_tipe" header="Nama Tipe" sortable style="min-width: 200px"></Column>
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
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editTipeCustomer(slotProps.data)" v-tooltip.top="'Edit'" aria-label="Edit" />
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

        <!-- Tipe Customer Dialog -->
        <Dialog v-model:visible="tipeCustomerDialog" :style="{ width: '500px' }" :header="isEdit ? 'Edit Tipe Customer' : 'Tambah Tipe Customer'" :modal="true" :closable="!saving">
            <div class="flex flex-col gap-4">
                <div>
                    <label class="block font-medium mb-2">
                        Kode Tipe <span class="text-red-500">*</span>
                        <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                    </label>
                    <InputText
                        v-model.trim="tipeCustomer.kode_tipe"
                        :invalid="submitted && !tipeCustomer.kode_tipe"
                        :disabled="isEdit"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan kode tipe"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !tipeCustomer.kode_tipe" class="text-red-500">Kode wajib diisi</small>
                    <small v-else-if="!isEdit" class="text-surface-500">Kode tidak dapat diubah setelah disimpan</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama Tipe <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="tipeCustomer.nama_tipe" :invalid="submitted && !tipeCustomer.nama_tipe" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan nama tipe" autocomplete="off" />
                    <small v-if="submitted && !tipeCustomer.nama_tipe" class="text-red-500">Nama wajib diisi</small>
                </div>

                <div v-if="authStore.can('customer-discount.manage')">
                    <label class="block font-medium mb-2">Diskon Otomatis</label>
                    <div class="flex gap-2 items-end">
                        <Select
                            v-model="tipeCustomer.diskon_tipe"
                            :options="[
                                { label: 'Tidak Ada', value: 'none' },
                                { label: 'Persen (%)', value: 'percent' },
                                { label: 'Nominal (Rp)', value: 'nominal' }
                            ]"
                            optionLabel="label"
                            optionValue="value"
                            class="w-40"
                        />
                        <InputNumber v-if="tipeCustomer.diskon_tipe !== 'none'" v-model="tipeCustomer.diskon_nilai" :min="0" :max="tipeCustomer.diskon_tipe === 'percent' ? 100 : 999999999" fluid />
                        <span v-if="tipeCustomer.diskon_tipe === 'percent'" class="text-sm text-surface-500 mb-2">%</span>
                    </div>
                    <small class="text-surface-400">Diskon otomatis di POS untuk customer bertipe ini (slot nota 1)</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Keterangan</label>
                    <Textarea v-model="tipeCustomer.keterangan" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid rows="3" placeholder="Keterangan (opsional)" autoResize />
                </div>

                <div>
                    <label class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="tipeCustomer.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="submitted && !tipeCustomer.status"
                        fluid
                        filter
                    />
                    <small v-if="submitted && !tipeCustomer.status" class="text-red-500">Status wajib dipilih</small>
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveTipeCustomer" :loading="saving" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Tipe Customer"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Kode Tipe" :value="detailData.kode_tipe" />
                    <DetailItem label="Nama Tipe" :value="detailData.nama_tipe" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                </div>
                <div class="mt-4">
                    <DetailItem label="Keterangan" :value="detailData.keterangan" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

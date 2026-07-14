<script setup>
import { ref, computed, onMounted } from 'vue';
import { adjustmentsApi, warehousesApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { useExportPdf } from '@/composables/useExportPdf';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const { formatQty, formatDateTime, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportDocumentPdf } = useExportPdf();

// Permissions
const canCreate = computed(() => authStore.can('adjustment.create'));
const canUpdate = computed(() => authStore.can('adjustment.update'));
const canDeletePerm = computed(() => authStore.can('adjustment.delete'));
const canApprove = computed(() => authStore.can('adjustment.approve'));

// Warehouses for filter
const warehouses = ref([]);

// Initialize composable
const {
    items: adjustments,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
    statusOptions,
    startDate,
    endDate,
    additionalFilters,
    detailDialog,
    detailData,
    loadingDetail,
    processingApprove,
    loadData,
    onPage,
    onSort,
    doSearch,
    clearSearch,
    onFilter,
    resetFilters,
    createNew,
    editItem,
    viewDetail,
    closeDetail,
    confirmDelete,
    confirmApprove,
    getStatusSeverity,
    getStatusLabel,
    canEdit,
    canDelete,
    canApprove: canApproveItem
} = useTransactionList(adjustmentsApi, {
    entityName: 'adjustment',
    dataKey: 'items',
    routePrefix: 'inventory-adjustment',
    filters: [{ key: 'warehouse_id', default: null }],
    autoLoad: false
});

// Detail table columns
const detailColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'product', header: 'Produk' },
    { field: 'jenis', header: 'Jenis', width: '120px' },
    { field: 'stok_sistem', header: 'Stok Sistem', align: 'right', width: '100px' },
    { field: 'qty', header: 'Qty', align: 'right', width: '80px' },
    { field: 'stok_akhir', header: 'Stok Akhir', align: 'right', width: '100px' },
    { field: 'notes', header: 'Notes' }
];

// Load warehouses for filter dropdown
async function loadWarehouses() {
    try {
        const response = await warehousesApi.getList();
        if (response.data.success) {
            warehouses.value = response.data.data.warehouses;
        }
    } catch (error) {
        console.error('Failed to load warehouses:', error);
    }
}

// Custom: Jenis severity helper
function getJenisSeverity(jenis) {
    return jenis === 'debit' ? 'success' : 'danger';
}

function getJenisLabel(jenis) {
    return jenis === 'debit' ? 'Debit (Masuk)' : 'Kredit (Keluar)';
}

// Export document PDF
async function exportDocPdf(item) {
    let data = item;
    if (!data.details?.[0]?.product) {
        try {
            const response = await adjustmentsApi.get(data.ulid);
            data = response.data.data.adjustment || response.data.data;
        } catch {
            return;
        }
    }

    const info = [
        { label: 'No. Dokumen', value: data.nomor_dokumen },
        { label: 'Tanggal', value: formatDateTime(data.tanggal) },
        { label: 'Gudang', value: data.warehouse?.nama_warehouse || '-' },
        { label: 'Status', value: getStatusLabel(data.status) }
    ];

    const columns = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'Kode Produk', width: 22, accessor: (row) => row.product?.kode_produk || '' },
        {
            header: 'Nama Produk',
            accessor: (row) => {
                let s = row.product?.nama_produk || '';
                if (row.serial_units?.length) {
                    s += '\n' + row.serial_units.map((u) => `• ${u.kode_internal || '-'} / SN ${u.serial_number || '-'}${u.fate ? ' (' + u.fate + ')' : ''}`).join('\n');
                }
                return s;
            }
        },
        { header: 'Jenis', width: 18, accessor: (row) => (row.jenis === 'debit' ? 'Debit (Masuk)' : 'Kredit (Keluar)') },
        { header: 'Stok Sistem', width: 16, align: 'right', accessor: (row) => formatQty(row.stok_sistem) },
        { header: 'Qty', width: 14, align: 'right', accessor: (row) => formatQty(row.qty) },
        { header: 'Stok Akhir', width: 16, align: 'right', accessor: (row) => formatQty(row.stok_akhir) },
        { header: 'Notes', accessor: (row) => row.notes || '-' }
    ];

    const audit = [];
    if (data.created_by?.name) {
        audit.push({ label: 'Dibuat oleh', value: data.created_by.name, date: formatDateTime(data.created_at) });
    }
    if (data.status === 'approved' && data.approved_by?.name) {
        audit.push({ label: 'Disetujui oleh', value: data.approved_by.name, date: formatDateTime(data.approved_at) });
    }

    exportDocumentPdf({
        title: 'Adjustment Stok',
        filename: data.nomor_dokumen || 'adjustment',
        info,
        table: { columns, data: data.details || [] },
        audit,
        notes: data.keterangan
    });
}

// Load data on mount
onMounted(async () => {
    await Promise.all([loadWarehouses(), loadData()]);
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Tambah Adjustment" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="additionalFilters.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Semua Gudang" class="w-40" filter showClear @change="onFilter" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-32" filter showClear @change="onFilter" />
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" />
                    </div>
                    <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                </div>
            </template>
        </Toolbar>

        <!-- DataTable -->
        <DataTable
            :value="adjustments"
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
                <DataTableHeader v-model="searchQuery" title="Daftar Adjustment Stok" placeholder="Cari nomor dokumen, keterangan..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data adjustment</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="Nomor" sortable style="min-width: 140px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <Column header="Gudang" style="min-width: 150px">
                <template #body="{ data }">
                    {{ data.warehouse?.nama_warehouse || '-' }}
                </template>
            </Column>

            <Column header="Item" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.details_count" severity="secondary" />
                </template>
            </Column>

            <Column header="Keterangan" style="min-width: 200px">
                <template #body="{ data }">
                    <span class="text-surface-500">{{ data.keterangan || '-' }}</span>
                </template>
            </Column>

            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                </template>
            </Column>

            <Column header="Aksi" style="min-width: 220px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <div class="flex gap-1">
                        <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" />
                        <Button icon="pi pi-file-pdf" severity="help" text rounded :loading="exporting" @click="exportDocPdf(data)" v-tooltip.top="'Export PDF'" />
                        <Button v-if="canUpdate && canEdit(data)" icon="pi pi-pencil" severity="warning" text rounded @click="editItem(data)" v-tooltip.top="'Edit'" />
                        <Button v-if="canDeletePerm && canDelete(data)" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'" />
                        <Button v-if="canApprove && canApproveItem(data)" icon="pi pi-check" severity="success" text rounded @click="confirmApprove(data)" v-tooltip.top="'Approve'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Adjustment"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="700px"
        >
            <template #content>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <DetailItem label="Nomor Dokumen" :value="detailData.nomor_dokumen" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                    <DetailItem label="Gudang" :value="detailData.warehouse?.nama_warehouse" />
                    <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal)" />
                </div>

                <div class="mb-4" v-if="detailData.keterangan">
                    <span class="text-surface-500 text-sm block mb-1">Keterangan</span>
                    <p class="m-0">{{ detailData.keterangan }}</p>
                </div>

                <!-- Detail Items Table -->
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
                                    ><span v-if="u.grade"> · {{ u.grade }}</span
                                    ><span v-if="u.fate" class="font-semibold" :class="u.fate === 'hilang' ? 'text-orange-500' : 'text-red-500'"> · {{ u.fate }}</span>
                                </div>
                            </div>
                        </template>
                        <template #jenis="{ item }">
                            <Tag :value="getJenisLabel(item.jenis)" :severity="getJenisSeverity(item.jenis)" />
                        </template>
                        <template #stok_sistem="{ item }">{{ formatQty(item.stok_sistem) }}</template>
                        <template #qty="{ item }">{{ formatQty(item.qty) }}</template>
                        <template #stok_akhir="{ item }">
                            <span :class="{ 'text-red-500': item.stok_akhir < 0, 'text-green-600': item.jenis === 'debit' }">
                                {{ formatQty(item.stok_akhir) }}
                            </span>
                        </template>
                        <template #notes="{ item }">
                            <span class="text-surface-500 text-sm">{{ item.notes || '-' }}</span>
                        </template>
                    </DetailTable>
                </div>

                <!-- Approved info -->
                <div class="mt-4 pt-4 border-t border-surface-200" v-if="detailData.status === 'approved' && detailData.approved_by">
                    <div class="flex items-center gap-2 text-surface-500 text-sm">
                        <i class="pi pi-check-circle text-green-500"></i>
                        <span>Disetujui: {{ formatDateTime(detailData.approved_at) }} oleh {{ detailData.approved_by?.name }}</span>
                    </div>
                </div>
            </template>

            <template #footer-extra>
                <div class="flex flex-wrap gap-2">
                    <Button label="Export PDF" icon="pi pi-file-pdf" severity="help" outlined :loading="exporting" @click="exportDocPdf(detailData)" />
                    <Button
                        v-if="canUpdate && canEdit(detailData)"
                        label="Edit"
                        icon="pi pi-pencil"
                        severity="warning"
                        @click="
                            editItem(detailData);
                            closeDetail();
                        "
                    />
                    <Button
                        v-if="canDeletePerm && canDelete(detailData)"
                        label="Hapus"
                        icon="pi pi-trash"
                        severity="danger"
                        @click="
                            confirmDelete(detailData);
                            closeDetail();
                        "
                    />
                    <Button v-if="canApprove && canApproveItem(detailData)" label="Approve" icon="pi pi-check" severity="success" :loading="processingApprove" @click="confirmApprove(detailData)" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

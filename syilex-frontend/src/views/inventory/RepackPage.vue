<script setup>
import { ref, computed, onMounted } from 'vue';
import { repacksApi, warehousesApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { useExportPdf } from '@/composables/useExportPdf';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const { formatQty, formatCurrency, formatDateTime, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportDocumentPdf } = useExportPdf();

// Permissions
const canCreate = computed(() => authStore.can('repack.create'));
const canUpdate = computed(() => authStore.can('repack.update'));
const canDelete = computed(() => authStore.can('repack.delete'));
const canApprove = computed(() => authStore.can('repack.approve'));
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));

// Warehouses for filter
const warehouses = ref([]);

// Tipe options for filter
const tipeOptions = [
    { label: 'Pecah', value: 'pecah' },
    { label: 'Gabung', value: 'gabung' }
];

// Initialize composable
const {
    items: repacks,
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
    canDelete: canDeleteItem,
    canApprove: canApproveItem
} = useTransactionList(repacksApi, {
    entityName: 'repack',
    dataKey: 'items',
    routePrefix: 'inventory-repack',
    filters: [
        { key: 'warehouse_id', default: null },
        { key: 'tipe', default: null }
    ],
    autoLoad: false
});

// Detail table columns (dynamic based on approval status AND canViewHpp permission)
const inputColumns = computed(() => {
    const cols = [
        { field: '#', header: '#', width: '40px' },
        { field: 'product', header: 'Produk' },
        { field: 'qty', header: 'Qty', align: 'right', width: '80px' }
    ];
    // HPP columns only shown if approved AND user has stok.view_hpp permission
    if (detailData.value?.status === 'approved' && canViewHpp.value) {
        cols.push({ field: 'hpp_unit', header: 'HPP/Unit', align: 'right', width: '120px' }, { field: 'total_hpp', header: 'Total HPP', align: 'right', width: '120px' });
    }
    return cols;
});

const outputColumns = computed(() => {
    const cols = [
        { field: '#', header: '#', width: '40px' },
        { field: 'product', header: 'Produk' },
        { field: 'qty', header: 'Qty', align: 'right', width: '80px' }
    ];
    // HPP columns only shown if approved AND user has stok.view_hpp permission
    if (detailData.value?.status === 'approved' && canViewHpp.value) {
        cols.push({ field: 'hpp_unit', header: 'HPP/Unit', align: 'right', width: '120px' }, { field: 'total_hpp', header: 'Total HPP', align: 'right', width: '120px' });
    }
    return cols;
});

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

// Custom: Tipe severity helper
function getTipeSeverity(tipe) {
    return tipe === 'pecah' ? 'info' : 'secondary';
}

function getTipeLabel(tipe) {
    return tipe === 'pecah' ? 'Pecah' : 'Gabung';
}

// Export document PDF
async function exportDocPdf(item) {
    let data = item;
    if (!data.inputs) {
        try {
            const response = await repacksApi.get(data.ulid);
            data = response.data.data.repack || response.data.data;
        } catch {
            return;
        }
    }

    const info = [
        { label: 'No. Dokumen', value: data.nomor_dokumen },
        { label: 'Tanggal', value: formatDateTime(data.tanggal) },
        { label: 'Gudang', value: data.warehouse?.nama_warehouse || '-' },
        { label: 'Tipe', value: getTipeLabel(data.tipe) },
        { label: 'Status', value: getStatusLabel(data.status) },
        { label: 'Biaya Repack', value: formatCurrency(data.biaya_repack) }
    ];

    // Build columns (with HPP if approved & has permission)
    const showHpp = data.status === 'approved' && canViewHpp.value;
    const baseCols = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'Kode Produk', width: 22, accessor: (row) => row.product?.kode_produk || '' },
        { header: 'Nama Produk', accessor: (row) => row.product?.nama_produk || '' },
        { header: 'Qty', width: 16, align: 'right', accessor: (row) => formatQty(row.qty) }
    ];
    if (showHpp) {
        baseCols.push({ header: 'HPP/Unit', width: 22, align: 'right', accessor: (row) => formatCurrency(row.cost_per_unit) }, { header: 'Total HPP', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_cost) });
    }

    const tbls = [
        { title: `Bahan Input (${data.inputs?.length || 0} item)`, columns: baseCols, data: data.inputs || [] },
        { title: `Hasil Output (${data.outputs?.length || 0} item)`, columns: baseCols, data: data.outputs || [] }
    ];

    // Summary (only if approved & has HPP permission)
    let summary = null;
    if (showHpp) {
        summary = [
            { label: 'Total HPP Input', value: formatCurrency(data.total_cost_input) },
            { label: 'Biaya Repack', value: formatCurrency(data.biaya_repack) },
            { separator: true },
            { label: 'Total HPP Output', value: formatCurrency(data.total_cost_output), bold: true }
        ];
    }

    const audit = [];
    if (data.created_by?.name) {
        audit.push({ label: 'Dibuat oleh', value: data.created_by.name, date: formatDateTime(data.created_at) });
    }
    if (data.status === 'approved' && data.approved_by?.name) {
        audit.push({ label: 'Disetujui oleh', value: data.approved_by.name, date: formatDateTime(data.approved_at) });
    }

    exportDocumentPdf({
        title: 'Repack',
        filename: data.nomor_dokumen || 'repack',
        info,
        tables: tbls,
        summary,
        audit,
        notes: data.notes
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
                <Button v-if="canCreate" label="Tambah Repack" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="additionalFilters.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Gudang" class="w-40" filter showClear @change="onFilter" />
                    <Select v-model="additionalFilters.tipe" :options="tipeOptions" optionLabel="label" optionValue="value" placeholder="Tipe" class="w-28" filter showClear @change="onFilter" />
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
            :value="repacks"
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
                <DataTableHeader v-model="searchQuery" title="Daftar Repack" placeholder="Cari nomor dokumen, notes..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data repack</p>
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

            <Column field="tipe" header="Tipe" sortable style="min-width: 90px">
                <template #body="{ data }">
                    <Tag :value="getTipeLabel(data.tipe)" :severity="getTipeSeverity(data.tipe)" />
                </template>
            </Column>

            <Column header="Gudang" style="min-width: 150px">
                <template #body="{ data }">
                    {{ data.warehouse?.nama_warehouse || '-' }}
                </template>
            </Column>

            <Column header="Input" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.inputs_count" severity="danger" />
                </template>
            </Column>

            <Column header="Output" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.outputs_count" severity="success" />
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
                        <Button v-if="canDelete && canDeleteItem(data)" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'" />
                        <Button v-if="canApprove && canApproveItem(data)" icon="pi pi-check" severity="success" text rounded @click="confirmApprove(data)" v-tooltip.top="'Approve'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Repack"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="800px"
        >
            <template #content>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <DetailItem label="Nomor Dokumen" :value="detailData.nomor_dokumen" />
                    <DetailItem label="Tipe" :value="getTipeLabel(detailData.tipe)" type="badge" :badge-severity="getTipeSeverity(detailData.tipe)" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                    <DetailItem label="Gudang" :value="detailData.warehouse?.nama_warehouse" />
                    <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal)" />
                    <DetailItem label="Biaya Repack" :value="detailData.biaya_repack" type="currency" />
                </div>

                <div class="mb-4" v-if="detailData.notes">
                    <span class="text-surface-500 text-sm block mb-1">Catatan</span>
                    <p class="m-0">{{ detailData.notes }}</p>
                </div>

                <!-- Input Items Table -->
                <div class="mt-4">
                    <h4 class="text-lg font-medium mb-3 text-red-600"><i class="pi pi-arrow-down mr-2"></i>Bahan Input ({{ detailData.inputs?.length || 0 }} item)</h4>
                    <DetailTable :data="detailData.inputs" :columns="inputColumns">
                        <template #product="{ item }">
                            <span class="font-medium">{{ item.product?.kode_produk }}</span>
                            <br />
                            <span class="text-surface-500 text-sm">{{ item.product?.nama_produk }}</span>
                        </template>
                        <template #qty="{ item }">{{ formatQty(item.qty) }}</template>
                        <template #hpp_unit="{ item }">{{ formatCurrency(item.cost_per_unit) }}</template>
                        <template #total_hpp="{ item }">{{ formatCurrency(item.total_cost) }}</template>
                    </DetailTable>
                </div>

                <!-- Output Items Table -->
                <div class="mt-4">
                    <h4 class="text-lg font-medium mb-3 text-green-600"><i class="pi pi-arrow-up mr-2"></i>Hasil Output ({{ detailData.outputs?.length || 0 }} item)</h4>
                    <DetailTable :data="detailData.outputs" :columns="outputColumns">
                        <template #product="{ item }">
                            <span class="font-medium">{{ item.product?.kode_produk }}</span>
                            <br />
                            <span class="text-surface-500 text-sm">{{ item.product?.nama_produk }}</span>
                        </template>
                        <template #qty="{ item }">{{ formatQty(item.qty) }}</template>
                        <template #hpp_unit="{ item }">{{ formatCurrency(item.cost_per_unit) }}</template>
                        <template #total_hpp="{ item }">{{ formatCurrency(item.total_cost) }}</template>
                    </DetailTable>
                </div>

                <!-- Cost Summary (only if approved AND has stok.view_hpp permission) -->
                <div class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700" v-if="detailData.status === 'approved' && canViewHpp">
                    <div class="grid grid-cols-3 gap-4">
                        <div class="text-center p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                            <div class="text-sm text-red-600 dark:text-red-400 mb-1">Total HPP Input</div>
                            <div class="text-lg font-bold text-red-700 dark:text-red-300">{{ formatCurrency(detailData.total_cost_input) }}</div>
                        </div>
                        <div class="text-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <div class="text-sm text-blue-600 dark:text-blue-400 mb-1">Biaya Repack</div>
                            <div class="text-lg font-bold text-blue-700 dark:text-blue-300">{{ formatCurrency(detailData.biaya_repack) }}</div>
                        </div>
                        <div class="text-center p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                            <div class="text-sm text-green-600 dark:text-green-400 mb-1">Total HPP Output</div>
                            <div class="text-lg font-bold text-green-700 dark:text-green-300">{{ formatCurrency(detailData.total_cost_output) }}</div>
                        </div>
                    </div>
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
                        v-if="canDelete && canDeleteItem(detailData)"
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

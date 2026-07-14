<script setup>
import { ref, computed } from 'vue';
import { purchaseReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportList } from '@/composables/useReportList';
import { useReportDetailDialog } from '@/composables/useReportDetailDialog';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';

const { formatCurrency, formatDateTime, toDateString, todayString, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();
const authStore = useAuthStore();
const canExport = computed(() => authStore.can('laporan.export'));

const canViewHarga = ref(false);
const selectedWarehouse = ref(null);
const selectedSource = ref(null);
const sourceOptions = [
    { label: 'Semua Sumber', value: null },
    { label: 'Purchase Order', value: 'po' },
    { label: 'Serial', value: 'serial' }
];

const { items, loading, totalRecords, summary, searchQuery, startDate, endDate, lazyParams, dropdowns, exportingExcel, exportExcel, onPage, onSort, doSearch, clearSearch, onFilterChange, resetFilters, buildFilterParams } = useReportList({
    fetchList: (params) => purchaseReportApi.getPerSupplier(params),
    exportFn: purchaseReportApi.exportPerSupplier,
    exportFilenameFn: () => `laporan_pembelian_per_supplier_${todayString()}.xlsx`,
    fetchDropdowns: purchaseReportApi.getDropdowns,
    getExtraFilters: () => ({
        warehouse_id: selectedWarehouse.value,
        source: selectedSource.value
    }),
    onResetFilters: () => {
        selectedWarehouse.value = null;
        selectedSource.value = null;
    },
    onListLoaded: (data) => {
        canViewHarga.value = data.can_view_harga ?? false;
    },
    listErrorLabel: 'laporan pembelian per supplier',
    defaultSortField: 'total_grand_total'
});

const warehouses = computed(() => dropdowns.value.warehouses ?? []);

const {
    detailDialog,
    loadingDetail,
    detailMeta: detailData,
    detailItems,
    detailSummary,
    detailTotalRecords,
    detailLazyParams,
    openDetail: openSupplierDetail,
    onDetailPage
} = useReportDetailDialog({
    fetchDetail: (supplierId, params) => purchaseReportApi.getPerSupplierDetail(supplierId, params),
    resolveDetailKey: (context, meta) => meta?.id ?? context.supplier_id,
    parseResponse: (data) => ({
        meta: data.supplier,
        items: data.details,
        summary: data.summary,
        total: data.pagination?.total ?? 0
    }),
    errorLabel: 'detail pembelian supplier',
    defaultSortField: 'tanggal_po'
});

function detailFilterParams() {
    const params = {
        date_from: toDateString(startDate.value),
        date_to: toDateString(endDate.value)
    };
    if (selectedWarehouse.value) params.warehouse_id = selectedWarehouse.value;
    if (selectedSource.value) params.source = selectedSource.value;
    return params;
}

async function viewDetail(supplier) {
    await openSupplierDetail(supplier, detailFilterParams());
}

function onDetailPageEvent(event) {
    onDetailPage(event, detailFilterParams());
}

const detailColumns = computed(() => {
    const cols = [
        { field: '#', header: '#', width: '40px' },
        { field: 'tanggal', header: 'Tanggal', width: '140px' },
        { field: 'nomor_dokumen', header: 'No. Dokumen', width: '170px' },
        { field: 'warehouse', header: 'Warehouse', width: '130px' },
        { field: 'items', header: 'Item', width: '60px', align: 'right' },
        { field: 'tempo', header: 'Tempo', width: '80px', align: 'right' }
    ];
    if (canViewHarga.value) {
        cols.push({ field: 'subtotal', header: 'Subtotal', align: 'right', width: '130px' }, { field: 'diskon', header: 'Diskon', align: 'right', width: '120px' }, { field: 'grand_total', header: 'Grand Total', align: 'right', width: '130px' });
    }
    return cols;
});

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'jumlah_po', sort_order: 'desc' };
    let allData;
    try {
        const response = await purchaseReportApi.getPerSupplier(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode', field: 'kode_supplier', width: 22 },
        { header: 'Nama Supplier', field: 'nama_supplier', width: 40 },
        { header: 'Jumlah PO', field: 'jumlah_po', width: 16, align: 'center' }
    ];
    if (canViewHarga.value) {
        columns.push(
            { header: 'Total Subtotal', width: 24, align: 'right', accessor: (row) => formatCurrency(row.total_subtotal) },
            { header: 'Total Diskon', width: 24, align: 'right', accessor: (row) => formatCurrency(row.total_diskon) },
            { header: 'Total Grand Total', width: 26, align: 'right', accessor: (row) => formatCurrency(row.total_grand_total) }
        );
    }

    exportListPdf({
        title: 'Laporan Pembelian Per Supplier',
        filename: `laporan_pembelian_per_supplier_${todayString()}`,
        columns,
        data: allData,
        totalLabel: `Total: ${allData.length} supplier`
    });
}
</script>

<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Laporan Pembelian per Supplier</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Warehouse" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedSource" :options="sourceOptions" optionLabel="label" optionValue="value" placeholder="Sumber" class="w-36" @change="onFilterChange" />
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilterChange" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilterChange" />
                    </div>
                    <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                </div>
            </template>
        </Toolbar>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Supplier</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ summary.total_supplier }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total PO</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ summary.total_po }}</div>
            </div>
            <template v-if="canViewHarga">
                <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                    <div class="text-surface-500 text-sm mb-1">Total Subtotal</div>
                    <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(summary.total_subtotal) }}</div>
                </div>
                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                    <div class="text-red-600 dark:text-red-400 text-sm mb-1">Total Diskon</div>
                    <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ formatCurrency(summary.total_diskon) }}</div>
                </div>
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                    <div class="text-blue-600 dark:text-blue-400 text-sm mb-1">Total Grand Total</div>
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(summary.total_grand_total) }}</div>
                </div>
            </template>
        </div>

        <!-- DataTable -->
        <DataTable
            :value="items"
            :loading="loading"
            :lazy="true"
            :paginator="true"
            :rows="lazyParams.rows"
            :totalRecords="totalRecords"
            :rowsPerPageOptions="[10, 25, 50]"
            :sortField="lazyParams.sortField"
            :sortOrder="lazyParams.sortOrder"
            dataKey="ulid"
            @page="onPage"
            @sort="onSort"
            stripedRows
            showGridlines
            scrollable
        >
            <template #header>
                <DataTableHeader v-model="searchQuery" title="Pembelian per Supplier" placeholder="Cari kode, nama supplier..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <div class="flex gap-2">
                            <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                            <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                        </div>
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data pembelian per supplier</div>
            </template>

            <Column field="kode_supplier" header="Kode" sortable style="min-width: 120px">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.kode_supplier }}</span>
                </template>
            </Column>

            <Column field="nama_supplier" header="Nama Supplier" sortable style="min-width: 200px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nama_supplier }}</span>
                </template>
            </Column>

            <Column field="jumlah_po" header="Jumlah PO" sortable style="min-width: 110px" class="text-right">
                <template #body="{ data }">
                    <Badge :value="data.jumlah_po" severity="secondary" />
                </template>
            </Column>

            <template v-if="canViewHarga">
                <Column field="total_subtotal" header="Total Subtotal" sortable style="min-width: 150px" class="text-right">
                    <template #body="{ data }">
                        {{ formatCurrency(data.total_subtotal) }}
                    </template>
                </Column>

                <Column field="total_diskon" header="Total Diskon" sortable style="min-width: 140px" class="text-right">
                    <template #body="{ data }">
                        <span class="text-red-600 dark:text-red-400">{{ formatCurrency(data.total_diskon) }}</span>
                    </template>
                </Column>

                <Column field="total_grand_total" header="Grand Total" sortable style="min-width: 150px" class="text-right">
                    <template #body="{ data }">
                        <span class="font-semibold">{{ formatCurrency(data.total_grand_total) }}</span>
                    </template>
                </Column>
            </template>

            <Column :exportable="false" style="min-width: 80px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <Button icon="pi pi-eye" outlined rounded severity="info" @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" aria-label="Lihat Detail" />
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <Dialog v-model:visible="detailDialog" modal header="Detail Pembelian Supplier" :style="{ width: '900px' }">
            <div v-if="loadingDetail" class="flex justify-center py-8">
                <ProgressSpinner style="width: 50px; height: 50px" />
            </div>

            <template v-else-if="detailData.ulid">
                <!-- Supplier Info -->
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <DetailItem label="Kode Supplier" :value="detailData.kode_supplier" />
                    <DetailItem label="Nama Supplier" :value="detailData.nama_supplier" />
                </div>

                <Divider />

                <!-- Summary -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Jumlah PO</div>
                        <div class="text-xl font-bold">{{ detailSummary.jumlah_po }}</div>
                    </div>
                    <template v-if="canViewHarga">
                        <div class="text-center">
                            <div class="text-surface-500 text-sm">Total Subtotal</div>
                            <div class="text-xl font-bold">{{ formatCurrency(detailSummary.total_subtotal) }}</div>
                        </div>
                        <div class="text-center">
                            <div class="text-surface-500 text-sm">Total Diskon</div>
                            <div class="text-xl font-bold text-red-600 dark:text-red-400">{{ formatCurrency(detailSummary.total_diskon) }}</div>
                        </div>
                        <div class="text-center">
                            <div class="text-surface-500 text-sm">Total Grand Total</div>
                            <div class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(detailSummary.total_grand_total) }}</div>
                        </div>
                    </template>
                </div>

                <Divider />

                <!-- Detail Table -->
                <h6 class="font-semibold mb-3">Daftar Purchase Order ({{ detailTotalRecords }} PO)</h6>
                <DetailTable :data="detailItems" :columns="detailColumns">
                    <template #tanggal="{ item }">
                        {{ formatDateTime(item.tanggal_po) }}
                    </template>
                    <template #nomor_dokumen="{ item }">
                        <span class="font-mono text-sm">{{ item.nomor_dokumen }}</span>
                    </template>
                    <template #warehouse="{ item }">
                        {{ item.nama_warehouse }}
                    </template>
                    <template #items="{ item }">
                        {{ item.details_count }}
                    </template>
                    <template #tempo="{ item }"> {{ item.tempo_hari || 0 }} Hari </template>
                    <template #subtotal="{ item }">
                        {{ formatCurrency(item.subtotal) }}
                    </template>
                    <template #diskon="{ item }">
                        <span class="text-red-600 dark:text-red-400">{{ formatCurrency(item.total_diskon_header) }}</span>
                    </template>
                    <template #grand_total="{ item }">
                        <span class="font-medium">{{ formatCurrency(item.grand_total) }}</span>
                    </template>
                </DetailTable>

                <!-- Detail Pagination -->
                <div v-if="detailTotalRecords > detailLazyParams.rows" class="flex justify-end mt-4">
                    <Paginator :first="detailLazyParams.first" :rows="detailLazyParams.rows" :totalRecords="detailTotalRecords" :rowsPerPageOptions="[10, 25, 50]" @page="onDetailPageEvent" />
                </div>
            </template>

            <template #footer>
                <Button label="Tutup" icon="pi pi-times" text @click="detailDialog = false" />
            </template>
        </Dialog>
    </div>
</template>

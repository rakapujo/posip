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

const { formatQty, formatCurrency, formatDateTime, toDateString, todayString, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();
const authStore = useAuthStore();
const canExport = computed(() => authStore.can('laporan.export'));

const canViewHarga = ref(false);
const selectedSupplier = ref(null);
const selectedWarehouse = ref(null);
const selectedBrand = ref(null);
const selectedKategori = ref(null);
const selectedSource = ref(null);
const sourceOptions = [
    { label: 'Semua Sumber', value: null },
    { label: 'Purchase Order', value: 'po' },
    { label: 'Serial', value: 'serial' }
];

const { items, loading, totalRecords, summary, searchQuery, startDate, endDate, lazyParams, dropdowns, exportingExcel, exportExcel, onPage, onSort, doSearch, clearSearch, onFilterChange, resetFilters, buildFilterParams } = useReportList({
    fetchList: (params) => purchaseReportApi.getPerBarang(params),
    exportFn: purchaseReportApi.exportPerBarang,
    exportFilenameFn: () => `laporan_pembelian_per_barang_${todayString()}.xlsx`,
    fetchDropdowns: purchaseReportApi.getDropdowns,
    getExtraFilters: () => ({
        supplier_id: selectedSupplier.value,
        warehouse_id: selectedWarehouse.value,
        brand_id: selectedBrand.value,
        kategori_id: selectedKategori.value,
        source: selectedSource.value
    }),
    onResetFilters: () => {
        selectedSupplier.value = null;
        selectedWarehouse.value = null;
        selectedBrand.value = null;
        selectedKategori.value = null;
        selectedSource.value = null;
    },
    onListLoaded: (data) => {
        canViewHarga.value = data.can_view_harga ?? false;
    },
    listErrorLabel: 'laporan pembelian per barang',
    defaultSortField: 'total_subtotal'
});

const suppliers = computed(() => dropdowns.value.suppliers ?? []);
const warehouses = computed(() => dropdowns.value.warehouses ?? []);
const brands = computed(() => dropdowns.value.brands ?? []);
const kategoris = computed(() => dropdowns.value.kategoris ?? []);

const {
    detailDialog,
    loadingDetail,
    detailMeta: detailData,
    detailItems,
    detailSummary,
    detailTotalRecords,
    detailLazyParams,
    openDetail: openProductDetail,
    onDetailPage
} = useReportDetailDialog({
    fetchDetail: (ulid, params) => purchaseReportApi.getPerBarangDetail(ulid, params),
    resolveDetailKey: (context, meta) => meta?.ulid ?? context.ulid,
    parseResponse: (data) => ({
        meta: data.product,
        items: data.details,
        summary: data.summary,
        total: data.pagination?.total ?? 0
    }),
    errorLabel: 'detail pembelian produk',
    defaultSortField: 'tanggal_po'
});

function detailFilterParams() {
    const params = {
        date_from: toDateString(startDate.value),
        date_to: toDateString(endDate.value)
    };
    if (selectedSupplier.value) params.supplier_id = selectedSupplier.value;
    if (selectedWarehouse.value) params.warehouse_id = selectedWarehouse.value;
    if (selectedSource.value) params.source = selectedSource.value;
    return params;
}

async function viewDetail(product) {
    await openProductDetail(product, detailFilterParams());
}

function onDetailPageEvent(event) {
    onDetailPage(event, detailFilterParams());
}

const detailColumns = computed(() => {
    const cols = [
        { field: '#', header: '#', width: '40px' },
        { field: 'tanggal', header: 'Tanggal', width: '140px' },
        { field: 'nomor_dokumen', header: 'No. Dokumen', width: '160px' },
        { field: 'supplier', header: 'Supplier', width: '140px' },
        { field: 'warehouse', header: 'Warehouse', width: '120px' },
        { field: 'unit', header: 'Unit', width: '80px' },
        { field: 'qty', header: 'Qty', align: 'right', width: '80px' }
    ];
    if (canViewHarga.value) {
        cols.push({ field: 'harga', header: 'Harga/Unit', align: 'right', width: '120px' }, { field: 'diskon', header: 'Diskon', align: 'right', width: '100px' }, { field: 'subtotal', header: 'Subtotal', align: 'right', width: '120px' });
    }
    return cols;
});

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'total_subtotal', sort_order: 'desc' };
    let allData;
    try {
        const response = await purchaseReportApi.getPerBarang(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode', field: 'kode_produk', width: 22 },
        { header: 'Nama Produk', field: 'nama_produk', width: 40 },
        { header: 'Brand', width: 20, accessor: (row) => row.brand || '-' },
        { header: 'Kategori', width: 20, accessor: (row) => row.kategori || '-' },
        { header: 'Jml PO', field: 'jumlah_po', width: 12, align: 'center' },
        { header: 'Total Qty', width: 16, align: 'right', accessor: (row) => formatQty(row.total_qty) }
    ];
    if (canViewHarga.value) {
        columns.push(
            { header: 'Total Bruto', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_bruto) },
            { header: 'Total Diskon', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_diskon) },
            { header: 'Total Nett', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_subtotal) }
        );
    }

    exportListPdf({
        title: 'Laporan Pembelian Per Barang',
        filename: `laporan_pembelian_per_barang_${todayString()}`,
        columns,
        data: allData,
        totalLabel: `Total: ${allData.length} produk`
    });
}
</script>

<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Laporan Pembelian per Barang</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <Select v-model="selectedSupplier" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Supplier" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Warehouse" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedBrand" :options="brands" optionLabel="nama_brand" optionValue="id" placeholder="Brand" class="w-36" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedKategori" :options="kategoris" optionLabel="nama_kategori" optionValue="id" placeholder="Kategori" class="w-36" filter showClear @change="onFilterChange" />
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
                <div class="text-surface-500 text-sm mb-1">Total Produk</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ summary.total_produk }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Qty</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatQty(summary.total_qty) }}</div>
            </div>
            <template v-if="canViewHarga">
                <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                    <div class="text-surface-500 text-sm mb-1">Total Bruto</div>
                    <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(summary.total_bruto) }}</div>
                </div>
                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                    <div class="text-red-600 dark:text-red-400 text-sm mb-1">Total Diskon</div>
                    <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ formatCurrency(summary.total_diskon) }}</div>
                </div>
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                    <div class="text-blue-600 dark:text-blue-400 text-sm mb-1">Total Nett</div>
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(summary.total_subtotal) }}</div>
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
                <DataTableHeader v-model="searchQuery" title="Pembelian per Barang" placeholder="Cari kode, nama produk..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <div class="flex gap-2">
                            <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                            <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                        </div>
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data pembelian per barang</div>
            </template>

            <Column field="kode_produk" header="Kode" sortable style="min-width: 120px">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.kode_produk }}</span>
                </template>
            </Column>

            <Column field="nama_produk" header="Nama Produk" sortable style="min-width: 200px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nama_produk }}</span>
                </template>
            </Column>

            <Column field="brand" header="Brand" style="min-width: 120px">
                <template #body="{ data }">
                    <span class="text-surface-500">{{ data.brand || '-' }}</span>
                </template>
            </Column>

            <Column field="kategori" header="Kategori" style="min-width: 120px">
                <template #body="{ data }">
                    <span class="text-surface-500">{{ data.kategori || '-' }}</span>
                </template>
            </Column>

            <Column field="jumlah_po" header="Jml PO" sortable style="min-width: 90px" class="text-right">
                <template #body="{ data }">
                    <Badge :value="data.jumlah_po" severity="secondary" />
                </template>
            </Column>

            <Column field="total_qty" header="Total Qty" sortable style="min-width: 110px" class="text-right">
                <template #body="{ data }">
                    {{ formatQty(data.total_qty) }}
                </template>
            </Column>

            <template v-if="canViewHarga">
                <Column field="total_bruto" header="Total Bruto" sortable style="min-width: 140px" class="text-right">
                    <template #body="{ data }">
                        {{ formatCurrency(data.total_bruto) }}
                    </template>
                </Column>

                <Column field="total_diskon" header="Total Diskon" sortable style="min-width: 130px" class="text-right">
                    <template #body="{ data }">
                        <span class="text-red-600 dark:text-red-400">{{ formatCurrency(data.total_diskon) }}</span>
                    </template>
                </Column>

                <Column field="total_subtotal" header="Total Nett" sortable style="min-width: 140px" class="text-right">
                    <template #body="{ data }">
                        <span class="font-semibold">{{ formatCurrency(data.total_subtotal) }}</span>
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
        <Dialog v-model:visible="detailDialog" modal header="Detail Pembelian Produk" :style="{ width: '950px' }">
            <div v-if="loadingDetail" class="flex justify-center py-8">
                <ProgressSpinner style="width: 50px; height: 50px" />
            </div>

            <template v-else-if="detailData.ulid">
                <!-- Product Info -->
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <DetailItem label="Kode Produk" :value="detailData.kode_produk" />
                    <DetailItem label="Nama Produk" :value="detailData.nama_produk" />
                    <DetailItem label="Brand" :value="detailData.brand || '-'" />
                    <DetailItem label="Kategori" :value="detailData.kategori || '-'" />
                </div>

                <Divider />

                <!-- Summary -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Jumlah PO</div>
                        <div class="text-xl font-bold">{{ detailSummary.jumlah_po }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Total Qty</div>
                        <div class="text-xl font-bold">{{ formatQty(detailSummary.total_qty) }}</div>
                    </div>
                    <template v-if="canViewHarga">
                        <div class="text-center">
                            <div class="text-surface-500 text-sm">Total Bruto</div>
                            <div class="text-xl font-bold">{{ formatCurrency(detailSummary.total_bruto) }}</div>
                        </div>
                        <div class="text-center">
                            <div class="text-surface-500 text-sm">Total Diskon</div>
                            <div class="text-xl font-bold text-red-600 dark:text-red-400">{{ formatCurrency(detailSummary.total_diskon) }}</div>
                        </div>
                        <div class="text-center">
                            <div class="text-surface-500 text-sm">Total Nett</div>
                            <div class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(detailSummary.total_subtotal) }}</div>
                        </div>
                    </template>
                </div>

                <Divider />

                <!-- Detail Table -->
                <h6 class="font-semibold mb-3">Riwayat Pembelian ({{ detailTotalRecords }} transaksi)</h6>
                <DetailTable :data="detailItems" :columns="detailColumns">
                    <template #tanggal="{ item }">
                        {{ formatDateTime(item.tanggal_po) }}
                    </template>
                    <template #nomor_dokumen="{ item }">
                        <span class="font-mono text-sm">{{ item.nomor_dokumen }}</span>
                    </template>
                    <template #supplier="{ item }">
                        {{ item.nama_supplier }}
                    </template>
                    <template #warehouse="{ item }">
                        {{ item.nama_warehouse }}
                    </template>
                    <template #unit="{ item }">
                        {{ item.unit_used }}
                    </template>
                    <template #qty="{ item }">
                        {{ formatQty(item.qty_in_unit) }}
                    </template>
                    <template #harga="{ item }">
                        {{ formatCurrency(item.harga_per_unit) }}
                    </template>
                    <template #diskon="{ item }">
                        <span class="text-red-600 dark:text-red-400">{{ formatCurrency(item.total_diskon_item) }}</span>
                    </template>
                    <template #subtotal="{ item }">
                        <span class="font-medium">{{ formatCurrency(item.subtotal) }}</span>
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

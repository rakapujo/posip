<script setup>
import { ref, computed } from 'vue';
import { salesProductReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportList } from '@/composables/useReportList';
import { useReportDetailDialog } from '@/composables/useReportDetailDialog';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const { formatQty, formatCurrency, formatPercent, formatDateTime, toDateString, todayString, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();

const canViewHpp = computed(() => authStore.can('stok.view_hpp'));
const canExport = computed(() => authStore.can('laporan.export'));

const selectedTerminal = ref(null);
const selectedBrand = ref(null);
const selectedKategori = ref(null);

const { items, loading, totalRecords, summary, searchQuery, startDate, endDate, lazyParams, dropdowns, exportingExcel, exportExcel, onPage, onSort, doSearch, clearSearch, onFilterChange, resetFilters, buildFilterParams } = useReportList({
    fetchList: (params) => salesProductReportApi.getAll(params),
    exportFn: salesProductReportApi.exportExcel,
    exportFilenameFn: () => `laporan_penjualan_per_barang_${todayString()}.xlsx`,
    fetchDropdowns: salesProductReportApi.getDropdowns,
    getExtraFilters: () => ({
        terminal_id: selectedTerminal.value,
        brand_id: selectedBrand.value,
        kategori_id: selectedKategori.value
    }),
    onResetFilters: () => {
        selectedTerminal.value = null;
        selectedBrand.value = null;
        selectedKategori.value = null;
    },
    listErrorLabel: 'laporan penjualan per barang',
    defaultSortField: 'pendapatan'
});

const terminals = computed(() => dropdowns.value.terminals ?? []);
const brands = computed(() => dropdowns.value.brands ?? []);
const kategoris = computed(() => dropdowns.value.kategoris ?? []);

// Detail dialog
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
    fetchDetail: (ulid, params) => salesProductReportApi.get(ulid, params),
    resolveDetailKey: (context, meta) => meta?.ulid ?? context.ulid,
    parseResponse: (data) => ({
        meta: data.product,
        items: data.details,
        summary: data.summary,
        total: data.pagination?.total ?? 0
    }),
    errorLabel: 'detail penjualan produk'
});

function detailFilterParams() {
    const params = {
        date_from: toDateString(startDate.value),
        date_to: toDateString(endDate.value)
    };
    if (selectedTerminal.value) {
        params.terminal_id = selectedTerminal.value;
    }
    return params;
}

async function viewDetail(product) {
    await openProductDetail(product, detailFilterParams());
}

function onDetailPageEvent(event) {
    onDetailPage(event, detailFilterParams());
}

// Detail table columns
const detailColumns = computed(() => {
    const cols = [
        { field: '#', header: '#', width: '40px' },
        { field: 'tanggal', header: 'Tanggal', width: '140px' },
        { field: 'nomor_dokumen', header: 'No. Invoice', width: '160px' },
        { field: 'unit', header: 'Unit', width: '80px' },
        { field: 'qty', header: 'Qty', align: 'right', width: '80px' },
        { field: 'harga_satuan', header: 'Harga Satuan', align: 'right', width: '120px' },
        { field: 'diskon', header: 'Diskon Line', align: 'right', width: '100px' },
        { field: 'nett', header: 'Nett', align: 'right', width: '120px' }
    ];
    if (canViewHpp.value) {
        cols.push({ field: 'hpp_pcs', header: 'HPP/pcs', align: 'right', width: '100px' }, { field: 'hpp_total', header: 'HPP Total', align: 'right', width: '120px' }, { field: 'laba', header: 'Laba', align: 'right', width: '120px' });
    }
    return cols;
});

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'pendapatan', sort_order: 'desc' };
    let allData;
    try {
        const response = await salesProductReportApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode', field: 'kode_produk', width: 18 },
        { header: 'Nama Produk', field: 'nama_produk', width: 40 },
        { header: 'Brand', field: 'brand', width: 22 },
        { header: 'Kategori', field: 'kategori', width: 22 },
        { header: 'Qty Terjual', field: 'qty_terjual', width: 14, align: 'right', accessor: (row) => formatQty(row.qty_terjual) },
        { header: 'Qty Retur', field: 'qty_retur', width: 12, align: 'right', accessor: (row) => formatQty(row.qty_retur) },
        { header: 'Pendapatan', width: 20, align: 'right', accessor: (row) => formatCurrency(row.pendapatan) }
    ];
    if (canViewHpp.value) {
        columns.push(
            { header: 'HPP Total', width: 20, align: 'right', accessor: (row) => formatCurrency(row.hpp_total) },
            { header: 'Laba Kotor', width: 20, align: 'right', accessor: (row) => formatCurrency(row.laba_kotor) },
            { header: 'Margin %', width: 14, align: 'right', accessor: (row) => formatPercent(row.margin_persen) }
        );
    }

    exportListPdf({
        title: 'Laporan Penjualan Per Barang',
        filename: `laporan_penjualan_per_barang_${todayString()}`,
        columns,
        data: allData,
        totalLabel: `Total: ${allData.length} produk`
    });
}

function getMarginSeverity(margin) {
    if (margin >= 30) return 'text-green-600 dark:text-green-400';
    if (margin >= 15) return 'text-blue-600 dark:text-blue-400';
    if (margin >= 0) return 'text-orange-600 dark:text-orange-400';
    return 'text-red-600 dark:text-red-400';
}
</script>

<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Penjualan per Barang</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <Select v-model="selectedTerminal" :options="terminals" optionLabel="nama_terminal" optionValue="id" placeholder="Terminal" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedBrand" :options="brands" optionLabel="nama_brand" optionValue="id" placeholder="Brand" class="w-36" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedKategori" :options="kategoris" optionLabel="nama_kategori" optionValue="id" placeholder="Kategori" class="w-36" filter showClear @change="onFilterChange" />
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
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6" :class="{ 'lg:grid-cols-7': canViewHpp }">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Produk</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatQty(summary.total_produk) }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Qty Terjual</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatQty(summary.total_qty) }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Qty Retur</div>
                <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ formatQty(summary.total_qty_retur) }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Pendapatan</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(summary.total_pendapatan) }}</div>
            </div>
            <div v-if="canViewHpp" class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total HPP</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(summary.total_hpp) }}</div>
            </div>
            <div v-if="canViewHpp" class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                <div class="text-green-600 dark:text-green-400 text-sm mb-1">Total Laba Kotor</div>
                <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(summary.total_laba) }}</div>
            </div>
            <div v-if="canViewHpp" class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-blue-600 dark:text-blue-400 text-sm mb-1">Rata-rata Margin</div>
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ formatPercent(summary.avg_margin) }}</div>
            </div>
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
                <DataTableHeader v-model="searchQuery" title="Penjualan per Barang" placeholder="Cari kode, nama produk..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                        <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data penjualan</div>
            </template>

            <!-- Kode Produk -->
            <Column field="kode_produk" header="Kode" sortable style="min-width: 120px">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.kode_produk }}</span>
                </template>
            </Column>

            <!-- Nama Produk -->
            <Column field="nama_produk" header="Nama Produk" sortable style="min-width: 200px">
                <template #body="{ data }">
                    <div class="font-medium">{{ data.nama_produk }}</div>
                </template>
            </Column>

            <!-- Brand -->
            <Column field="brand" header="Brand" style="min-width: 120px">
                <template #body="{ data }">
                    <span class="text-surface-500">{{ data.brand || '-' }}</span>
                </template>
            </Column>

            <!-- Kategori -->
            <Column field="kategori" header="Kategori" style="min-width: 120px">
                <template #body="{ data }">
                    <span class="text-surface-500">{{ data.kategori || '-' }}</span>
                </template>
            </Column>

            <!-- Qty Terjual -->
            <Column field="qty_terjual" header="Qty Terjual" sortable style="min-width: 110px" class="text-right">
                <template #body="{ data }">
                    {{ formatQty(data.qty_terjual) }}
                </template>
            </Column>

            <!-- Qty Retur -->
            <Column field="qty_retur" header="Qty Retur" sortable style="min-width: 100px" class="text-right">
                <template #body="{ data }">
                    <span :class="{ 'text-red-600': data.qty_retur > 0 }">{{ formatQty(data.qty_retur) }}</span>
                </template>
            </Column>

            <!-- Pendapatan -->
            <Column field="pendapatan" header="Pendapatan" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.pendapatan) }}
                </template>
            </Column>

            <!-- HPP Total (gated) -->
            <Column v-if="canViewHpp" field="hpp_total" header="HPP Total" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.hpp_total) }}
                </template>
            </Column>

            <!-- Laba Kotor (gated) -->
            <Column v-if="canViewHpp" field="laba_kotor" header="Laba Kotor" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    <span :class="data.laba_kotor >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ formatCurrency(data.laba_kotor) }}
                    </span>
                </template>
            </Column>

            <!-- Margin % (gated) -->
            <Column v-if="canViewHpp" field="margin_persen" header="Margin %" sortable style="min-width: 100px" class="text-right">
                <template #body="{ data }">
                    <span :class="getMarginSeverity(data.margin_persen)">
                        {{ formatPercent(data.margin_persen) }}
                    </span>
                </template>
            </Column>

            <!-- Actions -->
            <Column :exportable="false" style="min-width: 80px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <Button icon="pi pi-eye" outlined rounded severity="info" @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" aria-label="Lihat Detail" />
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <Dialog v-model:visible="detailDialog" modal header="Detail Penjualan Produk" :style="{ width: '900px' }">
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
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Total Qty</div>
                        <div class="text-xl font-bold">{{ formatQty(detailSummary.total_qty) }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Total Pendapatan</div>
                        <div class="text-xl font-bold">{{ formatCurrency(detailSummary.total_pendapatan) }}</div>
                    </div>
                    <div v-if="canViewHpp" class="text-center">
                        <div class="text-surface-500 text-sm">Total HPP</div>
                        <div class="text-xl font-bold">{{ formatCurrency(detailSummary.total_hpp) }}</div>
                    </div>
                    <div v-if="canViewHpp" class="text-center">
                        <div class="text-surface-500 text-sm">Total Laba</div>
                        <div class="text-xl font-bold" :class="detailSummary.total_laba >= 0 ? 'text-green-600' : 'text-red-600'">
                            {{ formatCurrency(detailSummary.total_laba) }}
                        </div>
                    </div>
                </div>

                <Divider />

                <!-- Detail Table -->
                <h6 class="font-semibold mb-3">Transaksi Individual ({{ detailTotalRecords }} transaksi)</h6>
                <DetailTable :data="detailItems" :columns="detailColumns">
                    <template #tanggal="{ item }">
                        {{ formatDateTime(item.tanggal) }}
                    </template>
                    <template #nomor_dokumen="{ item }">
                        <span class="font-mono text-sm">{{ item.nomor_dokumen }}</span>
                    </template>
                    <template #qty="{ item }">
                        {{ formatQty(item.qty) }}
                    </template>
                    <template #harga_satuan="{ item }">
                        {{ formatCurrency(item.harga_satuan) }}
                    </template>
                    <template #diskon="{ item }">
                        {{ formatCurrency(item.diskon_total) }}
                    </template>
                    <template #nett="{ item }">
                        {{ formatCurrency(item.jumlah) }}
                    </template>
                    <template #hpp_pcs="{ item }">
                        {{ formatCurrency(item.hpp_at_time) }}
                    </template>
                    <template #hpp_total="{ item }">
                        {{ formatCurrency(item.hpp_line) }}
                    </template>
                    <template #laba="{ item }">
                        <span :class="item.laba >= 0 ? 'text-green-600' : 'text-red-600'">
                            {{ formatCurrency(item.laba) }}
                        </span>
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

<script setup>
import { ref, computed } from 'vue';
import { purchaseReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportList } from '@/composables/useReportList';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const { formatQty, formatCurrency, formatDateTime, todayString, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();
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
    fetchList: (params) => purchaseReportApi.getHargaTerakhir(params),
    exportFn: purchaseReportApi.exportHargaTerakhir,
    exportFilenameFn: () => `laporan_harga_terakhir_pembelian_${todayString()}.xlsx`,
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
    listErrorLabel: 'laporan harga terakhir',
    defaultSortField: 'kode_produk',
    defaultSortOrder: 1
});

const suppliers = computed(() => dropdowns.value.suppliers ?? []);
const warehouses = computed(() => dropdowns.value.warehouses ?? []);
const brands = computed(() => dropdowns.value.brands ?? []);
const kategoris = computed(() => dropdowns.value.kategoris ?? []);

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'kode_produk', sort_order: 'asc' };
    let allData;
    try {
        const response = await purchaseReportApi.getHargaTerakhir(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode', field: 'kode_produk', width: 22 },
        { header: 'Nama Produk', field: 'nama_produk', width: 35 },
        { header: 'Brand', width: 18, accessor: (row) => row.brand || '-' },
        { header: 'Kategori', width: 18, accessor: (row) => row.kategori || '-' },
        { header: 'Tgl Terakhir', width: 22, accessor: (row) => formatDateTime(row.tanggal_po) },
        { header: 'No. Dokumen', field: 'nomor_dokumen', width: 26 },
        { header: 'Supplier', field: 'nama_supplier', width: 25 },
        { header: 'Gudang', field: 'nama_warehouse', width: 18 },
        { header: 'Unit', field: 'unit_used', width: 12 },
        { header: 'Qty', width: 12, align: 'right', accessor: (row) => formatQty(row.qty_in_unit) }
    ];
    if (canViewHarga.value) {
        columns.push(
            { header: 'Harga/Unit', width: 20, align: 'right', accessor: (row) => formatCurrency(row.harga_per_unit) },
            { header: 'Diskon', width: 18, align: 'right', accessor: (row) => (parseFloat(row.total_diskon_item) > 0 ? formatCurrency(row.total_diskon_item) : '-') },
            { header: 'Nett/Unit', width: 20, align: 'right', accessor: (row) => formatCurrency(row.cost_per_unit) }
        );
    }

    exportListPdf({
        title: 'Laporan Harga Terakhir Pembelian',
        filename: `laporan_harga_terakhir_pembelian_${todayString()}`,
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
                <span class="text-xl font-semibold">Laporan Harga Terakhir Pembelian</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <Select v-model="selectedSupplier" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Supplier" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Gudang" class="w-40" filter showClear @change="onFilterChange" />
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

        <!-- Summary Card -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Produk</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ summary.total_produk }}</div>
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
                <DataTableHeader v-model="searchQuery" title="Harga Terakhir Pembelian" placeholder="Cari kode/nama produk..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <div class="flex gap-2">
                            <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                            <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                        </div>
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data harga terakhir pembelian</div>
            </template>

            <Column field="kode_produk" header="Kode" sortable style="min-width: 130px">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.kode_produk }}</span>
                </template>
            </Column>

            <Column field="nama_produk" header="Nama Produk" sortable style="min-width: 180px">
                <template #body="{ data }">
                    {{ data.nama_produk }}
                </template>
            </Column>

            <Column field="brand" header="Brand" style="min-width: 120px">
                <template #body="{ data }">
                    {{ data.brand || '-' }}
                </template>
            </Column>

            <Column field="kategori" header="Kategori" style="min-width: 120px">
                <template #body="{ data }">
                    {{ data.kategori || '-' }}
                </template>
            </Column>

            <Column field="tanggal_po" header="Tgl Terakhir" sortable style="min-width: 160px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal_po) }}
                </template>
            </Column>

            <Column field="nomor_dokumen" header="No. Dokumen" style="min-width: 170px">
                <template #body="{ data }">
                    <span class="font-mono text-sm">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="sumber" header="Sumber" style="min-width: 90px">
                <template #body="{ data }">
                    <Tag :value="data.sumber === 'serial' ? 'Serial' : 'PO'" :severity="data.sumber === 'serial' ? 'help' : 'secondary'" />
                </template>
            </Column>

            <Column field="nama_supplier" header="Supplier" style="min-width: 140px">
                <template #body="{ data }">
                    {{ data.nama_supplier }}
                </template>
            </Column>

            <Column field="nama_warehouse" header="Gudang" style="min-width: 120px">
                <template #body="{ data }">
                    {{ data.nama_warehouse }}
                </template>
            </Column>

            <Column field="unit_used" header="Unit" style="min-width: 80px">
                <template #body="{ data }">
                    {{ data.unit_used }}
                </template>
            </Column>

            <Column field="qty_in_unit" header="Qty" style="min-width: 90px" class="text-right">
                <template #body="{ data }">
                    {{ formatQty(data.qty_in_unit) }}
                </template>
            </Column>

            <Column v-if="canViewHarga" field="harga_per_unit" header="Harga/Unit" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.harga_per_unit) }}
                </template>
            </Column>

            <Column v-if="canViewHarga" field="total_diskon_item" header="Diskon" style="min-width: 120px" class="text-right">
                <template #body="{ data }">
                    <span v-if="parseFloat(data.total_diskon_item) > 0" class="text-red-600 dark:text-red-400">{{ formatCurrency(data.total_diskon_item) }}</span>
                    <span v-else>-</span>
                </template>
            </Column>

            <Column v-if="canViewHarga" field="cost_per_unit" header="Nett/Unit" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    <span class="font-medium text-blue-600 dark:text-blue-400">{{ formatCurrency(data.cost_per_unit) }}</span>
                </template>
            </Column>
        </DataTable>
    </div>
</template>

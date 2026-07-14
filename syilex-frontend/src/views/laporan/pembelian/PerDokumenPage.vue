<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { purchaseReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportList } from '@/composables/useReportList';
import { useReportDetailDialog } from '@/composables/useReportDetailDialog';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';

const router = useRouter();
const authStore = useAuthStore();
const { formatCurrency, formatQty, formatDateTime, todayString, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();
const canExport = computed(() => authStore.can('laporan.export'));

const canViewHarga = ref(false);
const selectedSupplier = ref(null);
const selectedWarehouse = ref(null);
const selectedSource = ref(null);
const sourceOptions = [
    { label: 'Semua Sumber', value: null },
    { label: 'Purchase Order', value: 'po' },
    { label: 'Serial', value: 'serial' }
];

const { items, loading, totalRecords, summary, searchQuery, startDate, endDate, lazyParams, dropdowns, exportingExcel, exportExcel, onPage, onSort, doSearch, clearSearch, onFilterChange, resetFilters, buildFilterParams } = useReportList({
    fetchList: (params) => purchaseReportApi.getPerDokumen(params),
    exportFn: purchaseReportApi.exportPerDokumen,
    exportFilenameFn: () => `laporan_pembelian_per_dokumen_${todayString()}.xlsx`,
    fetchDropdowns: purchaseReportApi.getDropdowns,
    getExtraFilters: () => ({
        supplier_id: selectedSupplier.value,
        warehouse_id: selectedWarehouse.value,
        source: selectedSource.value
    }),
    onResetFilters: () => {
        selectedSupplier.value = null;
        selectedWarehouse.value = null;
        selectedSource.value = null;
    },
    onListLoaded: (data) => {
        canViewHarga.value = data.can_view_harga ?? false;
    },
    listErrorLabel: 'laporan pembelian per dokumen',
    defaultSortField: 'tanggal_po'
});

const suppliers = computed(() => dropdowns.value.suppliers ?? []);
const warehouses = computed(() => dropdowns.value.warehouses ?? []);

const {
    detailDialog,
    loadingDetail,
    detailMeta: detailData,
    openDetail: openPoDetail
} = useReportDetailDialog({
    paginated: false,
    fetchDetail: (item) => purchaseReportApi.getPerDokumenDetail(item.ulid),
    parseResponse: (data) => ({
        meta: data.purchase_order,
        items: data.purchase_order?.details ?? []
    }),
    errorLabel: 'purchase order'
});

const detailColumns = computed(() => {
    const cols = [
        { field: '#', header: '#', width: '40px' },
        { field: 'product', header: 'Produk' },
        { field: 'unit_used', header: 'Satuan', width: '80px' },
        { field: 'qty', header: 'Qty', align: 'right', width: '80px' }
    ];
    if (canViewHarga.value) {
        cols.push({ field: 'harga', header: 'Harga', align: 'right', width: '120px' }, { field: 'diskon', header: 'Diskon', align: 'right', width: '100px' }, { field: 'subtotal', header: 'Subtotal', align: 'right', width: '120px' });
    }
    return cols;
});

async function viewDetail(item) {
    if (item.sumber === 'serial') {
        router.push({ name: 'inventory-serial-intake', query: { detail: item.ulid } });
        return;
    }
    await openPoDetail(item);
}

function closeDetail() {
    detailDialog.value = false;
}

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'tanggal_po', sort_order: 'desc' };
    let allData;
    try {
        const response = await purchaseReportApi.getPerDokumen(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.tanggal_po) },
        { header: 'No. Dokumen', field: 'nomor_dokumen', width: 28 },
        { header: 'Supplier', width: 35, accessor: (row) => `[${row.kode_supplier}] ${row.nama_supplier}` },
        { header: 'Gudang', field: 'nama_warehouse', width: 22 },
        { header: 'Item', field: 'details_count', width: 10, align: 'center' }
    ];
    if (canViewHarga.value) {
        columns.push(
            { header: 'Subtotal', width: 22, align: 'right', accessor: (row) => formatCurrency(row.subtotal) },
            { header: 'Diskon', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_diskon_header) },
            { header: 'Grand Total', width: 24, align: 'right', accessor: (row) => formatCurrency(row.grand_total) }
        );
    }
    columns.push({ header: 'Tempo', field: 'tempo_hari', width: 12, align: 'center' });

    exportListPdf({
        title: 'Laporan Pembelian Per Dokumen',
        filename: `laporan_pembelian_per_dokumen_${todayString()}`,
        columns,
        data: allData,
        totalLabel: `Total: ${allData.length} PO`
    });
}
</script>

<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Laporan Pembelian per Dokumen</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <Select v-model="selectedSupplier" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Supplier" class="w-40" filter showClear @change="onFilterChange" />
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
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Jumlah PO</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ summary.jumlah_po }}</div>
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
                <DataTableHeader v-model="searchQuery" title="Daftar Purchase Order" placeholder="Cari nomor dokumen, supplier..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <div class="flex gap-2">
                            <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                            <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                        </div>
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data purchase order</div>
            </template>

            <Column field="tanggal_po" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal_po) }}
                </template>
            </Column>

            <Column field="nomor_dokumen" header="No. Dokumen" sortable style="min-width: 170px">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="sumber" header="Sumber" style="min-width: 90px">
                <template #body="{ data }">
                    <Tag :value="data.sumber === 'serial' ? 'Serial' : 'PO'" :severity="data.sumber === 'serial' ? 'help' : 'secondary'" />
                </template>
            </Column>

            <Column field="nama_supplier" header="Supplier" style="min-width: 180px">
                <template #body="{ data }">
                    <div>
                        <span class="font-medium">{{ data.nama_supplier }}</span>
                        <div class="text-sm text-surface-500">{{ data.kode_supplier }}</div>
                    </div>
                </template>
            </Column>

            <Column field="nama_warehouse" header="Warehouse" style="min-width: 130px">
                <template #body="{ data }">
                    {{ data.nama_warehouse }}
                </template>
            </Column>

            <Column field="details_count" header="Item" style="min-width: 70px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.details_count" severity="secondary" />
                </template>
            </Column>

            <Column v-if="canViewHarga" field="grand_total" header="Grand Total" sortable style="min-width: 150px" class="text-right">
                <template #body="{ data }">
                    <span class="font-semibold">{{ formatCurrency(data.grand_total) }}</span>
                </template>
            </Column>

            <Column field="tempo_hari" header="Tempo" sortable style="min-width: 80px" class="text-right">
                <template #body="{ data }"> {{ data.tempo_hari || 0 }} Hari </template>
            </Column>

            <Column header="Aksi" style="min-width: 80px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" />
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog v-model:visible="detailDialog" title="Detail Purchase Order" :loading="loadingDetail" :created-at="detailData.created_at" :created-by="detailData.created_by?.name" width="900px">
            <template #content>
                <div v-if="detailData.ulid">
                    <!-- Header Info -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <DetailItem label="No. Dokumen" :value="detailData.nomor_dokumen" />
                        <DetailItem label="Tanggal PO" :value="formatDateTime(detailData.tanggal_po)" />
                        <DetailItem label="Supplier" :value="detailData.supplier?.nama_supplier" />
                        <DetailItem label="Warehouse" :value="detailData.warehouse?.nama_warehouse" />
                        <DetailItem label="Tempo" :value="`${detailData.tempo_hari || 0} Hari`" />
                        <DetailItem label="Jatuh Tempo" :value="formatDateTime(detailData.tanggal_jatuh_tempo)" />
                    </div>

                    <!-- Details Table -->
                    <div class="mt-4">
                        <h4 class="text-lg font-medium mb-3">Detail Produk ({{ detailData.details?.length || 0 }} item)</h4>
                        <DetailTable :data="detailData.details" :columns="detailColumns">
                            <template #product="{ item }">
                                <span class="font-medium">{{ item.product?.kode_produk }}</span>
                                <br />
                                <span class="text-surface-500 text-sm">{{ item.product?.nama_produk }}</span>
                            </template>
                            <template #qty="{ item }">{{ formatQty(item.qty_in_unit) }}</template>
                            <template #harga="{ item }">{{ formatCurrency(item.harga_per_unit) }}</template>
                            <template #diskon="{ item }">{{ formatCurrency(item.total_diskon_item) }}</template>
                            <template #subtotal="{ item }">
                                <span class="font-medium">{{ formatCurrency(item.subtotal) }}</span>
                            </template>
                        </DetailTable>
                    </div>

                    <!-- Totals -->
                    <div v-if="canViewHarga" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                        <div></div>
                        <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4 space-y-2">
                            <div class="flex justify-between">
                                <span>Subtotal</span>
                                <span>{{ formatCurrency(detailData.subtotal) }}</span>
                            </div>
                            <div v-if="Number(detailData.total_diskon_header) > 0" class="flex justify-between text-red-500">
                                <span>Diskon</span>
                                <span>-{{ formatCurrency(detailData.total_diskon_header) }}</span>
                            </div>
                            <div v-if="Number(detailData.biaya_kirim_hasil) > 0" class="flex justify-between">
                                <span>Biaya Kirim</span>
                                <span>{{ formatCurrency(detailData.biaya_kirim_hasil) }}</span>
                            </div>
                            <div v-if="Number(detailData.biaya_lain_hasil) > 0" class="flex justify-between">
                                <span>{{ detailData.biaya_lain_nama || 'Biaya Lain' }}</span>
                                <span>{{ formatCurrency(detailData.biaya_lain_hasil) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>DPP</span>
                                <span>{{ formatCurrency(detailData.dpp) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>{{ detailData.pajak_nama }} ({{ detailData.pajak_persen }}%)</span>
                                <span>{{ formatCurrency(detailData.pajak_nominal) }}</span>
                            </div>
                            <Divider />
                            <div class="flex justify-between font-bold text-lg">
                                <span>Grand Total</span>
                                <span>{{ formatCurrency(detailData.grand_total) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Approved info -->
                    <div v-if="detailData.approved_by" class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
                        <div class="flex items-center gap-2 text-surface-500 text-sm">
                            <i class="pi pi-check-circle text-green-500"></i>
                            <span>Disetujui: {{ formatDateTime(detailData.approved_at) }} oleh {{ detailData.approved_by?.name }}</span>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div v-if="detailData.notes" class="mt-4">
                        <span class="text-surface-500 text-sm block mb-1">Catatan</span>
                        <p class="m-0">{{ detailData.notes }}</p>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="flex justify-end">
                    <Button label="Tutup" severity="secondary" outlined @click="closeDetail" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

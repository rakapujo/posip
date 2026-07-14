<script setup>
import { ref, computed } from 'vue';
import { purchaseReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportList } from '@/composables/useReportList';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const { formatCurrency, formatDateTime, todayString, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();
const canExport = computed(() => authStore.can('laporan.export'));

const selectedSupplier = ref(null);
const selectedSource = ref(null);
const sourceOptions = [
    { label: 'Semua Sumber', value: null },
    { label: 'Purchase Order', value: 'po' },
    { label: 'Serial', value: 'serial' }
];

const { items, loading, totalRecords, summary, searchQuery, startDate, endDate, lazyParams, dropdowns, exportingExcel, exportExcel, onPage, onSort, doSearch, clearSearch, onFilterChange, resetFilters, buildFilterParams } = useReportList({
    fetchList: (params) => purchaseReportApi.getDiskon(params),
    exportFn: purchaseReportApi.exportDiskon,
    exportFilenameFn: () => `laporan_diskon_pembelian_${todayString()}.xlsx`,
    fetchDropdowns: purchaseReportApi.getDropdowns,
    getExtraFilters: () => ({
        supplier_id: selectedSupplier.value,
        source: selectedSource.value
    }),
    onResetFilters: () => {
        selectedSupplier.value = null;
        selectedSource.value = null;
    },
    listErrorLabel: 'laporan diskon pembelian',
    defaultSortField: 'tanggal_po'
});

const suppliers = computed(() => dropdowns.value.suppliers ?? []);

function formatDisc(tipe, nilai, hasil, arrow = '\u2192') {
    if (!tipe || tipe === 'none' || !hasil || parseFloat(hasil) === 0) return '-';
    const prefix = tipe === 'percent' ? `${parseFloat(nilai)}%` : formatCurrency(nilai);
    return `${prefix} ${arrow} ${formatCurrency(hasil)}`;
}

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'tanggal_po', sort_order: 'desc' };
    let allData;
    try {
        const response = await purchaseReportApi.getDiskon(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.tanggal_po) },
        { header: 'No. Dokumen', field: 'nomor_dokumen', width: 28 },
        { header: 'Supplier', field: 'nama_supplier', width: 30 },
        { header: 'Subtotal', width: 22, align: 'right', accessor: (row) => formatCurrency(row.subtotal) },
        { header: 'Disc 1', width: 24, align: 'right', accessor: (row) => formatDisc(row.diskon_1_tipe, row.diskon_1_nilai, row.diskon_1_hasil, '->') },
        { header: 'Disc 2', width: 24, align: 'right', accessor: (row) => formatDisc(row.diskon_2_tipe, row.diskon_2_nilai, row.diskon_2_hasil, '->') },
        { header: 'Disc 3', width: 24, align: 'right', accessor: (row) => formatDisc(row.diskon_3_tipe, row.diskon_3_nilai, row.diskon_3_hasil, '->') },
        { header: 'Total Diskon', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_diskon_header) },
        { header: 'Total Stlh Diskon', width: 24, align: 'right', accessor: (row) => formatCurrency(row.total_setelah_diskon) }
    ];

    exportListPdf({
        title: 'Laporan Diskon Pembelian',
        filename: `laporan_diskon_pembelian_${todayString()}`,
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
                <span class="text-xl font-semibold">Laporan Diskon Pembelian</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <Select v-model="selectedSupplier" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Supplier" class="w-40" filter showClear @change="onFilterChange" />
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
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Subtotal</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(summary.total_subtotal) }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                <div class="text-red-600 dark:text-red-400 text-sm mb-1">Total Diskon</div>
                <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ formatCurrency(summary.total_diskon) }}</div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-blue-600 dark:text-blue-400 text-sm mb-1">Total Setelah Diskon</div>
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(summary.total_setelah_diskon) }}</div>
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
                <DataTableHeader v-model="searchQuery" title="Diskon Pembelian" placeholder="Cari nomor dokumen..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <div class="flex gap-2">
                            <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                            <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                        </div>
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data diskon pembelian</div>
            </template>

            <Column field="tanggal_po" header="Tanggal" sortable style="min-width: 160px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal_po) }}
                </template>
            </Column>

            <Column field="nomor_dokumen" header="No. Dokumen" sortable style="min-width: 180px">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.nomor_dokumen }}</span>
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

            <Column field="subtotal" header="Subtotal" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.subtotal) }}
                </template>
            </Column>

            <Column field="diskon_1" header="Disc 1" style="min-width: 150px" class="text-right">
                <template #body="{ data }">
                    {{ formatDisc(data.diskon_1_tipe, data.diskon_1_nilai, data.diskon_1_hasil) }}
                </template>
            </Column>

            <Column field="diskon_2" header="Disc 2" style="min-width: 150px" class="text-right">
                <template #body="{ data }">
                    {{ formatDisc(data.diskon_2_tipe, data.diskon_2_nilai, data.diskon_2_hasil) }}
                </template>
            </Column>

            <Column field="diskon_3" header="Disc 3" style="min-width: 150px" class="text-right">
                <template #body="{ data }">
                    {{ formatDisc(data.diskon_3_tipe, data.diskon_3_nilai, data.diskon_3_hasil) }}
                </template>
            </Column>

            <Column field="total_diskon_header" header="Total Diskon" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    <span class="text-red-600 dark:text-red-400 font-medium">{{ formatCurrency(data.total_diskon_header) }}</span>
                </template>
            </Column>

            <Column field="total_setelah_diskon" header="Total Stlh Diskon" sortable style="min-width: 150px" class="text-right">
                <template #body="{ data }">
                    <span class="font-medium">{{ formatCurrency(data.total_setelah_diskon) }}</span>
                </template>
            </Column>
        </DataTable>
    </div>
</template>

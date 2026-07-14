<script setup>
import { ref, computed } from 'vue';
import { salesFinancialReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportList } from '@/composables/useReportList';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const { formatCurrency, formatDateTime, todayString, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();
const canExport = computed(() => authStore.can('laporan.export'));

const selectedTerminal = ref(null);
const selectedTipe = ref(null);
const tipeOptions = [
    { label: 'Semua', value: null },
    { label: 'Penjualan', value: 'Penjualan' },
    { label: 'Retur', value: 'Retur' }
];

const { items, loading, totalRecords, summary, searchQuery, startDate, endDate, lazyParams, dropdowns, exportingExcel, exportExcel, onPage, onSort, doSearch, clearSearch, onFilterChange, resetFilters, buildFilterParams } = useReportList({
    fetchList: (params) => salesFinancialReportApi.getPembulatan(params),
    exportFn: salesFinancialReportApi.exportPembulatan,
    exportFilenamePrefix: 'laporan_pembulatan',
    fetchDropdowns: salesFinancialReportApi.getDropdowns,
    getExtraFilters: () => ({
        terminal_id: selectedTerminal.value,
        tipe: selectedTipe.value
    }),
    onResetFilters: () => {
        selectedTerminal.value = null;
        selectedTipe.value = null;
    },
    defaultSortField: 'tanggal'
});

const terminals = computed(() => dropdowns.value.terminals ?? []);

function getTipeSeverity(tipe) {
    return tipe === 'Penjualan' ? 'success' : 'danger';
}

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'tanggal', sort_order: 'desc' };
    let allData;
    try {
        const response = await salesFinancialReportApi.getPembulatan(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    exportListPdf({
        title: 'Laporan Pembulatan',
        filename: `laporan_pembulatan_${todayString()}`,
        columns: [
            { header: 'No', field: '#', width: 8, align: 'center' },
            { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.tanggal) },
            { header: 'No. Dokumen', field: 'nomor_dokumen', width: 28 },
            { header: 'Tipe', field: 'tipe', width: 16 },
            { header: 'Terminal', field: 'nama_terminal', width: 20 },
            { header: 'Grand Total', width: 22, align: 'right', accessor: (row) => formatCurrency(row.grand_total) },
            { header: 'Pembulatan', width: 20, align: 'right', accessor: (row) => formatCurrency(row.pembulatan) }
        ],
        data: allData,
        totalLabel: `Total: ${allData.length} transaksi`
    });
}
</script>

<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Laporan Pembulatan</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <Select v-model="selectedTerminal" :options="terminals" optionLabel="nama_terminal" optionValue="id" placeholder="Terminal" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedTipe" :options="tipeOptions" optionLabel="label" optionValue="value" placeholder="Tipe" class="w-36" @change="onFilterChange" />
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
                <div class="text-surface-500 text-sm mb-1">Jumlah Transaksi</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ summary.jumlah_transaksi }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Pembulatan Penjualan</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(summary.total_pembulatan_penjualan) }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Pembulatan Retur</div>
                <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ formatCurrency(summary.total_pembulatan_retur) }}</div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-blue-600 dark:text-blue-400 text-sm mb-1">Net Pembulatan</div>
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(summary.net_pembulatan) }}</div>
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
            dataKey="nomor_dokumen"
            @page="onPage"
            @sort="onSort"
            stripedRows
            showGridlines
            scrollable
        >
            <template #header>
                <DataTableHeader v-model="searchQuery" title="Pembulatan" placeholder="Cari nomor dokumen..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                        <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data pembulatan</div>
            </template>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 160px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <Column field="nomor_dokumen" header="No. Dokumen" sortable style="min-width: 180px">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tipe" header="Tipe" sortable style="min-width: 120px">
                <template #body="{ data }">
                    <Tag :value="data.tipe" :severity="getTipeSeverity(data.tipe)" />
                </template>
            </Column>

            <Column field="nama_terminal" header="Terminal" style="min-width: 140px">
                <template #body="{ data }">
                    {{ data.nama_terminal }}
                </template>
            </Column>

            <Column field="grand_total" header="Grand Total" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.grand_total) }}
                </template>
            </Column>

            <Column field="pembulatan" header="Pembulatan" sortable style="min-width: 130px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.pembulatan) }}
                </template>
            </Column>
        </DataTable>
    </div>
</template>

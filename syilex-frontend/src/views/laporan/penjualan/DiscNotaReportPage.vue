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

const { items, loading, totalRecords, summary, searchQuery, startDate, endDate, lazyParams, dropdowns, exportingExcel, exportExcel, onPage, onSort, doSearch, clearSearch, onFilterChange, resetFilters, buildFilterParams } = useReportList({
    fetchList: (params) => salesFinancialReportApi.getDiscNota(params),
    exportFn: salesFinancialReportApi.exportDiscNota,
    exportFilenamePrefix: 'laporan_disc_nota',
    fetchDropdowns: salesFinancialReportApi.getDropdowns,
    listErrorLabel: 'laporan disc nota',
    getExtraFilters: () => ({ terminal_id: selectedTerminal.value }),
    onResetFilters: () => {
        selectedTerminal.value = null;
    },
    defaultSortField: 'tanggal'
});

const terminals = computed(() => dropdowns.value.terminals ?? []);

function formatDisc(tipe, nilai, hasil) {
    if (!tipe || tipe === 'none' || !hasil || parseFloat(hasil) === 0) return '-';
    const prefix = tipe === 'percent' ? `${parseFloat(nilai)}%` : formatCurrency(nilai);
    return `${prefix} \u2192 ${formatCurrency(hasil)}`;
}

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'tanggal', sort_order: 'desc' };
    let allData;
    try {
        const response = await salesFinancialReportApi.getDiscNota(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    exportListPdf({
        title: 'Laporan Disc Nota',
        filename: `laporan_disc_nota_${todayString()}`,
        columns: [
            { header: 'No', field: '#', width: 8, align: 'center' },
            { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.tanggal) },
            { header: 'No. Invoice', field: 'nomor_dokumen', width: 28 },
            { header: 'Terminal', field: 'nama_terminal', width: 20 },
            { header: 'Subtotal', width: 22, align: 'right', accessor: (row) => formatCurrency(row.subtotal) },
            { header: 'Disc Nota 1', width: 24, align: 'right', accessor: (row) => formatDisc(row.diskon_nota_1_tipe, row.diskon_nota_1_nilai, row.diskon_nota_1_hasil) },
            { header: 'Disc Nota 2', width: 24, align: 'right', accessor: (row) => formatDisc(row.diskon_nota_2_tipe, row.diskon_nota_2_nilai, row.diskon_nota_2_hasil) },
            { header: 'Disc Nota 3', width: 24, align: 'right', accessor: (row) => formatDisc(row.diskon_nota_3_tipe, row.diskon_nota_3_nilai, row.diskon_nota_3_hasil) },
            { header: 'Total Diskon', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_diskon) },
            { header: 'Total Stlh Diskon', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_setelah_diskon) }
        ],
        data: allData,
        totalLabel: `Total: ${allData.length} nota`
    });
}
</script>

<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Laporan Disc Nota</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <Select v-model="selectedTerminal" :options="terminals" optionLabel="nama_terminal" optionValue="id" placeholder="Terminal" class="w-40" filter showClear @change="onFilterChange" />
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
                <div class="text-surface-500 text-sm mb-1">Jumlah Nota</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ summary.jumlah_nota }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Subtotal</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(summary.total_subtotal) }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                <div class="text-red-600 dark:text-red-400 text-sm mb-1">Total Diskon Nota</div>
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
            dataKey="nomor_dokumen"
            @page="onPage"
            @sort="onSort"
            stripedRows
            showGridlines
            scrollable
        >
            <template #header>
                <DataTableHeader v-model="searchQuery" title="Disc Nota" placeholder="Cari nomor invoice..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                        <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data disc nota</div>
            </template>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 160px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <Column field="nomor_dokumen" header="No. Invoice" sortable style="min-width: 180px">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="nama_terminal" header="Terminal" style="min-width: 140px">
                <template #body="{ data }">
                    {{ data.nama_terminal }}
                </template>
            </Column>

            <Column field="subtotal" header="Subtotal" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.subtotal) }}
                </template>
            </Column>

            <Column field="diskon_nota_1" header="Disc Nota 1" style="min-width: 180px" class="text-right">
                <template #body="{ data }">
                    <div v-if="data.diskon_nota_1_label" class="text-xs text-surface-500">{{ data.diskon_nota_1_label }}</div>
                    {{ formatDisc(data.diskon_nota_1_tipe, data.diskon_nota_1_nilai, data.diskon_nota_1_hasil) }}
                </template>
            </Column>

            <Column field="diskon_nota_2" header="Disc Nota 2" style="min-width: 180px" class="text-right">
                <template #body="{ data }">
                    <div v-if="data.diskon_nota_2_label" class="text-xs text-surface-500">{{ data.diskon_nota_2_label }}</div>
                    {{ formatDisc(data.diskon_nota_2_tipe, data.diskon_nota_2_nilai, data.diskon_nota_2_hasil) }}
                </template>
            </Column>

            <Column field="diskon_nota_3" header="Disc Nota 3" style="min-width: 180px" class="text-right">
                <template #body="{ data }">
                    <div v-if="data.diskon_nota_3_label" class="text-xs text-surface-500">{{ data.diskon_nota_3_label }}</div>
                    {{ formatDisc(data.diskon_nota_3_tipe, data.diskon_nota_3_nilai, data.diskon_nota_3_hasil) }}
                </template>
            </Column>

            <Column field="total_diskon" header="Total Diskon" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    <span class="text-red-600 dark:text-red-400 font-medium">{{ formatCurrency(data.total_diskon) }}</span>
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

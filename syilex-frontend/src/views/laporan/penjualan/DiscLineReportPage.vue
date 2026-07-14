<script setup>
import { ref, computed } from 'vue';
import { salesFinancialReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportList } from '@/composables/useReportList';
import { useReportDetailDialog } from '@/composables/useReportDetailDialog';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import DetailTable from '@/components/common/DetailTable.vue';

const authStore = useAuthStore();
const { formatCurrency, formatQty, formatDateTime, todayString, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportListPdf } = useExportPdf();
const canExport = computed(() => authStore.can('laporan.export'));

const selectedTerminal = ref(null);

const { items, loading, totalRecords, summary, searchQuery, startDate, endDate, lazyParams, dropdowns, exportingExcel, exportExcel, onPage, onSort, doSearch, clearSearch, onFilterChange, resetFilters, buildFilterParams } = useReportList({
    fetchList: (params) => salesFinancialReportApi.getDiscLine(params),
    exportFn: salesFinancialReportApi.exportDiscLine,
    exportFilenamePrefix: 'laporan_disc_line',
    fetchDropdowns: salesFinancialReportApi.getDropdowns,
    listErrorLabel: 'laporan disc line',
    getExtraFilters: () => ({ terminal_id: selectedTerminal.value }),
    onResetFilters: () => {
        selectedTerminal.value = null;
    },
    defaultSortField: 'tanggal'
});

const terminals = computed(() => dropdowns.value.terminals ?? []);

const {
    detailDialog,
    loadingDetail,
    detailMeta: detailSale,
    detailItems,
    detailSummary,
    openDetail: openDiscLineDetail
} = useReportDetailDialog({
    paginated: false,
    fetchDetail: (row) => salesFinancialReportApi.getDiscLineDetail(row.ulid),
    parseResponse: (data) => ({
        meta: data.sale,
        items: data.items,
        summary: data.summary
    }),
    errorLabel: 'detail disc line'
});

const detailColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'produk', header: 'Produk', width: '200px' },
    { field: 'qty', header: 'Qty', align: 'right', width: '70px' },
    { field: 'harga_satuan', header: 'Harga Satuan', align: 'right', width: '120px' },
    { field: 'bruto', header: 'Bruto', align: 'right', width: '120px' },
    { field: 'disc1', header: 'Disc 1', align: 'right', width: '130px' },
    { field: 'disc2', header: 'Disc 2', align: 'right', width: '130px' },
    { field: 'disc3', header: 'Disc 3', align: 'right', width: '130px' },
    { field: 'disc4', header: 'Disc 4', align: 'right', width: '130px' },
    { field: 'disc5', header: 'Disc 5', align: 'right', width: '130px' },
    { field: 'diskon_total', header: 'Total Disc', align: 'right', width: '120px' },
    { field: 'jumlah', header: 'Jumlah', align: 'right', width: '120px' }
];

function formatDisc(tipe, nilai, hasil) {
    if (!tipe || tipe === 'none' || !hasil || parseFloat(hasil) === 0) return '-';
    const prefix = tipe === 'percent' ? `${parseFloat(nilai)}%` : formatCurrency(nilai);
    return `${prefix} \u2192 ${formatCurrency(hasil)}`;
}

async function viewDetail(row) {
    await openDiscLineDetail(row);
}

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'tanggal', sort_order: 'desc' };
    let allData;
    try {
        const response = await salesFinancialReportApi.getDiscLine(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    exportListPdf({
        title: 'Laporan Disc Line',
        filename: `laporan_disc_line_${todayString()}`,
        columns: [
            { header: 'No', field: '#', width: 8, align: 'center' },
            { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.tanggal) },
            { header: 'No. Invoice', field: 'nomor_dokumen', width: 28 },
            { header: 'Terminal', field: 'nama_terminal', width: 20 },
            { header: 'Jml Item', width: 14, align: 'right', accessor: (row) => String(row.jumlah_item) },
            { header: 'Total Bruto', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_bruto) },
            { header: 'Total Disc Line', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_disc_line) },
            { header: 'Total Stlh Disc', width: 22, align: 'right', accessor: (row) => formatCurrency(row.total_setelah_disc) }
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
                <span class="text-xl font-semibold">Laporan Disc Line</span>
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
                <div class="text-surface-500 text-sm mb-1">Total Bruto</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(summary.total_bruto) }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                <div class="text-red-600 dark:text-red-400 text-sm mb-1">Total Disc Line</div>
                <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ formatCurrency(summary.total_disc_line) }}</div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-blue-600 dark:text-blue-400 text-sm mb-1">Total Setelah Disc</div>
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(summary.total_setelah_disc) }}</div>
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
                <DataTableHeader v-model="searchQuery" title="Disc Line" placeholder="Cari nomor invoice..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                        <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data disc line</div>
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

            <Column field="jumlah_item" header="Jml Item" style="min-width: 100px" class="text-right">
                <template #body="{ data }">
                    {{ data.jumlah_item }}
                </template>
            </Column>

            <Column field="total_bruto" header="Total Bruto" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.total_bruto) }}
                </template>
            </Column>

            <Column field="total_disc_line" header="Total Disc Line" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    <span class="text-red-600 dark:text-red-400">{{ formatCurrency(data.total_disc_line) }}</span>
                </template>
            </Column>

            <Column field="total_setelah_disc" header="Total Stlh Disc" sortable style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.total_setelah_disc) }}
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
        <Dialog v-model:visible="detailDialog" modal header="Detail Disc Line" :style="{ width: '1100px' }">
            <div v-if="loadingDetail" class="flex justify-center py-8">
                <ProgressSpinner style="width: 50px; height: 50px" />
            </div>

            <template v-else-if="detailSale.nomor_dokumen">
                <!-- Sale Info -->
                <div class="grid grid-cols-3 gap-4 mb-4">
                    <div>
                        <span class="text-surface-500 text-sm">No. Invoice</span>
                        <div class="font-mono font-medium">{{ detailSale.nomor_dokumen }}</div>
                    </div>
                    <div>
                        <span class="text-surface-500 text-sm">Tanggal</span>
                        <div>{{ formatDateTime(detailSale.tanggal) }}</div>
                    </div>
                    <div>
                        <span class="text-surface-500 text-sm">Terminal</span>
                        <div>{{ detailSale.terminal }}</div>
                    </div>
                </div>

                <Divider />

                <!-- Summary -->
                <div class="grid grid-cols-3 gap-4 mb-4">
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Total Bruto</div>
                        <div class="text-xl font-bold">{{ formatCurrency(detailSummary.total_bruto) }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-red-600 dark:text-red-400 text-sm">Total Disc</div>
                        <div class="text-xl font-bold text-red-600 dark:text-red-400">{{ formatCurrency(detailSummary.total_disc) }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-blue-600 dark:text-blue-400 text-sm">Total Jumlah</div>
                        <div class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(detailSummary.total_jumlah) }}</div>
                    </div>
                </div>

                <Divider />

                <!-- Detail Table -->
                <h6 class="font-semibold mb-3">Detail Item ({{ detailItems.length }} item)</h6>
                <DetailTable :data="detailItems" :columns="detailColumns">
                    <template #produk="{ item }">
                        <span class="font-medium">{{ item.kode_produk }}</span>
                        <br />
                        <span class="text-surface-500 text-sm">{{ item.nama_produk }}</span>
                    </template>
                    <template #qty="{ item }"> {{ formatQty(item.qty) }} {{ item.unit }} </template>
                    <template #harga_satuan="{ item }">
                        {{ formatCurrency(item.harga_satuan) }}
                    </template>
                    <template #bruto="{ item }">
                        {{ formatCurrency(item.bruto) }}
                    </template>
                    <template #disc1="{ item }">
                        {{ formatDisc(item.diskon_1_tipe, item.diskon_1_nilai, item.diskon_1_hasil) }}
                    </template>
                    <template #disc2="{ item }">
                        {{ formatDisc(item.diskon_2_tipe, item.diskon_2_nilai, item.diskon_2_hasil) }}
                    </template>
                    <template #disc3="{ item }">
                        {{ formatDisc(item.diskon_3_tipe, item.diskon_3_nilai, item.diskon_3_hasil) }}
                    </template>
                    <template #disc4="{ item }">
                        {{ formatDisc(item.diskon_4_tipe, item.diskon_4_nilai, item.diskon_4_hasil) }}
                    </template>
                    <template #disc5="{ item }">
                        {{ formatDisc(item.diskon_5_tipe, item.diskon_5_nilai, item.diskon_5_hasil) }}
                    </template>
                    <template #diskon_total="{ item }">
                        <span class="text-red-600 dark:text-red-400 font-medium">{{ formatCurrency(item.diskon_total) }}</span>
                    </template>
                    <template #jumlah="{ item }">
                        <span class="font-medium">{{ formatCurrency(item.jumlah) }}</span>
                    </template>
                </DetailTable>
            </template>

            <template #footer>
                <Button label="Tutup" icon="pi pi-times" text @click="detailDialog = false" />
            </template>
        </Dialog>
    </div>
</template>

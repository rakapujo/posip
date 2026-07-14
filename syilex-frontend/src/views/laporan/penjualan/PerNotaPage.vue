<script setup>
import { ref, computed, onMounted } from 'vue';
import { salesReportApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useReportList } from '@/composables/useReportList';
import { useReportDetailDialog } from '@/composables/useReportDetailDialog';
import { useExportPdf } from '@/composables/useExportPdf';
import { useReceiptPdf } from '@/composables/useReceiptPdf';
import { usePrintAdapter } from '@/composables/print/usePrintAdapter';
import { useReceiptEscPos } from '@/composables/useReceiptEscPos';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const { formatCurrency, formatQty, formatPercent, formatDateTime, todayString, getPrimeDateFormatShort } = useFormatters();
const notify = useNotification();
const settingsStore = useSettingsStore();
const { formatDiscLine, downloadReceiptPdf, printReceiptPdf } = useReceiptPdf();
const printAdapter = usePrintAdapter();
const escpos = useReceiptEscPos();
const { exporting, exportListPdf } = useExportPdf();
const canExport = computed(() => authStore.can('laporan.export'));

const selectedTerminal = ref(null);
const selectedUser = ref(null);
const selectedMetodeBayar = ref(null);
const selectedStatus = ref(null);
const statusOptions = [
    { label: 'Selesai', value: 'completed' },
    { label: 'Void', value: 'voided' },
    { label: 'Retur Sebagian', value: 'retur_partial' },
    { label: 'Retur Penuh', value: 'retur_full' }
];

const { items, loading, totalRecords, searchQuery, lazyParams, startDate, endDate, dropdowns, exportingExcel, exportExcel, onPage, onSort, doSearch, clearSearch, onFilterChange, resetFilters, buildFilterParams } = useReportList({
    fetchList: (params) => salesReportApi.getAll(params),
    exportFn: salesReportApi.exportExcel,
    exportFilenameFn: () => `laporan_penjualan_per_nota_${todayString()}.xlsx`,
    fetchDropdowns: salesReportApi.getDropdowns,
    getExtraFilters: () => ({
        terminal_id: selectedTerminal.value,
        user_id: selectedUser.value,
        metode_bayar_id: selectedMetodeBayar.value,
        status: selectedStatus.value
    }),
    onResetFilters: () => {
        selectedTerminal.value = null;
        selectedUser.value = null;
        selectedMetodeBayar.value = null;
        selectedStatus.value = null;
    },
    listErrorLabel: 'penjualan',
    defaultSortField: 'tanggal'
});

const terminals = computed(() => dropdowns.value.terminals ?? []);
const users = computed(() => dropdowns.value.users ?? []);
const metodeBayar = computed(() => dropdowns.value.metode_bayar ?? []);

const {
    detailDialog,
    loadingDetail,
    detailMeta: detailData,
    openDetail: openSaleDetail
} = useReportDetailDialog({
    paginated: false,
    fetchDetail: (row) => salesReportApi.get(row.ulid),
    parseResponse: (data) => ({
        meta: data.sales,
        items: data.sales?.details ?? []
    }),
    errorLabel: 'penjualan'
});

async function viewDetail(data) {
    await openSaleDetail(data);
}

async function exportPdf() {
    const params = { ...buildFilterParams(), page: 1, per_page: 999999, sort_field: 'tanggal', sort_order: 'desc' };
    let allData;
    try {
        const response = await salesReportApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.items;
    } catch {
        return;
    }

    exportListPdf({
        title: 'Laporan Penjualan Per Nota',
        filename: `laporan_penjualan_per_nota_${todayString()}`,
        columns: [
            { header: 'No', field: '#', width: 8, align: 'center' },
            { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.tanggal) },
            { header: 'No. Invoice', field: 'nomor_dokumen', width: 28 },
            { header: 'Terminal', width: 18, accessor: (row) => row.terminal?.kode_terminal || '-' },
            { header: 'Kasir', width: 22, accessor: (row) => row.created_by?.name || '-' },
            { header: 'Customer', width: 28, accessor: (row) => row.customer?.nama || 'Walk-in' },
            { header: 'Grand Total', width: 22, align: 'right', accessor: (row) => formatCurrency(row.grand_total) },
            { header: 'Status', width: 18, accessor: (row) => salesStatusLabel(row.receipt_status) }
        ],
        data: allData,
        totalLabel: `Total: ${allData.length} nota`
    });
}

// ─── Status Helpers (override composable defaults for sales-specific statuses) ───
const salesStatusSeverity = (status) => {
    const map = {
        completed: 'success',
        voided: 'danger',
        retur_partial: 'warn',
        retur_full: 'danger'
    };
    return map[status] || 'secondary';
};

const salesStatusLabel = (status) => {
    const map = {
        completed: 'Selesai',
        voided: 'Void',
        retur_partial: 'Retur Sebagian',
        retur_full: 'Retur Penuh'
    };
    return map[status] || status;
};

// ─── Detail Table Columns ───
const detailColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'product', header: 'Produk' },
    { field: 'unit', header: 'Unit', width: '80px' },
    { field: 'qty', header: 'Qty', align: 'right', width: '80px' },
    { field: 'harga', header: 'Harga', align: 'right', width: '120px' },
    { field: 'diskon', header: 'Diskon', align: 'right', width: '100px' },
    { field: 'total', header: 'Total', align: 'right', width: '120px' }
];

const paymentColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'metode', header: 'Metode' },
    { field: 'nominal', header: 'Nominal', align: 'right', width: '140px' },
    { field: 'referensi', header: 'Referensi', width: '150px' },
    { field: 'biaya', header: 'Biaya', align: 'right', width: '120px' }
];

const returDetailColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'product', header: 'Produk' },
    { field: 'qty', header: 'Qty', align: 'right', width: '80px' },
    { field: 'harga', header: 'Harga', align: 'right', width: '120px' },
    { field: 'total', header: 'Total', align: 'right', width: '120px' }
];

// ─── Helpers ───
const getPaymentBadges = (payments) => {
    if (!payments?.length) return [];
    const uniqueMethods = [...new Set(payments.map((p) => p.metode_pembayaran?.nama_pembayaran).filter(Boolean))];
    return uniqueMethods;
};

const totalReturns = computed(() => {
    if (!detailData.value?.returns?.length) return 0;
    return detailData.value.returns.reduce((sum, r) => sum + Number(r.grand_total), 0);
});

const nilaiBersih = computed(() => {
    return Number(detailData.value?.grand_total || 0) - totalReturns.value;
});

// ─── Fetch full sales data for PDF (list items don't have details) ───
const fetchFullSales = async (ulid) => {
    try {
        const response = await salesReportApi.get(ulid);
        if (response.data.success) {
            return response.data.data.sales;
        }
    } catch {
        notify.error('Gagal memuat data penjualan');
    }
    return null;
};

// ─── Direct Thermal Print Helper ───
async function tryDirectPrintReceipt(salesData) {
    await printAdapter.reconnect();
    const bytes = escpos.buildReceipt(salesData, { charWidth: 42, feedLines: 4, compact: false });
    const result = await printAdapter.printRaw(bytes);
    return result.success;
}

// ─── Actions ───
const handlePrint = async (data) => {
    const fullData = await fetchFullSales(data.ulid);
    if (!fullData) return;
    if (printAdapter.supported.value || printAdapter.isAvailable.value) {
        const ok = await tryDirectPrintReceipt(fullData);
        if (ok) return;
    }
    printReceiptPdf(fullData);
};

const handleDownloadPdf = async (data) => {
    const fullData = await fetchFullSales(data.ulid);
    if (fullData) downloadReceiptPdf(fullData);
};

const handleCopyUrl = async (data) => {
    const baseUrl = (settingsStore.store.url || '').replace(/\/+$/, '') || window.location.origin;
    const url = `${baseUrl}/struk-online/${data.ulid}`;
    try {
        await navigator.clipboard.writeText(url);
        notify.success('URL struk berhasil disalin');
    } catch {
        notify.error('Gagal menyalin URL');
    }
};

// For detail dialog actions, we need the full data loaded
const handleDetailPrint = async () => {
    if (!detailData.value?.ulid) return;
    if (printService.isAvailable.value) {
        const ok = await tryDirectPrintReceipt(detailData.value);
        if (ok) return;
    }
    printReceiptPdf(detailData.value);
};

const handleDetailPdf = () => {
    if (detailData.value?.ulid) downloadReceiptPdf(detailData.value);
};

const handleDetailCopyUrl = () => {
    if (detailData.value?.ulid) handleCopyUrl(detailData.value);
};

onMounted(() => {
    printService.checkStatus();
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Penjualan per Nota</span>
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="selectedTerminal" :options="terminals" optionLabel="nama_terminal" optionValue="id" placeholder="Terminal" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedUser" :options="users" optionLabel="name" optionValue="id" placeholder="Kasir" class="w-36" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedMetodeBayar" :options="metodeBayar" optionLabel="nama_pembayaran" optionValue="id" placeholder="Metode Bayar" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-32" showClear @change="onFilterChange" />
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

        <!-- DataTable -->
        <DataTable
            :value="items"
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
                <DataTableHeader v-model="searchQuery" title="Daftar Penjualan" placeholder="Cari no. invoice, customer..." @search="doSearch" @clear="clearSearch">
                    <template v-if="canExport" #extra>
                        <Button icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                        <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data penjualan</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="No. Invoice" sortable style="min-width: 160px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 160px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <Column header="Terminal" style="min-width: 120px">
                <template #body="{ data }">
                    {{ data.terminal?.kode_terminal || '-' }}
                </template>
            </Column>

            <Column header="Kasir" style="min-width: 120px">
                <template #body="{ data }">
                    {{ data.created_by?.name || '-' }}
                </template>
            </Column>

            <Column header="Customer" style="min-width: 150px">
                <template #body="{ data }">
                    {{ data.customer?.nama || 'Walk-in' }}
                </template>
            </Column>

            <Column field="grand_total" header="Grand Total" sortable style="min-width: 140px" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-semibold">{{ formatCurrency(data.grand_total) }}</span>
                </template>
            </Column>

            <Column header="Bayar" style="min-width: 140px">
                <template #body="{ data }">
                    <div class="flex flex-wrap gap-1">
                        <Tag v-for="method in getPaymentBadges(data.payments)" :key="method" :value="method" severity="info" class="text-xs" />
                    </div>
                </template>
            </Column>

            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="salesStatusLabel(data.receipt_status)" :severity="salesStatusSeverity(data.receipt_status)" />
                </template>
            </Column>

            <Column header="Aksi" style="min-width: 150px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <div class="flex gap-1">
                        <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" />
                        <Button icon="pi pi-print" severity="secondary" text rounded @click="handlePrint(data)" v-tooltip.top="'Print Struk'" />
                        <Button icon="pi pi-file-pdf" severity="warn" text rounded @click="handleDownloadPdf(data)" v-tooltip.top="'Download PDF'" />
                        <Button icon="pi pi-link" severity="secondary" text rounded @click="handleCopyUrl(data)" v-tooltip.top="'Copy URL Struk'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog v-model:visible="detailDialog" title="Detail Penjualan" :loading="loadingDetail" :created-at="detailData.created_at" :created-by="detailData.created_by?.name" width="950px">
            <template #content>
                <div v-if="detailData.ulid">
                    <!-- Header Info -->
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                        <DetailItem label="No. Invoice" :value="detailData.nomor_dokumen" />
                        <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal)" />
                        <DetailItem label="Terminal" :value="detailData.terminal?.nama_terminal" />
                        <DetailItem label="Kasir" :value="detailData.created_by?.name" />
                        <DetailItem label="Customer" :value="detailData.customer?.nama || 'Walk-in'" />
                        <DetailItem label="Status" :value="salesStatusLabel(detailData.receipt_status)" type="badge" :badge-severity="salesStatusSeverity(detailData.receipt_status)" />
                    </div>

                    <!-- Void info -->
                    <div v-if="detailData.receipt_status === 'voided'" class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                        <div class="flex items-center gap-2 text-red-600 dark:text-red-400 text-sm">
                            <i class="pi pi-times-circle"></i>
                            <span class="font-medium">Void oleh {{ detailData.voided_by?.name || '-' }}</span>
                        </div>
                        <div v-if="detailData.void_reason" class="text-sm text-red-500 dark:text-red-400 mt-1 ml-6">Alasan: {{ detailData.void_reason }}</div>
                        <div v-if="detailData.voided_at" class="text-xs text-red-400 mt-1 ml-6">{{ formatDateTime(detailData.voided_at) }}</div>
                    </div>

                    <!-- Items Table -->
                    <div class="mt-4">
                        <h4 class="text-lg font-medium mb-3">Detail Item ({{ detailData.details?.length || 0 }} produk)</h4>
                        <DetailTable :data="detailData.details" :columns="detailColumns">
                            <template #product="{ item }">
                                <span class="font-medium">{{ item.product?.kode_produk }}</span>
                                <br />
                                <span class="text-surface-500 text-sm">{{ item.product?.nama_produk }}</span>
                                <div v-if="item.serial_units?.length" class="mt-1 space-y-0.5">
                                    <div v-for="(u, ui) in item.serial_units" :key="ui" class="text-xs text-surface-400 leading-snug">
                                        <template v-if="u.kode_internal">{{ u.kode_internal }} · </template>SN {{ u.serial_number }}<template v-if="u.grade"> ({{ u.grade }})</template
                                        ><template v-if="u.battery_health || u.battery_condition">
                                            · 🔋{{ u.battery_health }}%<template v-if="u.battery_condition"> {{ u.battery_condition }}</template></template
                                        ><template v-if="u.account_status"> · {{ u.account_status }}</template
                                        ><template v-if="u.catatan"> · {{ u.catatan }}</template>
                                    </div>
                                </div>
                            </template>
                            <template #unit="{ item }">{{ item.unit }}</template>
                            <template #qty="{ item }">{{ formatQty(item.qty) }}</template>
                            <template #harga="{ item }">{{ formatCurrency(item.harga_satuan) }}</template>
                            <template #diskon="{ item }">
                                <template v-if="Number(item.diskon_total) > 0">
                                    <div>{{ formatCurrency(item.diskon_total) }}</div>
                                    <div class="text-xs text-surface-400">{{ formatDiscLine(item) }}</div>
                                </template>
                                <span v-else>-</span>
                            </template>
                            <template #total="{ item }">
                                <span class="font-medium">{{ formatCurrency(item.jumlah) }}</span>
                            </template>
                        </DetailTable>
                    </div>

                    <!-- Summary -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                        <div></div>
                        <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4 space-y-2">
                            <div class="flex justify-between">
                                <span>Subtotal</span>
                                <span>{{ formatCurrency(detailData.subtotal) }}</span>
                            </div>
                            <div v-if="Number(detailData.diskon_nota_1_hasil) > 0" class="flex justify-between text-red-500">
                                <span>{{ detailData.diskon_nota_1_label || 'Disc 1' }} ({{ detailData.diskon_nota_1_tipe === 'percent' ? formatPercent(detailData.diskon_nota_1_nilai) : formatCurrency(detailData.diskon_nota_1_nilai) }})</span>
                                <span>-{{ formatCurrency(detailData.diskon_nota_1_hasil) }}</span>
                            </div>
                            <div v-if="Number(detailData.diskon_nota_2_hasil) > 0" class="flex justify-between text-red-500">
                                <span>{{ detailData.diskon_nota_2_label || 'Disc 2' }} ({{ detailData.diskon_nota_2_tipe === 'percent' ? formatPercent(detailData.diskon_nota_2_nilai) : formatCurrency(detailData.diskon_nota_2_nilai) }})</span>
                                <span>-{{ formatCurrency(detailData.diskon_nota_2_hasil) }}</span>
                            </div>
                            <div v-if="Number(detailData.diskon_nota_3_hasil) > 0" class="flex justify-between text-red-500">
                                <span>{{ detailData.diskon_nota_3_label || 'Disc Manual' }} ({{ detailData.diskon_nota_3_tipe === 'percent' ? formatPercent(detailData.diskon_nota_3_nilai) : formatCurrency(detailData.diskon_nota_3_nilai) }})</span>
                                <span>-{{ formatCurrency(detailData.diskon_nota_3_hasil) }}</span>
                            </div>
                            <div v-if="Number(detailData.total_diskon) > 0" class="flex justify-between">
                                <span>Total Setelah Diskon</span>
                                <span>{{ formatCurrency(detailData.total_setelah_diskon) }}</span>
                            </div>
                            <div v-if="Number(detailData.biaya_kirim_hasil) > 0" class="flex justify-between">
                                <span>Biaya Kirim</span>
                                <span>{{ formatCurrency(detailData.biaya_kirim_hasil) }}</span>
                            </div>
                            <div v-if="Number(detailData.biaya_lain_hasil) > 0" class="flex justify-between">
                                <span>Biaya Lain</span>
                                <span>{{ formatCurrency(detailData.biaya_lain_hasil) }}</span>
                            </div>
                            <div v-if="Number(detailData.pajak_nominal) > 0" class="flex justify-between">
                                <span>DPP</span>
                                <span>{{ formatCurrency(detailData.dpp) }}</span>
                            </div>
                            <div v-if="Number(detailData.pajak_nominal) > 0" class="flex justify-between">
                                <span>{{ detailData.pajak_nama }} {{ detailData.pajak_persen }}%</span>
                                <span>{{ formatCurrency(detailData.pajak_nominal) }}</span>
                            </div>
                            <div v-if="Number(detailData.pembulatan)" class="flex justify-between">
                                <span>Pembulatan</span>
                                <span>{{ formatCurrency(detailData.pembulatan) }}</span>
                            </div>
                            <Divider />
                            <div class="flex justify-between font-bold text-lg">
                                <span>Grand Total</span>
                                <span>{{ formatCurrency(detailData.grand_total) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payments -->
                    <div class="mt-6">
                        <h4 class="text-lg font-medium mb-3">Pembayaran</h4>
                        <DetailTable :data="detailData.payments" :columns="paymentColumns">
                            <template #metode="{ item }">{{ item.metode_pembayaran?.nama_pembayaran || '-' }}</template>
                            <template #nominal="{ item }">{{ formatCurrency(item.nominal) }}</template>
                            <template #referensi="{ item }">{{ item.reference || '-' }}</template>
                            <template #biaya="{ item }">
                                {{ Number(item.biaya_tambahan) > 0 ? formatCurrency(item.biaya_tambahan) : '-' }}
                            </template>
                        </DetailTable>
                        <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-3 mt-2 space-y-1">
                            <div class="flex justify-between font-medium">
                                <span>Total Bayar</span>
                                <span>{{ formatCurrency(detailData.total_bayar) }}</span>
                            </div>
                            <div v-if="Number(detailData.kembalian) > 0" class="flex justify-between font-medium">
                                <span>Kembalian</span>
                                <span>{{ formatCurrency(detailData.kembalian) }}</span>
                            </div>
                            <div v-if="Number(detailData.total_biaya_pembayaran) > 0" class="flex justify-between text-sm text-surface-500">
                                <span>Total Biaya Pembayaran</span>
                                <span>{{ formatCurrency(detailData.total_biaya_pembayaran) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Returns Section -->
                    <div v-if="detailData.returns?.length > 0" class="mt-6">
                        <h4 class="text-lg font-medium mb-3">Riwayat Retur ({{ detailData.returns.length }})</h4>

                        <div v-for="ret in detailData.returns" :key="ret.ulid" class="mb-4 p-4 border border-orange-200 dark:border-orange-800 rounded-lg bg-orange-50/50 dark:bg-orange-900/10">
                            <!-- Retur header -->
                            <div class="flex flex-wrap items-center gap-3 mb-3">
                                <span class="font-medium">{{ ret.nomor_dokumen }}</span>
                                <Tag value="Tunai" severity="success" class="text-xs" />
                                <span class="text-sm text-surface-500">{{ formatDateTime(ret.tanggal) }}</span>
                            </div>

                            <!-- Cross-shift info -->
                            <div v-if="ret.terminal || ret.created_by" class="text-sm text-surface-500 mb-3 flex flex-wrap gap-4">
                                <span v-if="ret.terminal"><i class="pi pi-desktop mr-1"></i>{{ ret.terminal.nama_terminal }}</span>
                                <span v-if="ret.created_by"><i class="pi pi-user mr-1"></i>{{ ret.created_by.name }}</span>
                                <span v-if="ret.shift"><i class="pi pi-clock mr-1"></i>Shift {{ formatDateTime(ret.shift.started_at) }}</span>
                            </div>

                            <!-- Retur items -->
                            <DetailTable :data="ret.details" :columns="returDetailColumns">
                                <template #product="{ item }">
                                    <span class="font-medium">{{ item.product?.kode_produk }}</span>
                                    <span class="text-surface-500 text-sm ml-2">{{ item.product?.nama_produk }}</span>
                                </template>
                                <template #qty="{ item }">{{ formatQty(item.qty) }} {{ item.unit }}</template>
                                <template #harga="{ item }">{{ formatCurrency(item.harga_satuan) }}</template>
                                <template #total="{ item }">
                                    <span class="font-medium">{{ formatCurrency(item.jumlah) }}</span>
                                </template>
                            </DetailTable>

                            <div class="flex justify-end mt-2 gap-6 text-sm">
                                <span v-if="Number(ret.pembulatan)" class="text-surface-500"> Pembulatan: {{ formatCurrency(ret.pembulatan) }} </span>
                                <span class="font-semibold text-orange-600"> Total Retur: {{ formatCurrency(ret.grand_total) }} </span>
                            </div>
                        </div>

                        <!-- Ringkasan Retur -->
                        <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4 space-y-2">
                            <div class="font-semibold mb-2">Ringkasan Retur</div>
                            <div class="flex justify-between">
                                <span>Total Semua Retur</span>
                                <span class="font-medium text-orange-600">{{ formatCurrency(totalReturns) }}</span>
                            </div>
                            <div class="flex justify-between text-sm pl-4">
                                <span class="text-surface-500">Refund Tunai</span>
                                <span>{{ formatCurrency(totalReturns) }}</span>
                            </div>
                            <Divider />
                            <div class="flex justify-between font-bold text-lg">
                                <span>Nilai Bersih</span>
                                <span class="text-blue-600">{{ formatCurrency(nilaiBersih) }}</span>
                            </div>
                            <div class="text-xs text-surface-500">(Grand Total - Total Retur)</div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div v-if="detailData.notes" class="mt-4">
                        <span class="text-surface-500 text-sm block mb-1">Catatan</span>
                        <p class="m-0">{{ detailData.notes }}</p>
                    </div>
                </div>
            </template>

            <template #footer-extra>
                <div class="flex flex-wrap gap-2">
                    <Button label="Print" icon="pi pi-print" severity="secondary" outlined @click="handleDetailPrint" />
                    <Button label="PDF" icon="pi pi-file-pdf" severity="warn" outlined @click="handleDetailPdf" />
                    <Button label="Copy URL" icon="pi pi-link" severity="info" outlined @click="handleDetailCopyUrl" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { pembayaranPiutangsApi, customersApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { useExportPdf } from '@/composables/useExportPdf';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import RowActionButtons from '@/components/common/RowActionButtons.vue';

const authStore = useAuthStore();
const confirm = useConfirm();
const { formatCurrency, formatDateTime, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportDocumentPdf } = useExportPdf();

// Permissions
const canCreate = computed(() => authStore.can('pembayaran-piutang.create'));
const canEditPerm = computed(() => authStore.can('pembayaran-piutang.update'));
const canDeletePerm = computed(() => authStore.can('pembayaran-piutang.delete'));
const canCompletePerm = computed(() => authStore.can('pembayaran-piutang.complete'));
const canViewNominal = computed(() => authStore.can('piutang.view_nominal'));

// Customers for filter
const customers = ref([]);

// Custom status options
const customStatusOptions = [
    { label: 'Draft', value: 'draft' },
    { label: 'Completed', value: 'completed' }
];

const activeFilterCount = computed(() => {
    let n = 0;
    if (additionalFilters.value?.customer_id) n++;
    if (selectedStatus.value) n++;
    if (startDate.value) n++;
    if (endDate.value) n++;
    return n;
});

// Initialize composable
const {
    items,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
    statusOptions,
    startDate,
    endDate,
    additionalFilters,
    detailDialog,
    detailData,
    loadingDetail,
    processingApprove,
    loadData,
    onPage,
    onSort,
    doSearch,
    clearSearch,
    onFilter,
    resetFilters,
    createNew,
    editItem,
    viewDetail,
    closeDetail,
    confirmDelete,
    confirmApprove,
    approveItem,
    getStatusSeverity,
    getStatusLabel,
    canEdit: canEditItem,
    canDelete,
    canApprove: canCompleteItem
} = useTransactionList(pembayaranPiutangsApi, {
    entityName: 'pembayaran', // Match API response key
    dataKey: 'items',
    routePrefix: 'penjualan-pembayaran-piutang',
    filters: [{ key: 'customer_id', default: null }],
    statusOptions: customStatusOptions,
    autoLoad: false
});

// Override lazyParams sortField
lazyParams.value.sortField = 'tanggal';

// Detail table columns - Piutang Details
const piutangDetailColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'no_sales', header: 'No. Sales' },
    { field: 'tanggal_piutang', header: 'Tanggal Piutang', width: '140px' },
    { field: 'nominal_awal', header: 'Nominal Awal', align: 'right', width: '130px' },
    { field: 'sumber', header: 'Sumber', width: '80px' },
    { field: 'nominal_bayar', header: 'Nominal Bayar', align: 'right', width: '130px' }
];

// Detail table columns - Deposit Usages
const depositUsageColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'sumber_deposit', header: 'Sumber Deposit' },
    { field: 'tanggal', header: 'Tanggal', width: '140px' },
    { field: 'saldo_awal', header: 'Saldo Awal', align: 'right', width: '130px' },
    { field: 'digunakan', header: 'Digunakan', align: 'right', width: '130px' }
];

// Load customers for filter dropdown
async function loadCustomers() {
    try {
        const response = await customersApi.getList({ jenis: 'spesifik' });
        if (response.data.success) {
            customers.value = response.data.data.customers;
        }
    } catch (error) {
        console.error('Failed to load customers:', error);
    }
}

// Custom complete confirmation (bukan pesan stok)
function confirmComplete(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin menyelesaikan pembayaran ${data.nomor_dokumen}? Piutang customer akan diperbarui dan tidak dapat dibatalkan.`,
        header: 'Konfirmasi Complete',
        icon: 'pi pi-check-circle',
        rejectLabel: 'Batal',
        acceptLabel: 'Complete',
        rejectClass: 'p-button-secondary p-button-outlined',
        acceptClass: 'p-button-success',
        accept: () => approveItem(data)
    });
}

// Get metode pembayaran label
function getMetodeLabel(metode) {
    return metode === 'transfer' ? 'Transfer' : 'Cash';
}

// Export document PDF
async function exportDocPdf(item) {
    let data = item;
    if (!data.details) {
        try {
            const response = await pembayaranPiutangsApi.get(data.ulid);
            data = response.data.data.pembayaran || response.data.data;
        } catch {
            return;
        }
    }

    const info = [
        { label: 'No. Dokumen', value: data.nomor_dokumen },
        { label: 'Tanggal', value: formatDateTime(data.tanggal) },
        { label: 'Customer', value: data.customer?.nama || '-' },
        { label: 'Status', value: getStatusLabel(data.status) },
        { label: 'Metode', value: getMetodeLabel(data.metode_pembayaran) }
    ];
    if (data.no_referensi) info.push({ label: 'No. Referensi', value: data.no_referensi });
    if (data.bank_nama) info.push({ label: 'Bank', value: data.bank_nama });
    if (data.bank_rekening) info.push({ label: 'No. Rekening', value: data.bank_rekening });

    // Build multiple tables
    const tbls = [];

    // Table 1: Piutang details
    const piutangCols = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'No. Sales', accessor: (row) => row.piutang?.sales?.nomor_dokumen || '-' },
        { header: 'Tgl Piutang', width: 22, accessor: (row) => formatDateTime(row.piutang?.tanggal) },
        { header: 'Nominal Awal', width: 24, align: 'right', accessor: (row) => formatCurrency(row.piutang?.nominal_awal) },
        { header: 'Sumber', width: 16, accessor: (row) => (row.sumber === 'deposit' ? 'Deposit' : 'Cash') },
        { header: 'Nominal Bayar', width: 24, align: 'right', accessor: (row) => formatCurrency(row.nominal_dibayar) }
    ];
    tbls.push({ title: `Detail Piutang Dibayar (${data.details?.length || 0} item)`, columns: piutangCols, data: data.details || [] });

    // Table 2: Deposit usages (if any)
    if (data.deposit_usages?.length > 0) {
        const depositCols = [
            { header: '#', field: '#', width: 8, align: 'center' },
            { header: 'Sumber Deposit', accessor: (row) => row.deposit?.sales_return?.nomor_dokumen || row.deposit?.no_referensi || 'Manual' },
            { header: 'Tanggal', width: 22, accessor: (row) => formatDateTime(row.deposit?.tanggal) },
            { header: 'Saldo Awal', width: 24, align: 'right', accessor: (row) => formatCurrency(row.deposit?.nominal_awal) },
            { header: 'Digunakan', width: 24, align: 'right', accessor: (row) => formatCurrency(row.nominal_digunakan) }
        ];
        tbls.push({ title: `Deposit Digunakan (${data.deposit_usages.length} item)`, columns: depositCols, data: data.deposit_usages });
    }

    const summary = [
        { label: 'Total Cash', value: formatCurrency(data.total_bayar_cash) },
        { label: 'Total Deposit', value: formatCurrency(data.total_bayar_deposit) },
        { separator: true },
        { label: 'Total Pembayaran', value: formatCurrency(data.total_pembayaran), bold: true }
    ];

    const audit = [];
    if (data.created_by?.name) {
        audit.push({ label: 'Dibuat oleh', value: data.created_by.name, date: formatDateTime(data.created_at) });
    }
    if (data.status === 'completed' && data.completed_by?.name) {
        audit.push({ label: 'Diselesaikan oleh', value: data.completed_by.name, date: formatDateTime(data.completed_at) });
    }

    exportDocumentPdf({
        title: 'Pembayaran Piutang',
        filename: data.nomor_dokumen || 'pembayaran_piutang',
        info,
        tables: tbls,
        summary,
        audit,
        notes: data.notes
    });
}

// Load data on mount
onMounted(async () => {
    await loadCustomers();
    await loadData();
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Buat Pembayaran" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <ListFiltersSheet :active-count="activeFilterCount">
                    <Select v-model="additionalFilters.customer_id" :options="customers" optionLabel="nama" optionValue="id" placeholder="Customer" filter showClear @change="onFilter" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" filter showClear @change="onFilter" />
                    <div class="list-filter-control">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" />
                    </div>
                    <div class="list-filter-control">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" />
                    </div>
                    <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                </ListFiltersSheet>
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
                <DataTableHeader v-model="searchQuery" title="Daftar Pembayaran Piutang" placeholder="Cari no. dokumen, customer..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data pembayaran piutang</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="No. Dokumen" sortable style="min-width: 150px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <Column header="Customer" style="min-width: 200px">
                <template #body="{ data }">
                    <div>
                        <span class="font-medium">{{ data.customer?.nama }}</span>
                        <div class="text-sm text-surface-500">{{ data.customer?.kode_customer }}</div>
                    </div>
                </template>
            </Column>

            <Column header="Metode" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="getMetodeLabel(data.metode_pembayaran)" :severity="data.metode_pembayaran === 'transfer' ? 'info' : 'secondary'" />
                </template>
            </Column>

            <Column header="Piutang" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.details_count" severity="secondary" />
                </template>
            </Column>

            <Column v-if="canViewNominal" field="total_pembayaran" header="Total Bayar" sortable style="min-width: 150px" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-semibold">{{ formatCurrency(data.total_pembayaran) }}</span>
                </template>
            </Column>

            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                </template>
            </Column>

            <Column header="Aksi" style="min-width: 220px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <RowActionButtons>
                        <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'"  />
                        <Button v-if="canViewNominal" icon="pi pi-file-pdf" severity="help" text rounded :loading="exporting" @click="exportDocPdf(data)" v-tooltip.top="'Export PDF'"  />
                        <Button v-if="canEditPerm && canEditItem(data)" icon="pi pi-pencil" severity="warning" text rounded @click="editItem(data)" v-tooltip.top="'Edit'"  />
                        <Button v-if="canDeletePerm && canDelete(data)" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'"  />
                        <Button v-if="canCompletePerm && canCompleteItem(data)" icon="pi pi-check" severity="success" text rounded @click="confirmComplete(data)" v-tooltip.top="'Complete'"  />
                    </RowActionButtons>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Pembayaran Piutang"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="900px"
        >
            <template #content>
                <div v-if="detailData.ulid">
                    <!-- Header Info -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <DetailItem label="No. Dokumen" :value="detailData.nomor_dokumen" />
                        <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal)" />
                        <DetailItem label="Customer" :value="detailData.customer?.nama" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                        <DetailItem label="Metode Pembayaran" :value="getMetodeLabel(detailData.metode_pembayaran)" />
                        <DetailItem v-if="detailData.no_referensi" label="No. Referensi" :value="detailData.no_referensi" />
                        <DetailItem v-if="detailData.bank_nama" label="Bank" :value="detailData.bank_nama" />
                        <DetailItem v-if="detailData.bank_rekening" label="No. Rekening" :value="detailData.bank_rekening" />
                    </div>

                    <!-- Piutang Details Table -->
                    <div class="mt-4">
                        <h4 class="text-lg font-medium mb-3">Detail Piutang Dibayar ({{ detailData.details?.length || 0 }} item)</h4>
                        <DetailTable :data="detailData.details" :columns="piutangDetailColumns">
                            <template #no_sales="{ item }">
                                <span class="font-medium">{{ item.piutang?.sales?.nomor_dokumen || '-' }}</span>
                            </template>
                            <template #tanggal_piutang="{ item }">{{ formatDateTime(item.piutang?.tanggal) }}</template>
                            <template #nominal_awal="{ item }">{{ formatCurrency(item.piutang?.nominal_awal) }}</template>
                            <template #sumber="{ item }">
                                <Tag :value="item.sumber === 'deposit' ? 'Deposit' : 'Cash'" :severity="item.sumber === 'deposit' ? 'info' : 'secondary'" size="small" />
                            </template>
                            <template #nominal_bayar="{ item }">
                                <span class="font-medium text-green-600">{{ formatCurrency(item.nominal_dibayar) }}</span>
                            </template>
                        </DetailTable>
                    </div>

                    <!-- Deposit Usages Table -->
                    <div v-if="detailData.deposit_usages?.length > 0" class="mt-6">
                        <h4 class="text-lg font-medium mb-3">Deposit Digunakan ({{ detailData.deposit_usages?.length }} item)</h4>
                        <DetailTable :data="detailData.deposit_usages" :columns="depositUsageColumns">
                            <template #sumber_deposit="{ item }">
                                <span v-if="item.deposit?.sales_return" class="font-medium">{{ item.deposit?.sales_return?.nomor_dokumen }}</span>
                                <span v-else class="font-medium">{{ item.deposit?.no_referensi || 'Manual' }}</span>
                                <div class="text-sm text-surface-500">{{ item.deposit?.keterangan || '-' }}</div>
                            </template>
                            <template #tanggal="{ item }">{{ formatDateTime(item.deposit?.tanggal) }}</template>
                            <template #saldo_awal="{ item }">{{ formatCurrency(item.deposit?.nominal_awal) }}</template>
                            <template #digunakan="{ item }">
                                <span class="font-medium text-orange-600">{{ formatCurrency(item.nominal_digunakan) }}</span>
                            </template>
                        </DetailTable>
                    </div>

                    <!-- Totals -->
                    <div v-if="canViewNominal" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                        <div></div>
                        <div class="border border-surface-200 rounded-lg p-4 space-y-2">
                            <div class="flex justify-between">
                                <span>Total Cash</span>
                                <span>{{ formatCurrency(detailData.total_bayar_cash) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Total Deposit</span>
                                <span>{{ formatCurrency(detailData.total_bayar_deposit) }}</span>
                            </div>
                            <Divider />
                            <div class="flex justify-between font-bold text-lg">
                                <span>Total Pembayaran</span>
                                <span class="text-green-600">{{ formatCurrency(detailData.total_pembayaran) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Completed info -->
                    <div class="mt-4 pt-4 border-t border-surface-200" v-if="detailData.status === 'completed' && detailData.completed_by">
                        <div class="flex items-center gap-2 text-surface-500 text-sm">
                            <i class="pi pi-check-circle text-green-500"></i>
                            <span>Diselesaikan: {{ formatDateTime(detailData.completed_at) }} oleh {{ detailData.completed_by?.name }}</span>
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
                    <Button label="Export PDF" icon="pi pi-file-pdf" severity="help" outlined :loading="exporting" @click="exportDocPdf(detailData)" />
                    <Button v-if="canEditPerm && canEditItem(detailData)" label="Edit" icon="pi pi-pencil" severity="warning" @click=" editItem(detailData); closeDetail(); " text rounded />
                    <Button v-if="canDeletePerm && canDelete(detailData)" label="Hapus" icon="pi pi-trash" severity="danger" @click=" confirmDelete(detailData); closeDetail(); " text rounded />
                    <Button v-if="canCompletePerm && canCompleteItem(detailData)" label="Complete" icon="pi pi-check" severity="success" :loading="processingApprove" @click="confirmComplete(detailData)" text rounded />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

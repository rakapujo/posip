<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { salesApi, warehousesApi, customersApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useSalesInvoicePdf } from '@/composables/useSalesInvoicePdf';
import { useNotification } from '@/composables/useNotification';
import { useConfirm } from 'primevue/useconfirm';
import { useAuthStore } from '@/stores/auth';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import RowActionButtons from '@/components/common/RowActionButtons.vue';

const authStore = useAuthStore();
const route = useRoute();
const confirm = useConfirm();
const notify = useNotification();
const { formatCurrency, formatQty, formatDateTime, getPrimeDateFormatShort, toDateString } = useFormatters();
const { exporting, exportSalesInvoicePdf } = useSalesInvoicePdf();

// Permissions
const canCreate = computed(() => authStore.can('sales.create'));
const canEdit = computed(() => authStore.can('sales.update'));
const canDeletePerm = computed(() => authStore.can('sales.delete'));
const canApprove = computed(() => authStore.can('sales.approve'));

// Customers and Warehouses for filter
const customers = ref([]);
const canVoid = computed(() => authStore.can('sales.void'));
const warehouses = ref([]);

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
    getStatusSeverity,
    getStatusLabel,
    canEdit: canEditItem,
    canDelete,
    canApprove: canApproveItem
} = useTransactionList(salesApi, {
    entityName: 'sales',
    detailKey: 'sales',
    dataKey: 'items',
    routePrefix: 'penjualan-sales',
    filters: [
        { key: 'customer_id', default: null },
        { key: 'warehouse_id', default: null }
    ],
    statusOptions: [
        { label: 'Draft', value: 'draft' },
        { label: 'Completed', value: 'completed' },
        { label: 'Voided', value: 'voided' }
    ],
    autoLoad: false
});

function isDefaultMonthStart(d) {
    const now = new Date();
    return toDateString(d) === toDateString(new Date(now.getFullYear(), now.getMonth(), 1));
}

function isDefaultToday(d) {
    return toDateString(d) === toDateString(new Date());
}

const activeFilterCount = computed(() => {
    let n = 0;
    if (additionalFilters.customer_id) n++;
    if (additionalFilters.warehouse_id) n++;
    if (selectedStatus.value) n++;
    if (startDate.value && !isDefaultMonthStart(startDate.value)) n++;
    if (endDate.value && !isDefaultToday(endDate.value)) n++;
    return n;
});

// Override lazyParams sortField for tanggal_po
lazyParams.value.sortField = 'tanggal';

// Detail table columns
const detailColumns = computed(() => [
    { field: '#', header: '#', width: '40px' },
    { field: 'product', header: 'Produk' },
    { field: 'unit', header: 'Satuan', width: '80px' },
    { field: 'qty', header: 'Qty', align: 'right', width: '80px' },
    { field: 'harga', header: 'Harga', align: 'right', width: '120px' },
    { field: 'diskon', header: 'Diskon', align: 'right', width: '100px' },
    { field: 'subtotal', header: 'Subtotal', align: 'right', width: '120px' }
]);

// Load suppliers for filter dropdown
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

// Load warehouses for filter dropdown
async function loadWarehouses() {
    try {
        const response = await warehousesApi.getList();
        if (response.data.success) {
            warehouses.value = response.data.data.warehouses;
        }
    } catch (error) {
        console.error('Failed to load warehouses:', error);
    }
}

// Export document PDF — selalu fetch detail agar serial_units ter-resolve
async function exportDocPdf(item) {
    let data = item;
    try {
        const response = await salesApi.get(item.ulid);
        data = response.data.data.sales || response.data.data;
    } catch {
        if (!data.details) return;
    }

    await exportSalesInvoicePdf(data);
}

// Load data on mount
onMounted(async () => {
    await Promise.all([loadCustomers(), loadWarehouses()]);
    await loadData();
    // Auto-open detail (mis. dari Register Unit Serial Nota Jual)
    if (route.query.detail) {
        viewDetail({ ulid: route.query.detail });
    }
});

function canVoidItem(item) {
    if (!item || item.status !== 'completed' || item.cash_payment) return false;
    const p = item.piutang;
    if (!p) return false;
    return p.status === 'unpaid' && Number(p.nominal_terbayar || 0) === 0;
}

function confirmVoid(data) {
    confirm.require({
        message: `Void penjualan ${data.nomor_dokumen}? Hanya tempo unpaid penuh. Cash/lunas tidak bisa void.`,
        header: 'Konfirmasi Void',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Batal',
        acceptLabel: 'Void',
        rejectClass: 'p-button-secondary p-button-outlined',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                const reason = window.prompt('Alasan void:') || '';
                if (!reason.trim()) {
                    notify.error('Alasan void wajib diisi');
                    return;
                }
                const res = await salesApi.void(data.ulid, { reason: reason.trim() });
                if (res.data.success) {
                    notify.success('Penjualan berhasil di-void');
                    loadData();
                    if (detailDialog.value && detailData.value.ulid === data.ulid) closeDetail();
                }
            } catch (e) {
                notify.apiError(e, 'Gagal void penjualan');
            }
        }
    });
}
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Buat Penjualan" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <ListFiltersSheet :active-count="activeFilterCount">
                    <Select v-model="additionalFilters.customer_id" :options="customers" optionLabel="nama" optionValue="id" placeholder="Customer" filter showClear @change="onFilter" />
                    <Select v-model="additionalFilters.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Warehouse" filter showClear @change="onFilter" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" filter showClear @change="onFilter" />
                    <div class="list-filter-control">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" @update:modelValue="onFilter" />
                    </div>
                    <div class="list-filter-control">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" @update:modelValue="onFilter" />
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
                <DataTableHeader v-model="searchQuery" title="Daftar Penjualan" placeholder="Cari nomor, customer..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data penjualan</p>
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

            <Column header="Warehouse" style="min-width: 150px">
                <template #body="{ data }">
                    {{ data.warehouse?.nama_warehouse || '-' }}
                </template>
            </Column>

            <Column header="Item" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.details_count" severity="secondary" />
                </template>
            </Column>

            <Column field="grand_total" header="Grand Total" sortable style="min-width: 150px" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-semibold">{{ formatCurrency(data.grand_total) }}</span>
                </template>
            </Column>

            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                </template>
            </Column>

            <Column header="Aksi" style="min-width: 260px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <RowActionButtons>
                        <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'"  />
                        <Button icon="pi pi-file-pdf" severity="help" text rounded :loading="exporting" @click="exportDocPdf(data)" v-tooltip.top="'Export PDF'"  />
                        <Button v-if="canEdit && canEditItem(data)" icon="pi pi-pencil" severity="warning" text rounded @click="editItem(data)" v-tooltip.top="'Edit'"  />
                        <Button v-if="canDeletePerm && canDelete(data)" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'"  />
                        <Button v-if="canApprove && canApproveItem(data)" icon="pi pi-check" severity="success" text rounded @click="confirmApprove(data)" v-tooltip.top="'Approve'"  />
                        <Button v-if="canVoid && canVoidItem(data)" icon="pi pi-ban" severity="danger" text rounded @click="confirmVoid(data)" v-tooltip.top="'Void (tempo unpaid saja)'"  />
                    </RowActionButtons>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Penjualan"
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
                        <DetailItem label="Warehouse" :value="detailData.warehouse?.nama_warehouse" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                        <DetailItem label="Tempo" :value="`${detailData.tempo_hari || 0} Hari`" />
                        <DetailItem label="Jatuh Tempo" :value="formatDateTime(detailData.tanggal_jatuh_tempo)" />
                        <DetailItem v-if="detailData.cash_payment" label="Pembayaran" value="Cash / Lunas" type="badge" badge-severity="success" />
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
                            <template #unit="{ item }">{{ item.unit }}</template>
                            <template #qty="{ item }">{{ formatQty(item.qty) }}</template>
                            <template #harga="{ item }">{{ formatCurrency(item.harga_satuan) }}</template>
                            <template #diskon="{ item }">{{ formatCurrency(item.diskon_total) }}</template>
                            <template #subtotal="{ item }">
                                <span class="font-medium">{{ formatCurrency(item.jumlah) }}</span>
                            </template>
                        </DetailTable>
                    </div>

                    <!-- Totals -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                        <div></div>
                        <div class="border border-surface-200 rounded-lg p-4 space-y-2">
                            <div class="flex justify-between">
                                <span>Subtotal</span>
                                <span>{{ formatCurrency(detailData.subtotal) }}</span>
                            </div>
                            <div v-if="detailData.total_diskon_header > 0 || detailData.total_diskon > 0" class="flex justify-between text-red-500">
                                <span>Diskon</span>
                                <span>-{{ formatCurrency(detailData.total_diskon || detailData.total_diskon_header) }}</span>
                            </div>
                            <div v-if="detailData.biaya_kirim_hasil > 0" class="flex justify-between">
                                <span>Biaya Kirim</span>
                                <span>{{ formatCurrency(detailData.biaya_kirim_hasil) }}</span>
                            </div>
                            <div v-if="detailData.biaya_lain_hasil > 0" class="flex justify-between">
                                <span>Biaya Lain</span>
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
                            <div v-if="detailData.pembulatan && detailData.pembulatan !== 0" class="flex justify-between">
                                <span>Pembulatan</span>
                                <span :class="detailData.pembulatan > 0 ? 'text-green-600' : 'text-red-500'"> {{ detailData.pembulatan > 0 ? '+' : '' }}{{ formatCurrency(detailData.pembulatan) }} </span>
                            </div>
                            <Divider />
                            <div class="flex justify-between font-bold text-lg">
                                <span>Grand Total</span>
                                <span>{{ formatCurrency(detailData.grand_total) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Approved info -->
                    <div class="mt-4 pt-4 border-t border-surface-200" v-if="detailData.status === 'completed' && detailData.approved_by">
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

            <template #footer-extra>
                <div class="flex flex-wrap gap-2">
                    <Button label="Export PDF" icon="pi pi-file-pdf" severity="help" outlined :loading="exporting" @click="exportDocPdf(detailData)" />
                    <Button v-if="canEdit && canEditItem(detailData)" label="Edit" icon="pi pi-pencil" severity="warning" @click=" editItem(detailData); closeDetail(); " text rounded />
                    <Button v-if="canDeletePerm && canDelete(detailData)" label="Hapus" icon="pi pi-trash" severity="danger" @click=" confirmDelete(detailData); closeDetail(); " text rounded />
                    <Button v-if="canApprove && canApproveItem(detailData)" label="Approve" icon="pi pi-check" severity="success" :loading="processingApprove" @click="confirmApprove(detailData)" text rounded />
                    <Button v-if="canVoid && canVoidItem(detailData)" label="Void" icon="pi pi-ban" severity="danger" @click="confirmVoid(detailData)" text rounded />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

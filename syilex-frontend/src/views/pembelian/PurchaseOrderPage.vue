<script setup>
import { ref, computed, onMounted } from 'vue';
import { purchaseOrdersApi, warehousesApi, suppliersApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const { formatCurrency, formatPercent, formatQty, formatDateTime, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportDocumentPdf } = useExportPdf();

// Permissions
const canViewHarga = computed(() => authStore.can('po.view_harga'));
const canCreate = computed(() => authStore.can('po.create'));
const canEdit = computed(() => authStore.can('po.edit'));
const canDeletePerm = computed(() => authStore.can('po.delete'));
const canApprove = computed(() => authStore.can('po.approve'));

// Suppliers and Warehouses for filter
const suppliers = ref([]);
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
} = useTransactionList(purchaseOrdersApi, {
    entityName: 'purchase_order',
    dataKey: 'items',
    routePrefix: 'pembelian-po',
    filters: [
        { key: 'supplier_id', default: null },
        { key: 'warehouse_id', default: null }
    ],
    autoLoad: false
});

// Override lazyParams sortField for tanggal_po
lazyParams.value.sortField = 'tanggal_po';

// Detail table columns (dynamic based on permission)
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

// Load suppliers for filter dropdown
async function loadSuppliers() {
    try {
        const response = await suppliersApi.getList();
        if (response.data.success) {
            suppliers.value = response.data.data.suppliers;
        }
    } catch (error) {
        console.error('Failed to load suppliers:', error);
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

// Export document PDF
// Format disc value label based on tipe
function fmtDiscVal(tipe, nilai) {
    if (tipe === 'percent') return `${formatPercent(nilai)}`;
    if (tipe === 'nominal') return formatCurrency(nilai);
    return null;
}

// Format disc 1-5 chain for a detail row: "10% + Rp 2.000 + 5%\n(-Rp 8.850)"
function fmtDiscLine(row) {
    const parts = [];
    for (let i = 1; i <= 5; i++) {
        const tipe = row[`diskon_${i}_tipe`];
        const nilai = Number(row[`diskon_${i}_nilai`] || 0);
        if (tipe === 'none' || nilai === 0) continue;
        parts.push(fmtDiscVal(tipe, nilai));
    }
    if (parts.length === 0) return '-';
    const total = Number(row.total_diskon_item || 0);
    return `${parts.join(' + ')}\n(-${formatCurrency(total)})`;
}

async function exportDocPdf(item) {
    let data = item;
    // Fetch full detail if not already loaded (e.g. from action column)
    if (!data.details) {
        try {
            const response = await purchaseOrdersApi.get(data.ulid);
            data = response.data.data.purchase_order || response.data.data;
        } catch {
            return;
        }
    }

    // Info section
    const info = [
        { label: 'No. Dokumen', value: data.nomor_dokumen },
        { label: 'Tanggal PO', value: formatDateTime(data.tanggal_po) },
        { label: 'Supplier', value: data.supplier?.nama_supplier || '-' },
        { label: 'Warehouse', value: data.warehouse?.nama_warehouse || '-' },
        { label: 'Status', value: getStatusLabel(data.status) },
        { label: 'Tempo', value: `${data.tempo_hari || 0} Hari` },
        { label: 'Jatuh Tempo', value: formatDateTime(data.tanggal_jatuh_tempo) }
    ];

    // Table columns (permission-gated)
    const columns = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'Kode Produk', width: 22, accessor: (row) => row.product?.kode_produk || '' },
        { header: 'Nama Produk', accessor: (row) => row.product?.nama_produk || '' },
        { header: 'Satuan', field: 'unit_used', width: 16 },
        { header: 'Qty', width: 14, align: 'right', accessor: (row) => formatQty(row.qty_in_unit) }
    ];
    if (canViewHarga.value) {
        columns.push(
            { header: 'Harga', width: 22, align: 'right', accessor: (row) => formatCurrency(row.harga_per_unit) },
            { header: 'Disc 1-5', width: 32, accessor: fmtDiscLine },
            { header: 'Subtotal', width: 22, align: 'right', accessor: (row) => formatCurrency(row.subtotal) }
        );
    }

    // Summary (only if has price permission)
    let summary = null;
    if (canViewHarga.value) {
        summary = [{ label: 'Subtotal', value: formatCurrency(data.subtotal) }];

        // Disc Nota 1-3 per baris
        for (let i = 1; i <= 3; i++) {
            const tipe = data[`diskon_${i}_tipe`];
            const nilai = Number(data[`diskon_${i}_nilai`] || 0);
            const hasil = Number(data[`diskon_${i}_hasil`] || 0);
            if (tipe === 'none' || nilai === 0) continue;
            const label = `Disc Nota ${i} (${fmtDiscVal(tipe, nilai)})`;
            summary.push({ label, value: `-${formatCurrency(hasil)}` });
        }

        if (Number(data.total_diskon_header) > 0) {
            summary.push({ label: 'Total Stlh Diskon', value: formatCurrency(data.total_setelah_diskon) });
        }

        if (Number(data.biaya_kirim_hasil) > 0) {
            summary.push({ label: 'Biaya Kirim', value: formatCurrency(data.biaya_kirim_hasil) });
        }
        if (Number(data.biaya_lain_hasil) > 0) {
            summary.push({ label: data.biaya_lain_nama || 'Biaya Lain', value: formatCurrency(data.biaya_lain_hasil) });
        }
        summary.push({ label: 'DPP', value: formatCurrency(data.dpp) }, { label: `${data.pajak_nama} (${data.pajak_persen}%)`, value: formatCurrency(data.pajak_nominal) });
        if (data.pembulatan && data.pembulatan !== 0) {
            summary.push({ label: 'Pembulatan', value: formatCurrency(data.pembulatan) });
        }
        summary.push({ separator: true }, { label: 'Grand Total', value: formatCurrency(data.grand_total), bold: true });
    }

    // Audit
    const audit = [];
    if (data.created_by?.name) {
        audit.push({ label: 'Dibuat oleh', value: data.created_by.name, date: formatDateTime(data.created_at) });
    }
    if (data.status === 'approved' && data.approved_by?.name) {
        audit.push({ label: 'Disetujui oleh', value: data.approved_by.name, date: formatDateTime(data.approved_at) });
    }

    exportDocumentPdf({
        title: 'Purchase Order',
        filename: data.nomor_dokumen || 'purchase_order',
        info,
        table: { columns, data: data.details || [] },
        summary,
        audit,
        notes: data.notes
    });
}

// Load data on mount
onMounted(async () => {
    await Promise.all([loadSuppliers(), loadWarehouses()]);
    await loadData();
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Buat PO" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="additionalFilters.supplier_id" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Supplier" class="w-40" filter showClear @change="onFilter" />
                    <Select v-model="additionalFilters.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Warehouse" class="w-40" filter showClear @change="onFilter" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-32" filter showClear @change="onFilter" />
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" />
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
                <DataTableHeader v-model="searchQuery" title="Daftar Purchase Order" placeholder="Cari no. dokumen, supplier..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data purchase order</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="No. Dokumen" sortable style="min-width: 150px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tanggal_po" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal_po) }}
                </template>
            </Column>

            <Column header="Supplier" style="min-width: 200px">
                <template #body="{ data }">
                    <div>
                        <span class="font-medium">{{ data.supplier?.nama_supplier }}</span>
                        <div class="text-sm text-surface-500">{{ data.supplier?.kode_supplier }}</div>
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

            <Column v-if="canViewHarga" field="grand_total" header="Grand Total" sortable style="min-width: 150px" bodyClass="text-right">
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
                    <div class="flex gap-1">
                        <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" />
                        <Button icon="pi pi-file-pdf" severity="help" text rounded :loading="exporting" @click="exportDocPdf(data)" v-tooltip.top="'Export PDF'" />
                        <Button v-if="canEdit && canEditItem(data)" icon="pi pi-pencil" severity="warning" text rounded @click="editItem(data)" v-tooltip.top="'Edit'" />
                        <Button v-if="canDeletePerm && canDelete(data)" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'" />
                        <Button v-if="canApprove && canApproveItem(data)" icon="pi pi-check" severity="success" text rounded @click="confirmApprove(data)" v-tooltip.top="'Approve'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Purchase Order"
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
                        <DetailItem label="Tanggal PO" :value="formatDateTime(detailData.tanggal_po)" />
                        <DetailItem label="Supplier" :value="detailData.supplier?.nama_supplier" />
                        <DetailItem label="Warehouse" :value="detailData.warehouse?.nama_warehouse" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
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
                        <div class="border border-surface-200 rounded-lg p-4 space-y-2">
                            <div class="flex justify-between">
                                <span>Subtotal</span>
                                <span>{{ formatCurrency(detailData.subtotal) }}</span>
                            </div>
                            <div v-if="detailData.total_diskon_header > 0" class="flex justify-between text-red-500">
                                <span>Diskon</span>
                                <span>-{{ formatCurrency(detailData.total_diskon_header) }}</span>
                            </div>
                            <div v-if="detailData.biaya_kirim_hasil > 0" class="flex justify-between">
                                <span>Biaya Kirim</span>
                                <span>{{ formatCurrency(detailData.biaya_kirim_hasil) }}</span>
                            </div>
                            <div v-if="detailData.biaya_lain_hasil > 0" class="flex justify-between">
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
                    <div class="mt-4 pt-4 border-t border-surface-200" v-if="detailData.status === 'approved' && detailData.approved_by">
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
                    <Button
                        v-if="canEdit && canEditItem(detailData)"
                        label="Edit"
                        icon="pi pi-pencil"
                        severity="warning"
                        @click="
                            editItem(detailData);
                            closeDetail();
                        "
                    />
                    <Button
                        v-if="canDeletePerm && canDelete(detailData)"
                        label="Hapus"
                        icon="pi pi-trash"
                        severity="danger"
                        @click="
                            confirmDelete(detailData);
                            closeDetail();
                        "
                    />
                    <Button v-if="canApprove && canApproveItem(detailData)" label="Approve" icon="pi pi-check" severity="success" :loading="processingApprove" @click="confirmApprove(detailData)" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

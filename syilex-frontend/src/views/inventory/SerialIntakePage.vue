<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { serialIntakesApi, warehousesApi, suppliersApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import SerialLabelPrintDialog from '@/components/common/SerialLabelPrintDialog.vue';

const authStore = useAuthStore();
const route = useRoute();
const { formatCurrency, formatNumber, formatPercent, formatDateTime, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportDocumentPdf } = useExportPdf();

const canCreate = computed(() => authStore.can('serial-intake.create'));
const canEdit = computed(() => authStore.can('serial-intake.update'));
const canDeletePerm = computed(() => authStore.can('serial-intake.delete'));
const canApprove = computed(() => authStore.can('serial-intake.approve'));
// Lihat harga di read-only (detail/list/PDF). Form create/edit tetap menampilkan harga.
const canViewHarga = computed(() => authStore.can('serial-intake.view_harga'));

// Suppliers & Warehouses untuk filter
const suppliers = ref([]);
const warehouses = ref([]);

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
    processingApprove,
    getStatusSeverity,
    getStatusLabel,
    canEdit: canEditItem,
    canDelete,
    canApprove: canApproveItem
} = useTransactionList(serialIntakesApi, {
    entityName: 'pembelian serial',
    dataKey: 'items',
    detailKey: 'serial_intake',
    routePrefix: 'inventory-serial-intake',
    filters: [
        { key: 'supplier_id', default: null },
        { key: 'warehouse_id', default: null }
    ],
    statusOptions: [
        { label: 'Draft', value: 'draft' },
        { label: 'Approved', value: 'approved' }
    ],
    autoLoad: false
});

// Kolom tabel unit (detail) — Modal/Jual hanya bila berizin lihat harga
const unitColumns = computed(() => {
    const cols = [
        { field: 'no', header: '#', width: '50px' },
        { field: 'kode_internal', header: 'Kode Internal' },
        { field: 'serial_number', header: 'Nomor Seri' },
        { field: 'grade', header: 'Grade', align: 'center', width: '70px' },
        { field: 'battery_condition', header: 'Baterai' },
        { field: 'battery_health', header: 'Health', align: 'right', width: '90px' },
        { field: 'account_status', header: 'Akun', align: 'center', width: '110px' }
    ];
    // Modal (harga beli) = sensitif → hanya bila berizin view_harga
    if (canViewHarga.value) {
        cols.push({ field: 'harga_modal', header: 'Modal', align: 'right' });
    }
    // Harga Jual bukan rahasia → selalu tampil
    cols.push({ field: 'harga_jual', header: 'Jual', align: 'right' });
    return cols;
});

const unitRows = computed(() => (detailData.value.units || []).map((u, i) => ({ ...u, no: i + 1 })));

// Cetak label barcode unit (semua unit dokumen ini)
const printLabelVisible = ref(false);
const printContext = computed(() => ({
    kode_produk: detailData.value.product?.kode_produk,
    nama_produk: detailData.value.product?.nama_produk,
    nomor_dokumen: detailData.value.nomor_dokumen,
    tanggal: detailData.value.tanggal
}));

async function loadSuppliers() {
    try {
        const res = await suppliersApi.getList();
        if (res.data.success) suppliers.value = res.data.data.suppliers;
    } catch (error) {
        console.error('Failed to load suppliers:', error);
    }
}

async function loadWarehouses() {
    try {
        const res = await warehousesApi.getList();
        if (res.data.success) warehouses.value = res.data.data.warehouses;
    } catch (error) {
        console.error('Failed to load warehouses:', error);
    }
}

// Export PDF dokumen
async function exportDocPdf(item) {
    let d = item;
    if (!d.units) {
        try {
            const res = await serialIntakesApi.get(d.ulid);
            d = res.data.data.serial_intake;
        } catch {
            return;
        }
    }

    const info = [
        { label: 'No. Dokumen', value: d.nomor_dokumen },
        { label: 'Tanggal', value: formatDateTime(d.tanggal) },
        { label: 'Produk', value: d.product ? `${d.product.kode_produk} — ${d.product.nama_produk}` : '-' },
        { label: 'Gudang', value: d.warehouse?.nama_warehouse || '-' },
        { label: 'Supplier', value: d.supplier?.nama_supplier || '-' },
        { label: 'Status', value: getStatusLabel(d.status) },
        { label: 'Total Unit', value: formatNumber(d.total_unit || 0) },
        { label: 'Jatuh Tempo', value: d.tanggal_jatuh_tempo ? formatDateTime(d.tanggal_jatuh_tempo) : '-' }
    ];

    // PDF (read-only): harga & ringkasan finansial hanya untuk yang berizin lihat harga
    let pdfSummary = null;
    if (canViewHarga.value) {
        pdfSummary = [{ label: 'Subtotal', value: formatCurrency(d.subtotal || d.total_modal || 0) }];
        if (Number(d.total_diskon_header) > 0) pdfSummary.push({ label: 'Diskon Header', value: `-${formatCurrency(d.total_diskon_header)}` });
        if (Number(d.biaya_kirim_hasil) > 0) pdfSummary.push({ label: 'Biaya Kirim', value: formatCurrency(d.biaya_kirim_hasil) });
        if (Number(d.biaya_lain_hasil) > 0) pdfSummary.push({ label: d.biaya_lain_nama || 'Biaya Lain', value: formatCurrency(d.biaya_lain_hasil) });
        pdfSummary.push({ label: 'DPP', value: formatCurrency(d.dpp || 0) });
        pdfSummary.push({ label: `${d.pajak_nama || 'Pajak'} (${d.pajak_persen || 0}%)`, value: formatCurrency(d.pajak_nominal || 0) });
        if (d.pembulatan && Number(d.pembulatan) !== 0) pdfSummary.push({ label: 'Pembulatan', value: formatCurrency(d.pembulatan) });
        pdfSummary.push({ separator: true }, { label: 'Grand Total', value: formatCurrency(d.grand_total || 0), bold: true });
    }

    const columns = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'Kode Internal', width: 24, accessor: (r) => r.kode_internal || '-' },
        { header: 'Nomor Seri', accessor: (r) => r.serial_number },
        { header: 'Grade', width: 14, align: 'center', accessor: (r) => r.grade || '-' },
        { header: 'Baterai', width: 22, accessor: (r) => r.battery_condition || '-' },
        { header: 'Health', width: 16, align: 'right', accessor: (r) => (r.battery_health != null ? formatPercent(r.battery_health) : '-') },
        { header: 'Akun', width: 18, align: 'center', accessor: (r) => r.account_status || '-' }
    ];
    // Modal (harga beli) = sensitif → hanya bila berizin view_harga
    if (canViewHarga.value) {
        columns.push({ header: 'Modal', width: 22, align: 'right', accessor: (r) => formatCurrency(r.harga_modal) });
    }
    // Harga Jual bukan rahasia → selalu tampil
    columns.push({ header: 'Jual', width: 22, align: 'right', accessor: (r) => (r.harga_jual != null ? formatCurrency(r.harga_jual) : '-') });

    exportDocumentPdf({
        title: 'Pembelian Serial',
        filename: d.nomor_dokumen || 'pembelian_serial',
        info,
        table: { columns, data: d.units || [] },
        summary: pdfSummary,
        notes: d.notes
    });
}

onMounted(async () => {
    await Promise.all([loadSuppliers(), loadWarehouses()]);
    await loadData();
    // Auto-open detail dokumen (mis. dari kartu stok / register unit serial)
    if (route.query.detail) {
        viewDetail({ ulid: route.query.detail });
    }
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Input Pembelian Serial" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="additionalFilters.supplier_id" :options="suppliers" optionLabel="nama_supplier" optionValue="id" placeholder="Supplier" class="w-40" filter showClear @change="onFilter" />
                    <Select v-model="additionalFilters.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Gudang" class="w-40" filter showClear @change="onFilter" />
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
                <DataTableHeader v-model="searchQuery" title="Daftar Pembelian Serial" placeholder="Cari no. dokumen, produk..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data pembelian serial</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="No. Dokumen" sortable style="min-width: 150px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">{{ formatDateTime(data.tanggal) }}</template>
            </Column>

            <Column header="Produk" style="min-width: 200px">
                <template #body="{ data }">
                    <div>
                        <span class="font-medium">{{ data.product?.nama_produk }}</span>
                        <div class="text-sm text-surface-500">{{ data.product?.kode_produk }}</div>
                    </div>
                </template>
            </Column>

            <Column header="Gudang" style="min-width: 150px">
                <template #body="{ data }">{{ data.warehouse?.nama_warehouse || '-' }}</template>
            </Column>

            <Column header="Supplier" style="min-width: 160px">
                <template #body="{ data }">{{ data.supplier?.nama_supplier || '-' }}</template>
            </Column>

            <Column header="Unit" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.total_unit" severity="secondary" />
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

            <Column header="Aksi" style="min-width: 220px" alignFrozen="right" frozen>
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
            title="Detail Pembelian Serial"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="900px"
        >
            <template #content>
                <div v-if="detailData.ulid">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <DetailItem label="No. Dokumen" :value="detailData.nomor_dokumen" />
                        <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal)" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                        <DetailItem label="No. Referensi" :value="detailData.no_doc_referensi || '-'" />
                        <DetailItem label="Produk" :value="detailData.product ? `${detailData.product.kode_produk} — ${detailData.product.nama_produk}` : '-'" />
                        <DetailItem label="Gudang" :value="detailData.warehouse?.nama_warehouse" />
                        <DetailItem label="Supplier" :value="detailData.supplier?.nama_supplier || '-'" />
                        <DetailItem label="Total Unit" :value="formatNumber(detailData.total_unit ?? 0)" />
                        <DetailItem v-if="detailData.tanggal_jatuh_tempo" label="Jatuh Tempo" :value="formatDateTime(detailData.tanggal_jatuh_tempo)" />
                    </div>

                    <div class="mt-4">
                        <h4 class="text-lg font-medium mb-3">Daftar Unit ({{ unitRows.length }})</h4>
                        <DetailTable :data="unitRows" :columns="unitColumns">
                            <template #grade="{ item }">{{ item.grade || '—' }}</template>
                            <template #battery_condition="{ item }">{{ item.battery_condition || '—' }}</template>
                            <template #battery_health="{ item }">{{ item.battery_health != null ? formatPercent(item.battery_health) : '—' }}</template>
                            <template #account_status="{ item }">
                                <Tag v-if="item.account_status" :value="item.account_status" :severity="item.account_status === 'unlocked' ? 'success' : 'danger'" />
                                <span v-else>—</span>
                            </template>
                            <template #harga_modal="{ item }">{{ formatCurrency(item.harga_modal) }}</template>
                            <template #harga_jual="{ item }">{{ item.harga_jual != null ? formatCurrency(item.harga_jual) : '—' }}</template>
                        </DetailTable>
                    </div>

                    <div v-if="canViewHarga" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                        <div></div>
                        <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4 flex flex-col gap-2 text-sm">
                            <div class="flex justify-between">
                                <span>Subtotal</span><span>{{ formatCurrency(detailData.subtotal || detailData.total_modal) }}</span>
                            </div>
                            <div v-if="Number(detailData.total_diskon_header) > 0" class="flex justify-between text-red-500">
                                <span>Diskon Header</span><span>-{{ formatCurrency(detailData.total_diskon_header) }}</span>
                            </div>
                            <div v-if="Number(detailData.biaya_kirim_hasil) > 0" class="flex justify-between">
                                <span>Biaya Kirim</span><span>{{ formatCurrency(detailData.biaya_kirim_hasil) }}</span>
                            </div>
                            <div v-if="Number(detailData.biaya_lain_hasil) > 0" class="flex justify-between">
                                <span>{{ detailData.biaya_lain_nama || 'Biaya Lain' }}</span
                                ><span>{{ formatCurrency(detailData.biaya_lain_hasil) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>DPP</span><span>{{ formatCurrency(detailData.dpp) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>{{ detailData.pajak_nama || 'Pajak' }} ({{ detailData.pajak_persen || 0 }}%)</span><span>{{ formatCurrency(detailData.pajak_nominal) }}</span>
                            </div>
                            <div v-if="detailData.pembulatan && Number(detailData.pembulatan) != 0" class="flex justify-between">
                                <span>Pembulatan</span><span>{{ formatCurrency(detailData.pembulatan) }}</span>
                            </div>
                            <Divider class="my-1" />
                            <div class="flex justify-between font-bold text-lg">
                                <span>Grand Total</span><span>{{ formatCurrency(detailData.grand_total) }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="detailData.status === 'approved' && detailData.approved_by" class="mt-4 pt-4 border-t border-surface-200">
                        <div class="flex items-center gap-2 text-surface-500 text-sm">
                            <i class="pi pi-check-circle text-green-500"></i>
                            <span>Disetujui: {{ formatDateTime(detailData.approved_at) }} oleh {{ detailData.approved_by?.name }}</span>
                        </div>
                    </div>

                    <div v-if="detailData.notes" class="mt-4">
                        <span class="text-surface-500 text-sm block mb-1">Catatan</span>
                        <p class="m-0">{{ detailData.notes }}</p>
                    </div>
                </div>
            </template>

            <template #footer-extra>
                <Button v-if="detailData.ulid" label="Print Label" icon="pi pi-print" severity="contrast" @click="printLabelVisible = true" />
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
            </template>
        </DetailDialog>

        <SerialLabelPrintDialog v-model:visible="printLabelVisible" :units="detailData.units || []" :context="printContext" :title="`Cetak Label — ${detailData.nomor_dokumen || ''}`" />
    </div>
</template>

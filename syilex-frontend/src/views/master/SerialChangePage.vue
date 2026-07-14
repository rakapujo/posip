<script setup>
import { computed, onMounted } from 'vue';
import { serialChangesApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const { formatCurrency, formatNumber, formatPercent, formatDateTime, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportDocumentPdf } = useExportPdf();

const canCreate = computed(() => authStore.can('serial-change.create'));
const canEdit = computed(() => authStore.can('serial-change.update'));
const canDeletePerm = computed(() => authStore.can('serial-change.delete'));
const canApprove = computed(() => authStore.can('serial-change.approve'));

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
} = useTransactionList(serialChangesApi, {
    entityName: 'perubahan data serial',
    dataKey: 'items',
    detailKey: 'serial_change',
    routePrefix: 'master-serial-change',
    statusOptions: [
        { label: 'Draft', value: 'draft' },
        { label: 'Approved', value: 'approved' }
    ],
    autoLoad: false
});

const detailColumns = [
    { field: 'no', header: '#', width: '50px' },
    { field: 'kode_internal', header: 'Kode Internal' },
    { field: 'serial_number', header: 'Nomor Seri' },
    { field: 'harga_jual', header: 'Harga Jual', align: 'right' },
    { field: 'grade', header: 'Grade', align: 'center', width: '70px' },
    { field: 'battery_condition', header: 'Baterai' },
    { field: 'battery_health', header: 'Health', align: 'right', width: '90px' },
    { field: 'account_status', header: 'Akun', align: 'center', width: '100px' }
];

const detailRows = computed(() => (detailData.value.details || []).map((d, i) => ({ ...d, no: i + 1 })));

// Tampilkan "lama → baru" bila berubah
function diff(item, field, fmt = (v) => v ?? '—', arrow = '→') {
    const before = item.before?.[field];
    const after = item[field];
    const changed = String(before ?? '') !== String(after ?? '');
    return changed ? `${fmt(before)} ${arrow} ${fmt(after)}` : fmt(after);
}

async function exportDocPdf(item) {
    let d = item;
    if (!d.details) {
        try {
            const res = await serialChangesApi.get(d.ulid);
            d = res.data.data.serial_change;
        } catch {
            return;
        }
    }
    // Produk ditaruh terakhir (nilai panjang → baris sendiri, tak menabrak kolom kanan).
    const info = [
        { label: 'No. Dokumen', value: d.nomor_dokumen },
        { label: 'Tanggal', value: formatDateTime(d.tanggal) },
        { label: 'Status', value: getStatusLabel(d.status) },
        { label: 'Total Unit', value: formatNumber(d.total_unit || 0) },
        { label: 'Produk', value: d.product ? `${d.product.kode_produk} - ${d.product.nama_produk}` : '-' }
    ];
    // PDF (jsPDF) tak dukung panah "→" → pakai "->" ASCII.
    const dash = (v) => v ?? '-';
    const columns = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'Kode Internal', width: 24, accessor: (r) => r.serialUnit?.kode_internal || '-' },
        { header: 'Nomor Seri', accessor: (r) => diff(r, 'serial_number', dash, '->') },
        { header: 'Harga Jual', width: 38, align: 'right', accessor: (r) => diff(r, 'harga_jual', (v) => formatCurrency(v || 0), '->') },
        { header: 'Grade', width: 14, align: 'center', accessor: (r) => diff(r, 'grade', dash, '->') },
        { header: 'Baterai', width: 26, accessor: (r) => diff(r, 'battery_condition', dash, '->') },
        { header: 'Health', width: 18, align: 'right', accessor: (r) => diff(r, 'battery_health', (v) => (v != null ? formatPercent(v) : '-'), '->') },
        { header: 'Akun', width: 20, align: 'center', accessor: (r) => diff(r, 'account_status', dash, '->') }
    ];
    const audit = [];
    if (d.created_by?.name) audit.push({ label: 'Dibuat oleh', value: d.created_by.name, date: formatDateTime(d.created_at) });
    if (d.status === 'approved' && d.approved_by?.name) audit.push({ label: 'Disetujui oleh', value: d.approved_by.name, date: formatDateTime(d.approved_at) });

    exportDocumentPdf({
        title: 'Perubahan Data Serial',
        filename: d.nomor_dokumen || 'perubahan_data_serial',
        info,
        table: { columns, data: d.details || [] },
        notes: d.notes,
        audit
    });
}

onMounted(loadData);
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Perubahan Data Serial" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-32" filter showClear @change="onFilter" />
                    <div class="w-40"><DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tgl Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" /></div>
                    <div class="w-40"><DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tgl Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" /></div>
                    <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                </div>
            </template>
        </Toolbar>

        <DataTable
            :value="items"
            :loading="loading"
            lazy
            paginator
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
                <DataTableHeader v-model="searchQuery" title="Perubahan Data Serial" placeholder="Cari no. dokumen / produk..." @search="doSearch" @clear="clearSearch" />
            </template>
            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="No. Dokumen" sortable style="min-width: 150px">
                <template #body="{ data }"
                    ><span class="font-medium">{{ data.nomor_dokumen }}</span></template
                >
            </Column>
            <Column field="tanggal" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">{{ formatDateTime(data.tanggal) }}</template>
            </Column>
            <Column header="Produk" style="min-width: 200px">
                <template #body="{ data }">
                    <div class="font-medium">{{ data.product?.nama_produk }}</div>
                    <div class="text-xs text-surface-500">{{ data.product?.kode_produk }}</div>
                </template>
            </Column>
            <Column header="Unit" style="min-width: 60px; text-align: center">
                <template #body="{ data }"><Badge :value="data.total_unit" severity="secondary" /></template>
            </Column>
            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }"><Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" /></template>
            </Column>
            <Column header="Aksi" style="min-width: 200px" alignFrozen="right" frozen>
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

        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Perubahan Data Serial"
            :loading="loadingDetail"
            width="950px"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <div v-if="detailData.ulid">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                        <DetailItem label="No. Dokumen" :value="detailData.nomor_dokumen" />
                        <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal)" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                        <DetailItem label="Produk" :value="detailData.product ? `${detailData.product.kode_produk} — ${detailData.product.nama_produk}` : '-'" />
                    </div>

                    <h6 class="text-surface-600 font-medium mb-2">Unit Dikoreksi ({{ detailRows.length }}) — <span class="text-surface-400 font-normal">lama → baru</span></h6>
                    <DetailTable :data="detailRows" :columns="detailColumns">
                        <template #kode_internal="{ item }">{{ item.serialUnit?.kode_internal || '—' }}</template>
                        <template #serial_number="{ item }">{{ diff(item, 'serial_number') }}</template>
                        <template #harga_jual="{ item }">{{ diff(item, 'harga_jual', (v) => formatCurrency(v || 0)) }}</template>
                        <template #grade="{ item }">{{ diff(item, 'grade') }}</template>
                        <template #battery_condition="{ item }">{{ diff(item, 'battery_condition') }}</template>
                        <template #battery_health="{ item }">{{ diff(item, 'battery_health', (v) => (v != null ? formatPercent(v) : '—')) }}</template>
                        <template #account_status="{ item }">{{ diff(item, 'account_status') }}</template>
                    </DetailTable>

                    <div v-if="detailData.status === 'approved' && detailData.approved_by" class="mt-4 pt-4 border-t border-surface-200">
                        <div class="flex items-center gap-2 text-surface-500 text-sm">
                            <i class="pi pi-check-circle text-green-500"></i>
                            <span>Disetujui: {{ formatDateTime(detailData.approved_at) }} oleh {{ detailData.approved_by?.name }}</span>
                        </div>
                    </div>
                    <div v-if="detailData.notes" class="mt-4">
                        <span class="text-surface-500 text-sm block mb-1">Alasan / Catatan</span>
                        <p class="m-0">{{ detailData.notes }}</p>
                    </div>
                </div>
            </template>
            <template #footer-extra>
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
    </div>
</template>

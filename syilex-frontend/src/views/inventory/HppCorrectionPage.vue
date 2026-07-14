<script setup>
import { computed } from 'vue';
import { hppCorrectionsApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { useExportPdf } from '@/composables/useExportPdf';
import { useRouter } from 'vue-router';
import { useConfirm } from 'primevue/useconfirm';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const router = useRouter();
const confirm = useConfirm();
const { formatCurrency, formatDateTime, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportDocumentPdf } = useExportPdf();

// Permissions
const canCreate = computed(() => authStore.can('hpp.create'));
const canUpdate = computed(() => authStore.can('hpp.update'));
const canDeletePerm = computed(() => authStore.can('hpp.delete'));
const canApprove = computed(() => authStore.can('hpp.approve'));

// Alasan labels
const alasanLabels = {
    KOREKSI_HARGA_BELI: 'Koreksi Harga Beli',
    KOREKSI_DATA: 'Koreksi Data',
    MIGRASI_SISTEM: 'Migrasi Sistem',
    LAINNYA: 'Lainnya'
};

// Initialize composable
const {
    items: corrections,
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
    onPage,
    onSort,
    doSearch,
    clearSearch,
    onFilter,
    resetFilters,
    editItem,
    viewDetail,
    closeDetail,
    confirmDelete,
    confirmApprove,
    getStatusSeverity,
    getStatusLabel,
    canEdit,
    canDelete,
    canApprove: canApproveItem
} = useTransactionList(hppCorrectionsApi, {
    entityName: 'correction',
    dataKey: 'items',
    routePrefix: 'inventory-hpp-correction',
    filters: [],
    autoLoad: true
});

// Override lazyParams sortField for tanggal_koreksi
lazyParams.value.sortField = 'tanggal_koreksi';

// Detail table columns
const detailColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'product', header: 'Produk' },
    { field: 'hpp_lama', header: 'HPP Lama', align: 'right', width: '120px' },
    { field: 'hpp_baru', header: 'HPP Baru', align: 'right', width: '120px' },
    { field: 'selisih', header: 'Selisih', align: 'right', width: '120px' },
    { field: 'alasan', header: 'Alasan', width: '130px' },
    { field: 'notes', header: 'Notes' }
];

// Custom: checkDraftAndCreate - check existing draft before create
async function checkDraftAndCreate() {
    try {
        const response = await hppCorrectionsApi.checkDraft();
        if (response.data.success && response.data.data.has_draft) {
            const draft = response.data.data.draft;
            confirm.require({
                message: `Sudah ada draft koreksi HPP: ${draft.nomor_dokumen}. Lanjutkan ke draft tersebut?`,
                header: 'Draft Ditemukan',
                icon: 'pi pi-info-circle',
                acceptLabel: 'Lihat Draft',
                rejectLabel: 'Batal',
                accept: () => {
                    router.push({ name: 'inventory-hpp-correction-edit', params: { ulid: draft.ulid } });
                }
            });
        } else {
            router.push({ name: 'inventory-hpp-correction-create' });
        }
    } catch (error) {
        console.error('Failed to check draft:', error);
        router.push({ name: 'inventory-hpp-correction-create' });
    }
}

// Custom: Helpers
function getAlasanLabel(alasan) {
    return alasanLabels[alasan] || alasan;
}

function getDifferenceSeverity(diff) {
    if (diff > 0) return 'text-red-600';
    if (diff < 0) return 'text-green-600';
    return '';
}

function formatDifference(diff) {
    if (diff > 0) return `+${formatCurrency(diff)}`;
    return formatCurrency(diff);
}

// Export document PDF
async function exportDocPdf(item) {
    let data = item;
    if (!data.details) {
        try {
            const response = await hppCorrectionsApi.get(data.ulid);
            data = response.data.data.correction || response.data.data;
        } catch {
            return;
        }
    }

    const info = [
        { label: 'No. Dokumen', value: data.nomor_dokumen },
        { label: 'Tanggal', value: formatDateTime(data.tanggal_koreksi) },
        { label: 'Status', value: getStatusLabel(data.status) }
    ];

    const columns = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'Kode Produk', width: 22, accessor: (row) => row.product?.kode_produk || '' },
        { header: 'Nama Produk', accessor: (row) => row.product?.nama_produk || '' },
        { header: 'HPP Lama', width: 22, align: 'right', accessor: (row) => formatCurrency(row.hpp_lama) },
        { header: 'HPP Baru', width: 22, align: 'right', accessor: (row) => formatCurrency(row.hpp_baru) },
        { header: 'Selisih', width: 22, align: 'right', accessor: (row) => formatDifference(row.hpp_baru - row.hpp_lama) },
        { header: 'Alasan', width: 22, accessor: (row) => getAlasanLabel(row.alasan) }
    ];

    // Summary
    let totalSelisih = 0;
    (data.details || []).forEach((d) => {
        totalSelisih += parseFloat(d.hpp_baru) - parseFloat(d.hpp_lama);
    });
    const summary = [{ label: `Total Produk`, value: `${data.details?.length || 0} item` }, { separator: true }, { label: 'Total Selisih HPP', value: formatDifference(totalSelisih), bold: true }];

    const audit = [];
    if (data.created_by?.name) {
        audit.push({ label: 'Dibuat oleh', value: data.created_by.name, date: formatDateTime(data.created_at) });
    }
    if (data.status === 'approved' && data.approved_by?.name) {
        audit.push({ label: 'Disetujui oleh', value: data.approved_by.name, date: formatDateTime(data.approved_at) });
    }

    exportDocumentPdf({
        title: 'Koreksi HPP',
        filename: data.nomor_dokumen || 'koreksi_hpp',
        info,
        table: { columns, data: data.details || [] },
        summary,
        audit,
        notes: data.notes
    });
}

// Custom: Detail summary computed
const detailSummary = computed(() => {
    if (!detailData.value?.details) return null;

    const details = detailData.value.details;
    const total = details.length;

    let totalSelisih = 0;
    details.forEach((d) => {
        totalSelisih += parseFloat(d.hpp_baru) - parseFloat(d.hpp_lama);
    });

    return { total, totalSelisih };
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Tambah Koreksi HPP" icon="pi pi-plus" severity="primary" @click="checkDraftAndCreate" />
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
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
            :value="corrections"
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
                <DataTableHeader v-model="searchQuery" title="Daftar Koreksi HPP" placeholder="Cari nomor dokumen, catatan..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data koreksi HPP</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="Nomor" sortable style="min-width: 140px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tanggal_koreksi" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal_koreksi) }}
                </template>
            </Column>

            <Column header="Item" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.details_count" severity="secondary" />
                </template>
            </Column>

            <Column header="Catatan" style="min-width: 200px">
                <template #body="{ data }">
                    <span class="text-surface-600 text-sm">{{ data.notes || '-' }}</span>
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
                        <Button v-if="canUpdate && canEdit(data)" icon="pi pi-pencil" severity="warning" text rounded @click="editItem(data)" v-tooltip.top="'Edit'" />
                        <Button v-if="canDeletePerm && canDelete(data)" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'" />
                        <Button v-if="canApprove && canApproveItem(data)" icon="pi pi-check" severity="success" text rounded @click="confirmApprove(data)" v-tooltip.top="'Approve'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Koreksi HPP"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="800px"
        >
            <template #content>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <DetailItem label="Nomor Dokumen" :value="detailData.nomor_dokumen" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                    <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal_koreksi)" />
                    <div></div>
                </div>

                <div class="mb-4" v-if="detailData.notes">
                    <span class="text-surface-500 text-sm block mb-1">Catatan</span>
                    <p class="m-0">{{ detailData.notes }}</p>
                </div>

                <!-- Detail Items Table -->
                <div class="mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <h4 class="text-lg font-medium m-0">Detail Produk ({{ detailData.details?.length || 0 }} item)</h4>
                        <div v-if="detailSummary" class="text-sm">
                            <span :class="detailSummary.totalSelisih > 0 ? 'text-red-600' : detailSummary.totalSelisih < 0 ? 'text-green-600' : ''">
                                Total Selisih: <strong>{{ formatDifference(detailSummary.totalSelisih) }}</strong>
                            </span>
                        </div>
                    </div>
                    <DetailTable :data="detailData.details" :columns="detailColumns">
                        <template #product="{ item }">
                            <span class="font-medium">{{ item.product?.kode_produk }}</span>
                            <br />
                            <span class="text-surface-500 text-sm">{{ item.product?.nama_produk }}</span>
                        </template>
                        <template #hpp_lama="{ item }">{{ formatCurrency(item.hpp_lama) }}</template>
                        <template #hpp_baru="{ item }">
                            <span class="font-medium">{{ formatCurrency(item.hpp_baru) }}</span>
                        </template>
                        <template #selisih="{ item }">
                            <span :class="getDifferenceSeverity(item.hpp_baru - item.hpp_lama)">
                                {{ formatDifference(item.hpp_baru - item.hpp_lama) }}
                            </span>
                        </template>
                        <template #alasan="{ item }">
                            <Tag :value="getAlasanLabel(item.alasan)" severity="secondary" />
                        </template>
                        <template #notes="{ item }">{{ item.notes || '-' }}</template>
                    </DetailTable>
                </div>

                <!-- Approved info -->
                <div class="mt-4 pt-4 border-t border-surface-200" v-if="detailData.status === 'approved' && detailData.approved_by">
                    <div class="flex items-center gap-2 text-surface-500 text-sm">
                        <i class="pi pi-check-circle text-green-500"></i>
                        <span>Disetujui: {{ formatDateTime(detailData.approved_at) }} oleh {{ detailData.approved_by?.name }}</span>
                    </div>
                </div>
            </template>

            <template #footer-extra>
                <div class="flex flex-wrap gap-2">
                    <Button label="Export PDF" icon="pi pi-file-pdf" severity="help" outlined :loading="exporting" @click="exportDocPdf(detailData)" />
                    <Button
                        v-if="canUpdate && canEdit(detailData)"
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

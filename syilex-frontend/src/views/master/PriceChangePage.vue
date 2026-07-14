<script setup>
import { ref, computed } from 'vue';
import { priceChangesApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import { useRouter } from 'vue-router';
import { useNotification } from '@/composables/useNotification';
import { useConfirm } from 'primevue/useconfirm';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const router = useRouter();
const notify = useNotification();
const confirm = useConfirm();
const { formatCurrency, formatDateTime, getPrimeDateFormatShort, formatQty } = useFormatters();
const { exporting: exportingDetail, exportDocumentPdf } = useExportPdf();

// Permissions
const canCreate = computed(() => authStore.can('price-change.create'));
const canUpdate = computed(() => authStore.can('price-change.update'));
const canDeletePerm = computed(() => authStore.can('price-change.delete'));
const canApprovePerm = computed(() => authStore.can('price-change.approve'));
const canApplyPerm = computed(() => authStore.can('price-change.apply'));

// Alasan labels
const alasanLabels = {
    PENYESUAIAN_PASAR: 'Penyesuaian Harga Pasar',
    KENAIKAN_BIAYA: 'Kenaikan Biaya Operasional',
    PROMO: 'Program Promo',
    KOREKSI_DATA: 'Koreksi Data',
    LAINNYA: 'Lainnya'
};

// Custom status options for price change (3 statuses)
const customStatusOptions = [
    { label: 'Draft', value: 'draft' },
    { label: 'Scheduled', value: 'scheduled' },
    { label: 'Applied', value: 'applied' }
];

// Initialize composable
const {
    items: priceChanges,
    loading,
    totalRecords,
    searchQuery,
    lazyParams,
    selectedStatus,
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
    editItem,
    viewDetail,
    closeDetail,
    confirmDelete,
    canEdit,
    canDelete
} = useTransactionList(priceChangesApi, {
    entityName: 'perubahan harga',
    dataKey: 'items',
    detailKey: 'price_change', // Backend returns { price_change: {...} }
    routePrefix: 'master-price-change',
    filters: [],
    statusOptions: customStatusOptions,
    autoLoad: true
});

// Override lazyParams sortField for tanggal_berlaku
lazyParams.value.sortField = 'tanggal_berlaku';

// Custom status severity for 3 statuses
function getStatusSeverity(status) {
    switch (status) {
        case 'draft':
            return 'warn';
        case 'scheduled':
            return 'info';
        case 'applied':
            return 'success';
        default:
            return 'secondary';
    }
}

// Custom status label
function getStatusLabel(status) {
    switch (status) {
        case 'draft':
            return 'Draft';
        case 'scheduled':
            return 'Scheduled';
        case 'applied':
            return 'Applied';
        default:
            return status;
    }
}

// Can approve (draft only)
function canApproveItem(data) {
    return data.status === 'draft';
}

// Can cancel (scheduled only)
function canCancelItem(data) {
    return data.status === 'scheduled';
}

// Can apply (scheduled only)
function canApplyItem(data) {
    return data.status === 'scheduled';
}

// Processing states
const processingCancel = ref(false);
const processingApply = ref(false);

// Approve action
function confirmApprove(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin menyetujui perubahan harga "${data.nomor_dokumen}"? Dokumen akan masuk status "Scheduled" dan akan dieksekusi sesuai tanggal berlaku.`,
        header: 'Konfirmasi Approve',
        icon: 'pi pi-question-circle',
        acceptLabel: 'Ya, Approve',
        rejectLabel: 'Batal',
        acceptClass: 'p-button-success',
        accept: async () => {
            processingApprove.value = true;
            try {
                await priceChangesApi.approve(data.ulid);
                notify.approved('Perubahan harga');
                loadData();
                if (detailDialog.value) closeDetail();
            } catch (error) {
                notify.approveError(error);
            } finally {
                processingApprove.value = false;
            }
        }
    });
}

// Cancel action
function confirmCancel(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin membatalkan jadwal "${data.nomor_dokumen}"? Dokumen akan kembali ke status "Draft" dan dapat diedit kembali.`,
        header: 'Konfirmasi Batal',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Ya, Batalkan',
        rejectLabel: 'Tidak',
        acceptClass: 'p-button-warning',
        accept: async () => {
            processingCancel.value = true;
            try {
                await priceChangesApi.cancel(data.ulid);
                notify.success(`Jadwal ${data.nomor_dokumen} berhasil dibatalkan`);
                loadData();
                if (detailDialog.value) closeDetail();
            } catch (error) {
                notify.error(error.response?.data?.message || 'Gagal membatalkan jadwal');
            } finally {
                processingCancel.value = false;
            }
        }
    });
}

// Apply action
function confirmApply(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin mengeksekusi perubahan harga "${data.nomor_dokumen}" SEKARANG? Harga produk akan langsung berubah.`,
        header: 'Konfirmasi Apply',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Ya, Apply Sekarang',
        rejectLabel: 'Batal',
        acceptClass: 'p-button-danger',
        accept: async () => {
            processingApply.value = true;
            try {
                await priceChangesApi.apply(data.ulid);
                notify.success(`Perubahan harga ${data.nomor_dokumen} berhasil dieksekusi`);
                loadData();
                if (detailDialog.value) closeDetail();
            } catch (error) {
                notify.error(error.response?.data?.message || 'Gagal mengeksekusi perubahan harga');
            } finally {
                processingApply.value = false;
            }
        }
    });
}

// Navigate to create page
function createNew() {
    router.push({ name: 'master-price-change-create' });
}

// Helper to calculate selisih per unit
function getSelisih(item, unit) {
    const lama = parseFloat(item[`harga_${unit}_lama`]) || 0;
    const baru = parseFloat(item[`harga_${unit}_baru`]) || 0;
    return baru - lama;
}

// Get alasan from first detail (header level)
const detailAlasan = computed(() => {
    if (!detailData.value?.details?.length) return null;
    return detailData.value.details[0]?.alasan;
});

// Helpers
function getAlasanLabel(alasan) {
    return alasanLabels[alasan] || alasan;
}

// Untuk Harga Jual: naik = baik (hijau), turun = kurang baik (merah)
// Kebalikan dari HPP dimana naik = buruk
function getDifferenceSeverity(diff) {
    if (diff > 0) return 'text-green-600'; // Harga naik = pendapatan naik = baik
    if (diff < 0) return 'text-red-600'; // Harga turun = pendapatan turun = kurang baik
    return '';
}

function formatDifference(diff) {
    if (diff > 0) return `+${formatCurrency(diff)}`;
    return formatCurrency(diff);
}

// Export detail PDF
async function downloadDetailPdf(data) {
    let d = data;
    // Jika dipanggil dari list (belum ada detail lengkap), fetch dulu
    if (!d.details) {
        try {
            const response = await priceChangesApi.get(d.ulid);
            if (!response.data.success) return;
            d = response.data.data.price_change;
        } catch {
            return;
        }
    }

    const alasan = d.details?.[0]?.alasan;

    const info = [
        { label: 'Nomor Dokumen', value: d.nomor_dokumen || '-' },
        { label: 'Status', value: getStatusLabel(d.status) },
        { label: 'Alasan', value: alasan ? getAlasanLabel(alasan) : '-' },
        { label: 'Tanggal Pengajuan', value: d.tanggal_pengajuan ? formatDateTime(d.tanggal_pengajuan) : '-' },
        { label: 'Tanggal Berlaku', value: d.tanggal_berlaku ? formatDateTime(d.tanggal_berlaku) : '-' }
    ];
    if (d.notes) {
        info.push({ label: 'Catatan', value: d.notes });
    }

    // Build flat table: 3 rows per product (Harga Lama, Harga Baru, Selisih)
    const columns = [
        { header: '#', field: '#', align: 'center' },
        { header: 'Produk', field: 'produk' },
        { header: 'Baris', field: 'baris' },
        { header: 'Unit 1', field: 'unit1', align: 'right' },
        { header: 'Unit 2', field: 'unit2', align: 'right' },
        { header: 'Unit 3', field: 'unit3', align: 'right' },
        { header: 'Unit 4', field: 'unit4', align: 'right' },
        { header: 'Notes', field: 'notes' }
    ];

    const tableData = [];
    (d.details || []).forEach((item, idx) => {
        const p = item.product;
        const fmtUnit = (val, unit, konv) => `${formatCurrency(val)} /${unit} (${formatQty(konv)})`;
        const produkLabel = `[${p?.kode_produk}] ${p?.nama_produk}`;

        // Row 1: Harga Lama
        tableData.push({
            produk: produkLabel,
            baris: 'Harga Lama',
            unit1: fmtUnit(item.harga_1_lama, p?.unit_1, p?.konversi_1),
            unit2: fmtUnit(item.harga_2_lama, p?.unit_2, p?.konversi_2),
            unit3: fmtUnit(item.harga_3_lama, p?.unit_3, p?.konversi_3),
            unit4: fmtUnit(item.harga_4_lama, p?.unit_4, p?.konversi_4),
            notes: idx === 0 ? item.notes || '-' : ''
        });
        // Row 2: Harga Baru
        tableData.push({
            produk: '',
            baris: 'Harga Baru',
            unit1: fmtUnit(item.harga_1_baru, p?.unit_1, p?.konversi_1),
            unit2: fmtUnit(item.harga_2_baru, p?.unit_2, p?.konversi_2),
            unit3: fmtUnit(item.harga_3_baru, p?.unit_3, p?.konversi_3),
            unit4: fmtUnit(item.harga_4_baru, p?.unit_4, p?.konversi_4),
            notes: ''
        });
        // Row 3: Selisih
        const fmtDiff = (unit) => {
            const diff = getSelisih(item, unit);
            return formatDifference(diff);
        };
        tableData.push({
            produk: '',
            baris: 'Selisih',
            unit1: fmtDiff(1),
            unit2: fmtDiff(2),
            unit3: fmtDiff(3),
            unit4: fmtDiff(4),
            notes: ''
        });
    });

    // Custom accessor for # — only show number on first row of each group
    const columnsWithAccessor = columns.map((col) => {
        if (col.field === '#') {
            return { ...col, accessor: (row, idx) => (idx % 3 === 0 ? String(Math.floor(idx / 3) + 1) : '') };
        }
        return col;
    });

    const audit = [];
    if (d.created_at) audit.push({ label: 'Dibuat oleh', value: d.created_by?.name || '-', date: d.created_at });
    if (d.approved_at) audit.push({ label: 'Disetujui oleh', value: d.approved_by?.name || '-', date: d.approved_at });
    if (d.applied_at) audit.push({ label: 'Dieksekusi oleh', value: d.applied_by?.name || '(Auto)', date: d.applied_at });

    await exportDocumentPdf({
        title: 'Perubahan Harga',
        filename: d.nomor_dokumen || 'perubahan_harga',
        info,
        table: { columns: columnsWithAccessor, data: tableData },
        audit
    });
}
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Tambah Perubahan Harga" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="selectedStatus" :options="customStatusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-32" filter showClear @change="onFilter" />
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
            :value="priceChanges"
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
                <DataTableHeader v-model="searchQuery" title="Daftar Perubahan Harga" placeholder="Cari nomor dokumen, catatan..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data perubahan harga</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="Nomor" sortable style="min-width: 140px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tanggal_pengajuan" header="Tgl Pengajuan" sortable style="min-width: 130px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal_pengajuan) }}
                </template>
            </Column>

            <Column field="tanggal_berlaku" header="Tgl Berlaku" sortable style="min-width: 130px">
                <template #body="{ data }">
                    <span class="font-medium text-primary">{{ formatDateTime(data.tanggal_berlaku) }}</span>
                </template>
            </Column>

            <Column header="Item" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.details_count" severity="secondary" />
                </template>
            </Column>

            <Column header="Catatan" style="min-width: 150px">
                <template #body="{ data }">
                    <span class="text-surface-600 text-sm">{{ data.notes || '-' }}</span>
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
                        <Button icon="pi pi-file-pdf" severity="help" text rounded @click="downloadDetailPdf(data)" v-tooltip.top="'Download PDF'" />
                        <Button v-if="canUpdate && canEdit(data)" icon="pi pi-pencil" severity="warning" text rounded @click="editItem(data)" v-tooltip.top="'Edit'" />
                        <Button v-if="canDeletePerm && canDelete(data)" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'" />
                        <Button v-if="canApprovePerm && canApproveItem(data)" icon="pi pi-check" severity="success" text rounded @click="confirmApprove(data)" v-tooltip.top="'Approve'" />
                        <Button v-if="canApprovePerm && canCancelItem(data)" icon="pi pi-times" severity="warn" text rounded @click="confirmCancel(data)" v-tooltip.top="'Batalkan Jadwal'" />
                        <Button v-if="canApplyPerm && canApplyItem(data)" icon="pi pi-play" severity="danger" text rounded @click="confirmApply(data)" v-tooltip.top="'Apply Sekarang'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Perubahan Harga"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="1200px"
        >
            <template #content>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <DetailItem label="Nomor Dokumen" :value="detailData.nomor_dokumen" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                    <DetailItem label="Alasan" :value="getAlasanLabel(detailAlasan)" type="badge" badge-severity="secondary" v-if="detailAlasan" />
                    <DetailItem label="Tanggal Pengajuan" :value="formatDateTime(detailData.tanggal_pengajuan)" />
                    <DetailItem label="Tanggal Berlaku" :value="formatDateTime(detailData.tanggal_berlaku)" />
                </div>

                <div class="mb-4" v-if="detailData.notes">
                    <span class="text-surface-500 text-sm block mb-1">Catatan</span>
                    <p class="m-0">{{ detailData.notes }}</p>
                </div>

                <!-- Detail Items Table -->
                <div class="mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <h4 class="text-lg font-medium m-0">Detail Produk ({{ detailData.details?.length || 0 }} item)</h4>
                    </div>

                    <!-- Custom Table with Rowspan -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border-collapse">
                            <thead>
                                <tr class="bg-surface-100">
                                    <th class="border border-surface-200 px-3 py-2 text-left w-10">#</th>
                                    <th class="border border-surface-200 px-3 py-2 text-left" style="min-width: 160px">Produk</th>
                                    <th class="border border-surface-200 px-3 py-2 text-left w-24">Perubahan</th>
                                    <th class="border border-surface-200 px-3 py-2 text-right" style="min-width: 150px">Unit 1</th>
                                    <th class="border border-surface-200 px-3 py-2 text-right" style="min-width: 150px">Unit 2</th>
                                    <th class="border border-surface-200 px-3 py-2 text-right" style="min-width: 150px">Unit 3</th>
                                    <th class="border border-surface-200 px-3 py-2 text-right" style="min-width: 150px">Unit 4</th>
                                    <th class="border border-surface-200 px-3 py-2 text-left" style="min-width: 100px">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="(item, index) in detailData.details" :key="item.id">
                                    <!-- Row 1: Harga Lama -->
                                    <tr class="bg-white">
                                        <td class="border border-surface-200 px-3 py-2 text-center align-top font-medium" rowspan="3">{{ index + 1 }}</td>
                                        <td class="border border-surface-200 px-3 py-2 align-top" rowspan="3">
                                            <div class="font-medium">{{ item.product?.kode_produk }}</div>
                                            <div class="text-surface-500 text-xs">{{ item.product?.nama_produk }}</div>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-surface-500 text-xs">Harga Lama</td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs">
                                            {{ formatCurrency(item.harga_1_lama) }} <span class="text-surface-400">/{{ item.product?.unit_1 }} ({{ formatQty(item.product?.konversi_1) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs">
                                            {{ formatCurrency(item.harga_2_lama) }} <span class="text-surface-400">/{{ item.product?.unit_2 }} ({{ formatQty(item.product?.konversi_2) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs">
                                            {{ formatCurrency(item.harga_3_lama) }} <span class="text-surface-400">/{{ item.product?.unit_3 }} ({{ formatQty(item.product?.konversi_3) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs">
                                            {{ formatCurrency(item.harga_4_lama) }} <span class="text-surface-400">/{{ item.product?.unit_4 }} ({{ formatQty(item.product?.konversi_4) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-2 align-top text-surface-600 text-xs" rowspan="3">{{ item.notes || '-' }}</td>
                                    </tr>
                                    <!-- Row 2: Harga Baru -->
                                    <tr class="bg-white">
                                        <td class="border border-surface-200 px-3 py-1 text-surface-500 text-xs">Harga Baru</td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs font-medium">
                                            {{ formatCurrency(item.harga_1_baru) }} <span class="text-surface-400 font-normal">/{{ item.product?.unit_1 }} ({{ formatQty(item.product?.konversi_1) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs font-medium">
                                            {{ formatCurrency(item.harga_2_baru) }} <span class="text-surface-400 font-normal">/{{ item.product?.unit_2 }} ({{ formatQty(item.product?.konversi_2) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs font-medium">
                                            {{ formatCurrency(item.harga_3_baru) }} <span class="text-surface-400 font-normal">/{{ item.product?.unit_3 }} ({{ formatQty(item.product?.konversi_3) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs font-medium">
                                            {{ formatCurrency(item.harga_4_baru) }} <span class="text-surface-400 font-normal">/{{ item.product?.unit_4 }} ({{ formatQty(item.product?.konversi_4) }})</span>
                                        </td>
                                    </tr>
                                    <!-- Row 3: Selisih -->
                                    <tr class="bg-surface-50 border-b-2 border-surface-300">
                                        <td class="border border-surface-200 px-3 py-1 text-surface-500 text-xs">Selisih</td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs" :class="getDifferenceSeverity(getSelisih(item, 1))">
                                            {{ formatDifference(getSelisih(item, 1)) }} <span class="text-surface-400">/{{ item.product?.unit_1 }} ({{ formatQty(item.product?.konversi_1) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs" :class="getDifferenceSeverity(getSelisih(item, 2))">
                                            {{ formatDifference(getSelisih(item, 2)) }} <span class="text-surface-400">/{{ item.product?.unit_2 }} ({{ formatQty(item.product?.konversi_2) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs" :class="getDifferenceSeverity(getSelisih(item, 3))">
                                            {{ formatDifference(getSelisih(item, 3)) }} <span class="text-surface-400">/{{ item.product?.unit_3 }} ({{ formatQty(item.product?.konversi_3) }})</span>
                                        </td>
                                        <td class="border border-surface-200 px-3 py-1 text-right text-xs" :class="getDifferenceSeverity(getSelisih(item, 4))">
                                            {{ formatDifference(getSelisih(item, 4)) }} <span class="text-surface-400">/{{ item.product?.unit_4 }} ({{ formatQty(item.product?.konversi_4) }})</span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Approved info -->
                <div class="mt-4 pt-4 border-t border-surface-200" v-if="detailData.approved_by">
                    <div class="flex items-center gap-2 text-surface-500 text-sm">
                        <i class="pi pi-check-circle text-blue-500"></i>
                        <span>Disetujui: {{ formatDateTime(detailData.approved_at) }} oleh {{ detailData.approved_by?.name }}</span>
                    </div>
                </div>

                <!-- Applied info -->
                <div class="mt-2" v-if="detailData.status === 'applied' && detailData.applied_at">
                    <div class="flex items-center gap-2 text-surface-500 text-sm">
                        <i class="pi pi-play text-green-500"></i>
                        <span>Dieksekusi: {{ formatDateTime(detailData.applied_at) }} {{ detailData.applied_by ? `oleh ${detailData.applied_by?.name}` : '(Auto)' }}</span>
                    </div>
                </div>
            </template>

            <template #footer-extra>
                <div class="flex flex-wrap gap-2">
                    <Button label="Download PDF" icon="pi pi-file-pdf" severity="help" outlined :loading="exportingDetail" @click="downloadDetailPdf(detailData)" />
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
                    <Button v-if="canApprovePerm && canApproveItem(detailData)" label="Approve" icon="pi pi-check" severity="success" :loading="processingApprove" @click="confirmApprove(detailData)" />
                    <Button v-if="canApprovePerm && canCancelItem(detailData)" label="Batalkan Jadwal" icon="pi pi-times" severity="warn" :loading="processingCancel" @click="confirmCancel(detailData)" />
                    <Button v-if="canApplyPerm && canApplyItem(detailData)" label="Apply Sekarang" icon="pi pi-play" severity="danger" :loading="processingApply" @click="confirmApply(detailData)" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

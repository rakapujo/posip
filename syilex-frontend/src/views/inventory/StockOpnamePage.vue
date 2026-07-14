<script setup>
import { ref, computed, onMounted } from 'vue';
import { opnamesApi, warehousesApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { useExportPdf } from '@/composables/useExportPdf';
import { useNotification } from '@/composables/useNotification';
import { useConfirm } from 'primevue/useconfirm';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const confirm = useConfirm();
const notify = useNotification();
const { formatQty, formatCurrency, formatDateTime, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportDocumentPdf } = useExportPdf();

// Permissions
const canCreate = computed(() => authStore.can('opname.create'));
const canUpdate = computed(() => authStore.can('opname.update'));
const canDeletePerm = computed(() => authStore.can('opname.delete'));
const canApprove = computed(() => authStore.can('opname.approve'));
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));

// Warehouses for filter
const warehouses = ref([]);

// Mode options for filter
const modeOptions = ref([
    { label: 'Full', value: 'full' },
    { label: 'Partial', value: 'partial' }
]);

// Initialize composable with custom status options (3 statuses)
const {
    items: opnames,
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
    getStatusSeverity,
    getStatusLabel,
    canEdit,
    canDelete,
    canApprove: canApproveItem
} = useTransactionList(opnamesApi, {
    entityName: 'opname',
    dataKey: 'items',
    routePrefix: 'inventory-opname',
    filters: [
        { key: 'warehouse_id', default: null },
        { key: 'mode', default: null }
    ],
    statusOptions: [
        { label: 'Draft', value: 'draft' },
        { label: 'Approved', value: 'approved' },
        { label: 'Cancelled', value: 'cancelled' }
    ],
    autoLoad: false
});

// Override lazyParams sortField for tanggal_opname
lazyParams.value.sortField = 'tanggal_opname';

// Detail table columns (dynamic based on permission)
const detailColumns = computed(() => {
    const cols = [
        { field: '#', header: '#', width: '40px' },
        { field: 'product', header: 'Produk' },
        { field: 'qty_system', header: 'Stok Sistem', align: 'right', width: '100px' }
    ];
    if (canViewHpp.value) {
        cols.push({ field: 'hpp_unit', header: 'HPP/Unit', align: 'right', width: '110px' });
    }
    cols.push({ field: 'qty_physical', header: 'Stok Fisik', align: 'right', width: '100px' });
    if (canViewHpp.value) {
        cols.push({ field: 'nilai', header: 'Nilai', align: 'right', width: '120px' });
    }
    cols.push({ field: 'selisih', header: 'Selisih', align: 'right', width: '90px' });
    if (canViewHpp.value) {
        cols.push({ field: 'nilai_selisih', header: 'Nilai Selisih', align: 'right', width: '120px' });
    }
    cols.push({ field: 'notes', header: 'Notes' });
    return cols;
});

// Load warehouses for filter dropdown
async function loadWarehouses() {
    try {
        const response = await warehousesApi.getList();
        if (response.data.success) {
            warehouses.value = response.data.data.warehouses;
        }
    } catch (error) {
        console.error('Failed to load warehouses:', error);
        notify.apiError(error, 'Gagal load warehouses');
    }
}

// Custom: Mode helpers
function getModeLabel(mode) {
    return mode === 'full' ? 'Full' : 'Partial';
}

function getModeSeverity(mode) {
    return mode === 'full' ? 'info' : 'secondary';
}

// Custom: Difference helpers
function getDifferenceSeverity(diff) {
    if (diff > 0) return 'text-green-600';
    if (diff < 0) return 'text-red-500';
    return '';
}

function formatDifference(diff) {
    if (diff > 0) return `+${formatQty(diff)}`;
    return formatQty(diff);
}

// Custom: HPP calculation functions
function calculateNilaiPersediaan(detail) {
    const qty = detail.qty_physical ?? 0;
    const avgCost = detail.product?.avg_cost ?? 0;
    return qty * avgCost;
}

function calculateNilaiSelisih(detail) {
    const diff = detail.qty_difference ?? 0;
    const avgCost = detail.product?.avg_cost ?? 0;
    return diff * avgCost;
}

// Custom: Detail summary computed
const detailSummary = computed(() => {
    if (!detailData.value?.details) return null;

    const details = detailData.value.details;
    const total = details.length;
    const match = details.filter((d) => d.qty_difference === 0).length;
    const surplus = details.filter((d) => d.qty_difference > 0).length;
    const shortage = details.filter((d) => d.qty_difference < 0).length;

    let totalNilaiPersediaan = 0;
    let totalNilaiSelisih = 0;

    details.forEach((d) => {
        totalNilaiPersediaan += calculateNilaiPersediaan(d);
        totalNilaiSelisih += calculateNilaiSelisih(d);
    });

    return {
        total,
        match,
        surplus,
        shortage,
        totalNilaiPersediaan,
        totalNilaiSelisih
    };
});

// Export document PDF
async function exportDocPdf(item) {
    let data = item;
    if (!data.details) {
        try {
            const response = await opnamesApi.get(data.ulid);
            data = response.data.data.opname || response.data.data;
        } catch {
            return;
        }
    }

    const info = [
        { label: 'No. Dokumen', value: data.nomor_dokumen },
        { label: 'Tanggal Opname', value: formatDateTime(data.tanggal_opname) },
        { label: 'Warehouse', value: data.warehouse?.nama_warehouse || '-' },
        { label: 'Mode', value: getModeLabel(data.mode) },
        { label: 'Status', value: getStatusLabel(data.status) }
    ];

    // Build columns (dynamic based on HPP permission)
    const columns = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'Kode Produk', width: 20, accessor: (row) => row.product?.kode_produk || '' },
        {
            header: 'Nama Produk',
            accessor: (row) => {
                let s = row.product?.nama_produk || '';
                if (row.serial_units?.length) {
                    s += '\n(hadir) ' + row.serial_units.map((u) => `• ${u.kode_internal || '-'} / SN ${u.serial_number || '-'}`).join('\n');
                }
                return s;
            }
        },
        { header: 'Stok Sistem', width: 16, align: 'right', accessor: (row) => formatQty(row.qty_system) }
    ];
    if (canViewHpp.value) {
        columns.push({ header: 'HPP/Unit', width: 18, align: 'right', accessor: (row) => formatCurrency(row.product?.avg_cost ?? 0) });
    }
    columns.push({ header: 'Stok Fisik', width: 16, align: 'right', accessor: (row) => formatQty(row.qty_physical) });
    if (canViewHpp.value) {
        columns.push({ header: 'Nilai', width: 20, align: 'right', accessor: (row) => formatCurrency((row.qty_physical ?? 0) * (row.product?.avg_cost ?? 0)) });
    }
    columns.push({
        header: 'Selisih',
        width: 14,
        align: 'right',
        accessor: (row) => {
            const diff = row.qty_difference ?? 0;
            return diff > 0 ? `+${formatQty(diff)}` : formatQty(diff);
        }
    });
    if (canViewHpp.value) {
        columns.push({
            header: 'Nilai Selisih',
            width: 20,
            align: 'right',
            accessor: (row) => {
                const val = (row.qty_difference ?? 0) * (row.product?.avg_cost ?? 0);
                return formatCurrency(val);
            }
        });
    }

    // Summary (HPP values if permitted)
    let summary = null;
    if (canViewHpp.value && data.details?.length > 0) {
        let totalNilai = 0;
        let totalSelisih = 0;
        data.details.forEach((d) => {
            totalNilai += (d.qty_physical ?? 0) * (d.product?.avg_cost ?? 0);
            totalSelisih += (d.qty_difference ?? 0) * (d.product?.avg_cost ?? 0);
        });
        summary = [{ label: 'Total Nilai Persediaan', value: formatCurrency(totalNilai) }, { separator: true }, { label: 'Total Nilai Selisih', value: formatCurrency(totalSelisih), bold: true }];
    }

    const audit = [];
    if (data.created_by?.name) {
        audit.push({ label: 'Dibuat oleh', value: data.created_by.name, date: formatDateTime(data.created_at) });
    }
    if (data.status === 'approved' && data.approved_by?.name) {
        audit.push({ label: 'Disetujui oleh', value: data.approved_by.name, date: formatDateTime(data.approved_at) });
    }
    if (data.adjustment) {
        audit.push({ label: 'Adjustment', value: `${data.adjustment.nomor_dokumen} (${data.adjustment.status})` });
    }

    exportDocumentPdf({
        title: 'Stock Opname',
        filename: data.nomor_dokumen || 'stock_opname',
        info,
        table: { columns, data: data.details || [] },
        summary,
        audit,
        notes: data.notes
    });
}

// Custom: Override confirmApprove with special message
function customConfirmApprove(data) {
    const hasDifference = data.details?.some((d) => d.qty_difference !== 0) || detailData.value?.details?.some((d) => d.qty_difference !== 0);
    const message = hasDifference ? `Apakah Anda yakin ingin menyetujui stock opname ${data.nomor_dokumen}? Adjustment akan dibuat otomatis untuk selisih stok.` : `Apakah Anda yakin ingin menyetujui stock opname ${data.nomor_dokumen}?`;

    confirm.require({
        message,
        header: 'Konfirmasi Approve',
        icon: 'pi pi-check-circle',
        rejectLabel: 'Batal',
        acceptLabel: 'Approve',
        rejectClass: 'p-button-secondary p-button-outlined',
        acceptClass: 'p-button-success',
        accept: async () => {
            processingApprove.value = true;
            try {
                const response = await opnamesApi.approve(data.ulid);
                if (response.data.success) {
                    notify.approved('Stock opname');
                    loadData();
                    if (detailDialog.value && detailData.value.ulid === data.ulid) {
                        detailData.value = response.data.data.opname;
                    }
                }
            } catch (error) {
                console.error('Failed to approve stock opname:', error);
                notify.approveError(error);
            } finally {
                processingApprove.value = false;
            }
        }
    });
}

// Load data on mount
onMounted(async () => {
    await Promise.all([loadWarehouses(), loadData()]);
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Buat Stock Opname" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="additionalFilters.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Warehouse" class="w-40" filter showClear @change="onFilter" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-32" filter showClear @change="onFilter" />
                    <Select v-model="additionalFilters.mode" :options="modeOptions" optionLabel="label" optionValue="value" placeholder="Mode" class="w-28" filter showClear @change="onFilter" />
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
            :value="opnames"
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
                <DataTableHeader v-model="searchQuery" title="Daftar Stock Opname" placeholder="Cari nomor dokumen, catatan..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data stock opname</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="Nomor" sortable style="min-width: 140px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tanggal_opname" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal_opname) }}
                </template>
            </Column>

            <Column header="Warehouse" style="min-width: 150px">
                <template #body="{ data }">
                    {{ data.warehouse?.nama_warehouse || '-' }}
                </template>
            </Column>

            <Column header="Mode" style="min-width: 80px">
                <template #body="{ data }">
                    <Tag :value="getModeLabel(data.mode)" :severity="getModeSeverity(data.mode)" />
                </template>
            </Column>

            <Column header="Item" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.details_count" severity="secondary" />
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
                        <Button v-if="canApprove && canApproveItem(data)" icon="pi pi-check" severity="success" text rounded @click="customConfirmApprove(data)" v-tooltip.top="'Approve'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Stock Opname"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="900px"
        >
            <template #content>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <DetailItem label="Nomor Dokumen" :value="detailData.nomor_dokumen" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                    <DetailItem label="Warehouse" :value="detailData.warehouse?.nama_warehouse" />
                    <DetailItem label="Tanggal Opname" :value="formatDateTime(detailData.tanggal_opname)" />
                    <DetailItem label="Mode" :value="getModeLabel(detailData.mode)" type="badge" :badge-severity="getModeSeverity(detailData.mode)" />
                </div>

                <div class="mb-4" v-if="detailData.notes">
                    <span class="text-surface-500 text-sm block mb-1">Catatan</span>
                    <p class="m-0">{{ detailData.notes }}</p>
                </div>

                <!-- Detail Items Table -->
                <div class="mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <h4 class="text-lg font-medium m-0">Detail Produk ({{ detailData.details?.length || 0 }} item)</h4>
                        <div v-if="detailSummary" class="flex flex-wrap gap-3 text-sm">
                            <span class="text-surface-500"
                                >Cocok: <strong>{{ detailSummary.match }}</strong></span
                            >
                            <span class="text-green-600"
                                >Lebih: <strong>{{ detailSummary.surplus }}</strong></span
                            >
                            <span class="text-red-500"
                                >Kurang: <strong>{{ detailSummary.shortage }}</strong></span
                            >
                            <template v-if="canViewHpp">
                                <span class="border-l border-surface-300 pl-3"
                                    >Total: <strong>{{ formatCurrency(detailSummary.totalNilaiPersediaan) }}</strong></span
                                >
                                <span :class="detailSummary.totalNilaiSelisih >= 0 ? 'text-green-600' : 'text-red-500'">
                                    Selisih: <strong>{{ formatCurrency(detailSummary.totalNilaiSelisih) }}</strong>
                                </span>
                            </template>
                        </div>
                    </div>
                    <DetailTable :data="detailData.details" :columns="detailColumns">
                        <template #product="{ item }">
                            <span class="font-medium">{{ item.product?.kode_produk }}</span>
                            <br />
                            <span class="text-surface-500 text-sm">{{ item.product?.nama_produk }}</span>
                            <div v-if="item.serial_units?.length" class="mt-1 space-y-0.5">
                                <div class="text-[10px] text-surface-400">Unit hadir:</div>
                                <div v-for="(u, ui) in item.serial_units" :key="ui" class="text-xs font-mono text-surface-500">
                                    {{ u.kode_internal || u.serial_number }}<span v-if="u.serial_number"> · SN {{ u.serial_number }}</span
                                    ><span v-if="u.grade"> · {{ u.grade }}</span>
                                </div>
                            </div>
                        </template>
                        <template #qty_system="{ item }">{{ formatQty(item.qty_system) }}</template>
                        <template #hpp_unit="{ item }">
                            <span class="text-surface-600">{{ formatCurrency(item.product?.avg_cost ?? 0) }}</span>
                        </template>
                        <template #qty_physical="{ item }">{{ formatQty(item.qty_physical) }}</template>
                        <template #nilai="{ item }">
                            <span class="font-medium">{{ formatCurrency(calculateNilaiPersediaan(item)) }}</span>
                        </template>
                        <template #selisih="{ item }">
                            <span :class="getDifferenceSeverity(item.qty_difference)">
                                {{ formatDifference(item.qty_difference) }}
                            </span>
                        </template>
                        <template #nilai_selisih="{ item }">
                            <span :class="getDifferenceSeverity(item.qty_difference)">
                                {{ formatCurrency(calculateNilaiSelisih(item)) }}
                            </span>
                        </template>
                        <template #notes="{ item }">{{ item.notes || '-' }}</template>
                    </DetailTable>
                </div>

                <!-- Generated Adjustment info -->
                <div class="mt-4 pt-4 border-t border-surface-200" v-if="detailData.adjustment">
                    <div class="flex items-center gap-2 text-surface-600 text-sm">
                        <i class="pi pi-file text-blue-500"></i>
                        <span>Adjustment: {{ detailData.adjustment.nomor_dokumen }} ({{ detailData.adjustment.status }})</span>
                    </div>
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
                    <Button v-if="canApprove && canApproveItem(detailData)" label="Approve" icon="pi pi-check" severity="success" :loading="processingApprove" @click="customConfirmApprove(detailData)" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

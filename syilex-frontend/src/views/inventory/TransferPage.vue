<script setup>
import { ref, computed, onMounted } from 'vue';
import { transfersApi, warehousesApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { useExportPdf } from '@/composables/useExportPdf';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

import { useNotification } from '@/composables/useNotification';

const authStore = useAuthStore();
const notify = useNotification();
const { formatQty, formatCurrency, formatDateTime, getPrimeDateFormatShort } = useFormatters();
const { exporting, exportDocumentPdf } = useExportPdf();

const canViewHpp = computed(() => authStore.can('stok.view_hpp'));

// E6: Tab state
const activeTab = ref('per_dokumen');
const patternSummary = ref({ loading: false, items: [], top_sender: null, top_receiver: null });

async function loadPatternSummary() {
    patternSummary.value.loading = true;
    try {
        const params = {};
        if (startDate.value) params.date_from = startDate.value.toISOString().slice(0, 10);
        if (endDate.value) params.date_to = endDate.value.toISOString().slice(0, 10);

        const response = await transfersApi.getPatternSummary(params);
        if (response.data.success) {
            patternSummary.value.items = response.data.data.items || [];
            patternSummary.value.top_sender = response.data.data.top_sender;
            patternSummary.value.top_receiver = response.data.data.top_receiver;
        }
    } catch (error) {
        notify.apiError(error, 'Gagal load pattern summary');
    } finally {
        patternSummary.value.loading = false;
    }
}

function onTabChange(tab) {
    activeTab.value = tab;
    if (tab === 'pattern' && patternSummary.value.items.length === 0) {
        loadPatternSummary();
    }
}

// Permissions
const canCreate = computed(() => authStore.can('transfer.create'));
const canUpdate = computed(() => authStore.can('transfer.update'));
const canDelete = computed(() => authStore.can('transfer.delete'));
const canApprove = computed(() => authStore.can('transfer.approve'));

// Warehouses for filter
const warehouses = ref([]);

// Initialize composable
const {
    items: transfers,
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
    canEdit,
    canDelete: canDeleteItem,
    canApprove: canApproveItem
} = useTransactionList(transfersApi, {
    entityName: 'transfer',
    dataKey: 'items',
    routePrefix: 'inventory-transfer',
    filters: [
        { key: 'warehouse_from_id', default: null },
        { key: 'warehouse_to_id', default: null }
    ],
    autoLoad: false
});

// Detail table columns
const hasBiaya = computed(() => {
    const d = detailData.value || {};
    return (Number(d.biaya_kirim) || 0) + (Number(d.biaya_lain) || 0) > 0;
});

const detailColumns = computed(() => {
    const cols = [
        { field: '#', header: '#', width: '40px' },
        { field: 'product', header: 'Produk' },
        { field: 'qty', header: 'Qty', align: 'right', width: '100px' }
    ];
    if (hasBiaya.value) {
        cols.push({ field: 'biaya_dialokasikan', header: 'Biaya Dialokasikan', align: 'right', width: '160px' });
    }
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
    }
}

// Export document PDF
async function exportDocPdf(item) {
    let data = item;
    if (!data.details) {
        try {
            const response = await transfersApi.get(data.ulid);
            data = response.data.data.transfer || response.data.data;
        } catch {
            return;
        }
    }

    const info = [
        { label: 'No. Dokumen', value: data.nomor_dokumen },
        { label: 'Tanggal', value: formatDateTime(data.tanggal) },
        { label: 'Gudang Asal', value: data.warehouse_from?.nama_warehouse || '-' },
        { label: 'Gudang Tujuan', value: data.warehouse_to?.nama_warehouse || '-' },
        { label: 'Status', value: getStatusLabel(data.status) }
    ];

    const columns = [
        { header: '#', field: '#', width: 8, align: 'center' },
        { header: 'Kode Produk', width: 25, accessor: (row) => row.product?.kode_produk || '' },
        {
            header: 'Nama Produk',
            accessor: (row) => {
                let s = row.product?.nama_produk || '';
                if (row.serial_units?.length) {
                    s += '\n' + row.serial_units.map((u) => `• ${u.kode_internal || '-'} / SN ${u.serial_number || '-'}`).join('\n');
                }
                return s;
            }
        },
        { header: 'Qty', width: 20, align: 'right', accessor: (row) => formatQty(row.qty) }
    ];

    const audit = [];
    if (data.created_by?.name) {
        audit.push({ label: 'Dibuat oleh', value: data.created_by.name, date: formatDateTime(data.created_at) });
    }
    if (data.status === 'approved' && data.approved_by?.name) {
        audit.push({ label: 'Disetujui oleh', value: data.approved_by.name, date: formatDateTime(data.approved_at) });
    }

    exportDocumentPdf({
        title: 'Transfer Antar Gudang',
        filename: data.nomor_dokumen || 'transfer',
        info,
        table: { columns, data: data.details || [] },
        audit,
        notes: data.notes
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
                <Button v-if="canCreate" label="Tambah Transfer" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="additionalFilters.warehouse_from_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Gudang Asal" class="w-40" filter showClear @change="onFilter" />
                    <Select v-model="additionalFilters.warehouse_to_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Gudang Tujuan" class="w-40" filter showClear @change="onFilter" />
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

        <!-- E6: Tab navigation -->
        <div class="mb-4 border-b border-surface-200 dark:border-surface-700 flex gap-1">
            <button
                class="px-4 py-2 text-sm font-medium border-b-2 transition"
                :class="activeTab === 'per_dokumen' ? 'border-primary text-primary' : 'border-transparent text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100'"
                @click="onTabChange('per_dokumen')"
                type="button"
            >
                <i class="pi pi-list mr-1"></i> Per Dokumen
            </button>
            <button
                class="px-4 py-2 text-sm font-medium border-b-2 transition"
                :class="activeTab === 'pattern' ? 'border-primary text-primary' : 'border-transparent text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100'"
                @click="onTabChange('pattern')"
                type="button"
            >
                <i class="pi pi-share-alt mr-1"></i> Pattern (Flow)
            </button>
        </div>

        <!-- Tab: Pattern -->
        <div v-if="activeTab === 'pattern'">
            <div v-if="patternSummary.top_sender || patternSummary.top_receiver" class="mb-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 border border-blue-200 dark:border-blue-800">
                    <div class="text-xs text-blue-600 dark:text-blue-400 mb-1">Paling Sering Kirim</div>
                    <div class="text-lg font-bold text-blue-700 dark:text-blue-300">{{ patternSummary.top_sender || '-' }}</div>
                </div>
                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3 border border-green-200 dark:border-green-800">
                    <div class="text-xs text-green-600 dark:text-green-400 mb-1">Paling Sering Menerima</div>
                    <div class="text-lg font-bold text-green-700 dark:text-green-300">{{ patternSummary.top_receiver || '-' }}</div>
                </div>
            </div>

            <DataTable :value="patternSummary.items" :loading="patternSummary.loading" stripedRows responsiveLayout="scroll">
                <template #empty>
                    <div class="flex items-center justify-center py-8 text-surface-500">
                        <i class="pi pi-share-alt mr-2"></i>
                        Belum ada transfer approved dalam periode filter.
                    </div>
                </template>
                <Column header="Dari" style="min-width: 180px">
                    <template #body="{ data }">
                        <div class="font-medium">{{ data.from_kode }}</div>
                        <div class="text-xs text-surface-500">{{ data.from_nama }}</div>
                    </template>
                </Column>
                <Column header="Ke" style="min-width: 180px">
                    <template #body="{ data }">
                        <div class="font-medium">{{ data.to_kode }}</div>
                        <div class="text-xs text-surface-500">{{ data.to_nama }}</div>
                    </template>
                </Column>
                <Column field="frekuensi" header="Frekuensi" style="width: 110px" bodyClass="text-right font-medium" />
                <Column field="qty_total" header="Qty Total" style="width: 130px" bodyClass="text-right">
                    <template #body="{ data }">{{ formatQty(data.qty_total) }}</template>
                </Column>
                <Column v-if="canViewHpp" field="value_total" header="Nilai Total" style="min-width: 140px" bodyClass="text-right">
                    <template #body="{ data }">{{ formatCurrency(data.value_total) }}</template>
                </Column>
            </DataTable>
        </div>

        <!-- DataTable (per dokumen — existing) -->
        <DataTable
            v-if="activeTab === 'per_dokumen'"
            :value="transfers"
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
                <DataTableHeader v-model="searchQuery" title="Daftar Transfer Antar Gudang" placeholder="Cari nomor dokumen, notes..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data transfer</p>
                </div>
            </template>

            <Column field="nomor_dokumen" header="Nomor" sortable style="min-width: 140px">
                <template #body="{ data }">
                    <span class="font-medium">{{ data.nomor_dokumen }}</span>
                </template>
            </Column>

            <Column field="tanggal" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <Column header="Gudang Asal" style="min-width: 150px">
                <template #body="{ data }">
                    {{ data.warehouse_from?.nama_warehouse || '-' }}
                </template>
            </Column>

            <Column header="Gudang Tujuan" style="min-width: 150px">
                <template #body="{ data }">
                    {{ data.warehouse_to?.nama_warehouse || '-' }}
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
                        <Button v-if="canDelete && canDeleteItem(data)" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'" />
                        <Button v-if="canApprove && canApproveItem(data)" icon="pi pi-check" severity="success" text rounded @click="confirmApprove(data)" v-tooltip.top="'Approve'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Transfer"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="700px"
        >
            <template #content>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <DetailItem label="Nomor Dokumen" :value="detailData.nomor_dokumen" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                    <DetailItem label="Gudang Asal" :value="detailData.warehouse_from?.nama_warehouse" />
                    <DetailItem label="Gudang Tujuan" :value="detailData.warehouse_to?.nama_warehouse" />
                    <DetailItem label="Tanggal" :value="formatDateTime(detailData.tanggal)" />
                </div>

                <div class="mb-4" v-if="detailData.notes">
                    <span class="text-surface-500 text-sm block mb-1">Catatan</span>
                    <p class="m-0">{{ detailData.notes }}</p>
                </div>

                <!-- Biaya pengiriman -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4" v-if="hasBiaya">
                    <DetailItem label="Biaya Kirim" :value="detailData.biaya_kirim" type="currency" />
                    <DetailItem :label="detailData.biaya_lain_nama || 'Biaya Lain'" :value="detailData.biaya_lain" type="currency" />
                    <DetailItem label="Masuk HPP" :value="detailData.masuk_hpp ? 'Ya' : 'Tidak'" type="badge" :badge-severity="detailData.masuk_hpp ? 'success' : 'secondary'" />
                </div>

                <!-- Detail Items Table -->
                <div class="mt-4">
                    <h4 class="text-lg font-medium mb-3">Detail Produk ({{ detailData.details?.length || 0 }} item)</h4>
                    <DetailTable :data="detailData.details" :columns="detailColumns">
                        <template #product="{ item }">
                            <span class="font-medium">{{ item.product?.kode_produk }}</span>
                            <br />
                            <span class="text-surface-500 text-sm">{{ item.product?.nama_produk }}</span>
                            <div v-if="item.serial_units?.length" class="mt-1 space-y-0.5">
                                <div v-for="(u, ui) in item.serial_units" :key="ui" class="text-xs font-mono text-surface-500">
                                    {{ u.kode_internal || u.serial_number }}<span v-if="u.serial_number"> · SN {{ u.serial_number }}</span
                                    ><span v-if="u.grade"> · {{ u.grade }}</span>
                                </div>
                            </div>
                        </template>
                        <template #qty="{ item }">{{ formatQty(item.qty) }}</template>
                        <template #biaya_dialokasikan="{ item }">{{ formatCurrency(item.biaya_dialokasikan) }}</template>
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
                        v-if="canDelete && canDeleteItem(detailData)"
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

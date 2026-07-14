<script setup>
import { ref, computed } from 'vue';
import { promosApi } from '@/api';
import { useTransactionList } from '@/composables/useTransactionList';
import { useFormatters } from '@/composables/useFormatters';
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
const { formatCurrency, formatDateTime, getPrimeDateFormatShort } = useFormatters();

// ─── Permissions ───
const canCreate = computed(() => authStore.can('promo.create'));
const canUpdate = computed(() => authStore.can('promo.update'));
const canDeletePerm = computed(() => authStore.can('promo.delete'));
const canApprovePerm = computed(() => authStore.can('promo.approve'));
const canTogglePerm = computed(() => authStore.can('promo.toggle'));

// ─── Status options (computed display statuses) ───
const statusOptions = [
    { label: 'Draft', value: 'draft' },
    { label: 'Aktif', value: 'active' },
    { label: 'Akan Datang', value: 'upcoming' },
    { label: 'Kadaluarsa', value: 'expired' },
    { label: 'Nonaktif', value: 'inactive' }
];

// ─── Initialize composable ───
const {
    items: promos,
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
} = useTransactionList(promosApi, {
    entityName: 'promo',
    dataKey: 'items',
    detailKey: 'promo',
    routePrefix: 'master-promo',
    statusOptions,
    autoLoad: true
});

// ─── Status helpers ───
function getStatusSeverity(status) {
    switch (status) {
        case 'draft':
            return 'warn';
        case 'active':
            return 'success';
        case 'upcoming':
            return 'info';
        case 'expired':
            return 'secondary';
        case 'inactive':
            return 'danger';
        default:
            return 'secondary';
    }
}
function getStatusLabel(status) {
    return statusOptions.find((o) => o.value === status)?.label ?? status;
}

// ─── State-machine helpers ───
function canApproveItem(data) {
    return data.display_status === 'draft';
}
function canCancelItem(data) {
    return ['active', 'upcoming', 'expired'].includes(data.display_status);
}
function canDeactivateItem(data) {
    return ['active', 'upcoming', 'expired'].includes(data.display_status);
}
function canReactivateItem(data) {
    return data.display_status === 'inactive';
}

// ─── Processing states ───
const processingCancel = ref(false);
const processingDeactivate = ref(false);
const processingReactivate = ref(false);

// ─── Approve ───
function confirmApprove(data) {
    confirm.require({
        message: `Approve promo "${data.nama_promo}"? Promo akan mulai aktif sesuai periode yang ditentukan.`,
        header: 'Konfirmasi Approve',
        icon: 'pi pi-question-circle',
        acceptLabel: 'Ya, Approve',
        rejectLabel: 'Batal',
        acceptClass: 'p-button-success',
        accept: async () => {
            processingApprove.value = true;
            try {
                await promosApi.approve(data.ulid);
                notify.success('Promo berhasil di-approve');
                loadData();
                if (detailDialog.value) closeDetail();
            } catch (e) {
                notify.error(e.response?.data?.message || 'Gagal approve promo');
            } finally {
                processingApprove.value = false;
            }
        }
    });
}

// ─── Cancel approval ───
function confirmCancel(data) {
    confirm.require({
        message: `Batalkan approval promo "${data.nama_promo}"? Promo akan kembali ke status Draft dan bisa diedit.`,
        header: 'Konfirmasi Batalkan Approval',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Ya, Batalkan',
        rejectLabel: 'Tidak',
        acceptClass: 'p-button-warning',
        accept: async () => {
            processingCancel.value = true;
            try {
                await promosApi.cancel(data.ulid);
                notify.success('Approval promo berhasil dibatalkan');
                loadData();
                if (detailDialog.value) closeDetail();
            } catch (e) {
                notify.error(e.response?.data?.message || 'Gagal membatalkan approval');
            } finally {
                processingCancel.value = false;
            }
        }
    });
}

// ─── Deactivate ───
function confirmDeactivate(data) {
    confirm.require({
        message: `Nonaktifkan promo "${data.nama_promo}"? Promo tidak akan lagi diterapkan di POS.`,
        header: 'Konfirmasi Nonaktifkan',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Ya, Nonaktifkan',
        rejectLabel: 'Batal',
        acceptClass: 'p-button-danger',
        accept: async () => {
            processingDeactivate.value = true;
            try {
                await promosApi.deactivate(data.ulid);
                notify.success('Promo berhasil dinonaktifkan');
                loadData();
                if (detailDialog.value) closeDetail();
            } catch (e) {
                notify.error(e.response?.data?.message || 'Gagal menonaktifkan promo');
            } finally {
                processingDeactivate.value = false;
            }
        }
    });
}

// ─── Reactivate ───
function confirmReactivate(data) {
    confirm.require({
        message: `Aktifkan kembali promo "${data.nama_promo}"?`,
        header: 'Konfirmasi Aktifkan',
        icon: 'pi pi-question-circle',
        acceptLabel: 'Ya, Aktifkan',
        rejectLabel: 'Batal',
        acceptClass: 'p-button-success',
        accept: async () => {
            processingReactivate.value = true;
            try {
                await promosApi.reactivate(data.ulid);
                notify.success('Promo berhasil diaktifkan kembali');
                loadData();
                if (detailDialog.value) closeDetail();
            } catch (e) {
                notify.error(e.response?.data?.message || 'Gagal mengaktifkan promo');
            } finally {
                processingReactivate.value = false;
            }
        }
    });
}

function createNew() {
    router.push({ name: 'master-promo-create' });
}

// ─── Detail: format discount slot ───
function formatDiskonSlot(tipe, nilai) {
    if (!tipe || tipe === 'none' || !nilai || Number(nilai) === 0) return '—';
    if (tipe === 'percent') return `${Number(nilai)}%`;
    if (tipe === 'nominal') return formatCurrency(nilai);
    return '—';
}

function targetTypeLabel(type) {
    return { semua: 'Semua Produk', produk: 'Produk', grup: 'Grup', kategori: 'Kategori' }[type] ?? type;
}
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Tambah Promo" icon="pi pi-plus" severity="primary" @click="createNew" />
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-36" showClear @change="onFilter" />
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tgl Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tgl Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="onFilter" />
                    </div>
                    <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                </div>
            </template>
        </Toolbar>

        <DataTable
            :value="promos"
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
                <DataTableHeader v-model="searchQuery" title="Daftar Promo" placeholder="Cari kode / nama promo..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-tags text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada data promo</p>
                </div>
            </template>

            <Column field="kode_promo" header="Kode" sortable style="min-width: 130px">
                <template #body="{ data }">
                    <span class="font-medium font-mono text-sm">{{ data.kode_promo }}</span>
                </template>
            </Column>

            <Column field="nama_promo" header="Nama Promo" sortable style="min-width: 160px">
                <template #body="{ data }">
                    <div class="font-medium">{{ data.nama_promo }}</div>
                    <div v-if="data.customer_type" class="text-xs text-surface-500"><i class="pi pi-users text-xs mr-1"></i>{{ data.customer_type.nama_tipe }}</div>
                    <div v-if="data.customer_category" class="text-xs text-surface-500"><i class="pi pi-tag text-xs mr-1"></i>{{ data.customer_category.nama_kategori }}</div>
                    <div v-if="data.terminal" class="text-xs text-surface-500"><i class="pi pi-desktop text-xs mr-1"></i>{{ data.terminal.nama_terminal }}</div>
                </template>
            </Column>

            <Column field="tanggal_mulai" header="Periode" sortable style="min-width: 180px">
                <template #body="{ data }">
                    <div class="text-sm">
                        <span class="font-medium">{{ formatDateTime(data.tanggal_mulai) }}</span>
                        <span v-if="data.tanggal_selesai"> — {{ formatDateTime(data.tanggal_selesai) }}</span>
                        <span v-else> — <span class="text-surface-400 italic">Tanpa batas</span></span>
                    </div>
                    <div v-if="data.jam_mulai" class="text-xs text-amber-600 font-medium mt-0.5"><i class="pi pi-clock text-xs mr-1"></i>Happy Hour: {{ data.jam_mulai?.substring(0, 5) }} — {{ data.jam_selesai?.substring(0, 5) }}</div>
                </template>
            </Column>

            <Column header="Detail" style="min-width: 60px; text-align: center">
                <template #body="{ data }">
                    <Badge :value="data.details_count" severity="secondary" />
                </template>
            </Column>

            <Column field="display_status" header="Status" style="min-width: 110px">
                <template #body="{ data }">
                    <Tag :value="getStatusLabel(data.display_status)" :severity="getStatusSeverity(data.display_status)" />
                </template>
            </Column>

            <Column header="Aksi" style="min-width: 280px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <div class="flex gap-1 flex-wrap">
                        <Button icon="pi pi-eye" severity="info" text rounded @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" />
                        <Button v-if="canUpdate && canEdit(data)" icon="pi pi-pencil" severity="warning" text rounded @click="editItem(data)" v-tooltip.top="'Edit'" />
                        <Button v-if="canDeletePerm && canDelete(data)" icon="pi pi-trash" severity="danger" text rounded @click="confirmDelete(data)" v-tooltip.top="'Hapus'" />
                        <Button v-if="canApprovePerm && canApproveItem(data)" icon="pi pi-check" severity="success" text rounded @click="confirmApprove(data)" v-tooltip.top="'Approve'" />
                        <Button v-if="canApprovePerm && canCancelItem(data)" icon="pi pi-undo" severity="warn" text rounded @click="confirmCancel(data)" v-tooltip.top="'Batalkan Approval'" />
                        <Button v-if="canTogglePerm && canDeactivateItem(data)" icon="pi pi-ban" severity="danger" text rounded @click="confirmDeactivate(data)" v-tooltip.top="'Nonaktifkan'" />
                        <Button v-if="canTogglePerm && canReactivateItem(data)" icon="pi pi-play" severity="success" text rounded @click="confirmReactivate(data)" v-tooltip.top="'Aktifkan Kembali'" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Promo"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
            width="900px"
        >
            <template #content>
                <!-- Header Info -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <DetailItem label="Kode Promo" :value="detailData.kode_promo" />
                    <DetailItem label="Nama Promo" :value="detailData.nama_promo" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.display_status)" type="badge" :badge-severity="getStatusSeverity(detailData.display_status)" />
                    <DetailItem label="Tanggal Mulai" :value="formatDateTime(detailData.tanggal_mulai)" />
                    <DetailItem label="Tanggal Selesai" :value="detailData.tanggal_selesai ? formatDateTime(detailData.tanggal_selesai) : 'Tanpa batas'" />
                    <DetailItem v-if="detailData.jam_mulai" label="Happy Hour (jam aktif tiap hari)" :value="`${detailData.jam_mulai?.substring(0, 5)} — ${detailData.jam_selesai?.substring(0, 5)}`" />
                    <DetailItem v-if="detailData.customer_type" label="Tipe Customer" :value="detailData.customer_type?.nama_tipe" />
                    <DetailItem v-if="detailData.customer_category" label="Kategori Customer" :value="detailData.customer_category?.nama_kategori" />
                    <DetailItem v-if="detailData.terminal" label="Terminal" :value="detailData.terminal?.nama_terminal" />
                </div>

                <div class="mb-4" v-if="detailData.deskripsi">
                    <span class="text-surface-500 text-sm block mb-1">Deskripsi</span>
                    <p class="m-0">{{ detailData.deskripsi }}</p>
                </div>

                <!-- Detail Rows -->
                <div class="mt-4">
                    <h4 class="text-base font-medium mb-3">Baris Diskon ({{ detailData.details?.length || 0 }} baris)</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border-collapse">
                            <thead>
                                <tr class="bg-surface-100">
                                    <th class="border border-surface-200 px-3 py-2 text-center w-8">#</th>
                                    <th class="border border-surface-200 px-3 py-2 text-left" style="min-width: 130px">Target</th>
                                    <th class="border border-surface-200 px-3 py-2 text-center w-16">Min Qty</th>
                                    <th class="border border-surface-200 px-3 py-2 text-center" style="min-width: 80px">Diskon 1</th>
                                    <th class="border border-surface-200 px-3 py-2 text-center" style="min-width: 80px">Diskon 2</th>
                                    <th class="border border-surface-200 px-3 py-2 text-center" style="min-width: 80px">Diskon 3</th>
                                    <th class="border border-surface-200 px-3 py-2 text-center" style="min-width: 80px">Diskon 4</th>
                                    <th class="border border-surface-200 px-3 py-2 text-left w-32">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(d, idx) in detailData.details" :key="idx">
                                    <td class="border border-surface-200 px-3 py-2 text-center text-surface-500">{{ idx + 1 }}</td>
                                    <td class="border border-surface-200 px-3 py-2">
                                        <span class="font-medium">{{ targetTypeLabel(d.target_type) }}</span>
                                        <span v-if="d.target_name" class="block text-xs text-surface-500">{{ d.target_name }}</span>
                                    </td>
                                    <td class="border border-surface-200 px-3 py-2 text-center">{{ d.min_qty }}</td>
                                    <td class="border border-surface-200 px-3 py-2 text-center">
                                        <span :class="formatDiskonSlot(d.diskon_1_tipe, d.diskon_1_nilai) !== '—' ? 'text-primary font-medium' : 'text-surface-400'">
                                            {{ formatDiskonSlot(d.diskon_1_tipe, d.diskon_1_nilai) }}
                                        </span>
                                    </td>
                                    <td class="border border-surface-200 px-3 py-2 text-center">
                                        <span :class="formatDiskonSlot(d.diskon_2_tipe, d.diskon_2_nilai) !== '—' ? 'text-primary font-medium' : 'text-surface-400'">
                                            {{ formatDiskonSlot(d.diskon_2_tipe, d.diskon_2_nilai) }}
                                        </span>
                                    </td>
                                    <td class="border border-surface-200 px-3 py-2 text-center">
                                        <span :class="formatDiskonSlot(d.diskon_3_tipe, d.diskon_3_nilai) !== '—' ? 'text-primary font-medium' : 'text-surface-400'">
                                            {{ formatDiskonSlot(d.diskon_3_tipe, d.diskon_3_nilai) }}
                                        </span>
                                    </td>
                                    <td class="border border-surface-200 px-3 py-2 text-center">
                                        <span :class="formatDiskonSlot(d.diskon_4_tipe, d.diskon_4_nilai) !== '—' ? 'text-primary font-medium' : 'text-surface-400'">
                                            {{ formatDiskonSlot(d.diskon_4_tipe, d.diskon_4_nilai) }}
                                        </span>
                                    </td>
                                    <td class="border border-surface-200 px-3 py-2 text-xs text-surface-600">{{ d.keterangan || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Approval info -->
                <div class="mt-4 pt-4 border-t border-surface-200" v-if="detailData.approved_by">
                    <div class="flex items-center gap-2 text-surface-500 text-sm">
                        <i class="pi pi-check-circle text-blue-500"></i>
                        <span>Disetujui: {{ formatDateTime(detailData.approved_at) }} oleh {{ detailData.approved_by?.name }}</span>
                    </div>
                </div>
            </template>

            <template #footer-extra>
                <div class="flex flex-wrap gap-2">
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
                    <Button v-if="canApprovePerm && canCancelItem(detailData)" label="Batalkan Approval" icon="pi pi-undo" severity="warn" :loading="processingCancel" @click="confirmCancel(detailData)" />
                    <Button v-if="canTogglePerm && canDeactivateItem(detailData)" label="Nonaktifkan" icon="pi pi-ban" severity="danger" :loading="processingDeactivate" @click="confirmDeactivate(detailData)" />
                    <Button v-if="canTogglePerm && canReactivateItem(detailData)" label="Aktifkan Kembali" icon="pi pi-play" severity="success" :loading="processingReactivate" @click="confirmReactivate(detailData)" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>

<script setup>
import { metodePembayaransApi } from '@/api';
import { onMounted, ref, computed, watch } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useConfirm } from 'primevue/useconfirm';
import ImageUpload from '@/components/common/ImageUpload.vue';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';

const notify = useNotification();
const confirm = useConfirm();
const authStore = useAuthStore();
const { formatCurrency, currencySettings, numberSettings, getLocale, shouldUppercase, todayString } = useFormatters();

// Permissions
const canCreate = computed(() => authStore.can('metode-bayar.create'));
const canUpdate = computed(() => authStore.can('metode-bayar.update'));
const canDelete = computed(() => authStore.can('metode-bayar.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const { exporting, exportListPdf } = useExportPdf();
const exportingExcel = ref(false);

// Data
const dt = ref();
const metodePembayarans = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

// Search
const searchQuery = ref('');

// Pagination & Sort
const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'created_at',
    sortOrder: -1
});

// Filters
const selectedStatus = ref(null);
const statusOptions = ref([
    { label: 'Semua Status', value: null },
    { label: 'Aktif', value: 'active' },
    { label: 'Nonaktif', value: 'inactive' }
]);

const selectedMetode = ref(null);
const metodeOptions = ref([
    { label: 'Semua Metode', value: null },
    { label: 'Tunai', value: 'tunai' },
    { label: 'Non-Tunai', value: 'non_tunai' }
]);

// Dialog states
const metodePembayaranDialog = ref(false);
const detailDialog = ref(false);
const submitted = ref(false);
const saving = ref(false);
const loadingDetail = ref(false);
const detailData = ref({});

// Current item being edited
const metodePembayaran = ref({});
const isEdit = computed(() => !!metodePembayaran.value.ulid);

// Dropdown options
const metodeFormOptions = ref([
    { label: 'Tunai', value: 'tunai' },
    { label: 'Non-Tunai', value: 'non_tunai' }
]);

const jenisOptions = ref([
    { label: 'Bank Transfer', value: 'bank' },
    { label: 'QRIS', value: 'qris' },
    { label: 'Kartu Kredit', value: 'credit_card' },
    { label: 'Kartu Debit', value: 'debit_card' },
    { label: 'E-Wallet', value: 'e_wallet' },
    { label: 'Lainnya', value: 'lainnya' }
]);

const biayaTipeOptions = computed(() => [
    { label: 'Tidak Ada', value: 'none' },
    { label: 'Persen (%)', value: 'percent' },
    { label: `Nominal (${currencySettings.value.symbol})`, value: 'nominal' }
]);

// Initial data structure
const emptyMetodePembayaran = {
    kode_pembayaran: '',
    nama_pembayaran: '',
    metode: 'tunai',
    jenis: null,
    nama_akun: '',
    nomor_akun: '',
    logo: '',
    qr_code: '',
    biaya_tambahan_tipe: 'none',
    biaya_tambahan_nilai: 0,
    status: 'active'
};

// Computed: show non-tunai fields
const isNonTunai = computed(() => metodePembayaran.value.metode === 'non_tunai');
const showBiayaNilai = computed(() => metodePembayaran.value.biaya_tambahan_tipe !== 'none');

// Watch metode changes to reset non-tunai fields when switching to tunai
watch(
    () => metodePembayaran.value.metode,
    (newVal) => {
        if (newVal === 'tunai') {
            metodePembayaran.value.jenis = null;
            metodePembayaran.value.nama_akun = '';
            metodePembayaran.value.nomor_akun = '';
            metodePembayaran.value.logo = '';
            metodePembayaran.value.qr_code = '';
            metodePembayaran.value.biaya_tambahan_tipe = 'none';
            metodePembayaran.value.biaya_tambahan_nilai = 0;
        }
    }
);

// Watch biaya_tambahan_tipe to reset nilai when switching to none
watch(
    () => metodePembayaran.value.biaya_tambahan_tipe,
    (newVal) => {
        if (newVal === 'none') {
            metodePembayaran.value.biaya_tambahan_nilai = 0;
        }
    }
);

onMounted(async () => {
    await loadData();
});

async function loadData() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || 'created_at',
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };

        // Search
        if (searchQuery.value?.trim()) {
            params.search = searchQuery.value.trim();
        }

        // Filter by status
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }

        // Filter by metode
        if (selectedMetode.value) {
            params.metode = selectedMetode.value;
        }

        const response = await metodePembayaransApi.getAll(params);
        if (response.data.success) {
            metodePembayarans.value = response.data.data.metode_pembayarans;
            totalRecords.value = response.data.data.pagination.total;
        }
    } catch (error) {
        notify.loadListError('metode pembayaran');
    } finally {
        loading.value = false;
    }
}

function onPage(event) {
    lazyParams.value.first = event.first;
    lazyParams.value.rows = event.rows;
    loadData();
}

function onSort(event) {
    lazyParams.value.sortField = event.sortField;
    lazyParams.value.sortOrder = event.sortOrder;
    loadData();
}

function onFilter() {
    lazyParams.value.first = 0;
    loadData();
}

function clearSearch() {
    searchQuery.value = '';
    lazyParams.value.first = 0;
    loadData();
}

function doSearch() {
    lazyParams.value.first = 0;
    loadData();
}

// Reset all filters
function resetFilters() {
    selectedStatus.value = null;
    selectedMetode.value = null;
    lazyParams.value.first = 0;
    loadData();
}

function openNew() {
    metodePembayaran.value = { ...emptyMetodePembayaran };
    submitted.value = false;
    metodePembayaranDialog.value = true;
}

function hideDialog() {
    metodePembayaranDialog.value = false;
    submitted.value = false;
}

function editItem(data) {
    metodePembayaran.value = {
        ulid: data.ulid,
        kode_pembayaran: data.kode_pembayaran,
        nama_pembayaran: data.nama_pembayaran,
        metode: data.metode,
        jenis: data.jenis,
        nama_akun: data.nama_akun || '',
        nomor_akun: data.nomor_akun || '',
        logo: data.logo || '',
        qr_code: data.qr_code || '',
        biaya_tambahan_tipe: data.biaya_tambahan_tipe || 'none',
        biaya_tambahan_nilai: parseFloat(data.biaya_tambahan_nilai) || 0,
        status: data.status
    };
    submitted.value = false;
    metodePembayaranDialog.value = true;
}

// Export Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedStatus.value) params.status = selectedStatus.value;
        if (selectedMetode.value) params.metode = selectedMetode.value;

        const response = await metodePembayaransApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_metode_pembayaran_${todayString()}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);

        notify.exportSuccess();
    } catch (error) {
        notify.exportError();
    } finally {
        exportingExcel.value = false;
    }
}

async function saveItem() {
    submitted.value = true;

    // Validate required fields
    if (!metodePembayaran.value.kode_pembayaran?.trim()) return;
    if (!metodePembayaran.value.nama_pembayaran?.trim()) return;
    if (!metodePembayaran.value.metode) return;
    if (!metodePembayaran.value.status) return;

    // Validate non-tunai required fields
    if (metodePembayaran.value.metode === 'non_tunai') {
        if (!metodePembayaran.value.jenis) return;
        if (!metodePembayaran.value.biaya_tambahan_tipe) return;
    }

    saving.value = true;

    try {
        const data = {
            nama_pembayaran: metodePembayaran.value.nama_pembayaran.trim(),
            metode: metodePembayaran.value.metode,
            status: metodePembayaran.value.status
        };

        // kode_pembayaran only for create
        if (!isEdit.value) {
            data.kode_pembayaran = metodePembayaran.value.kode_pembayaran.trim();
        }

        // Non-tunai fields
        if (metodePembayaran.value.metode === 'non_tunai') {
            data.jenis = metodePembayaran.value.jenis;
            data.nama_akun = metodePembayaran.value.nama_akun?.trim() || null;
            data.nomor_akun = metodePembayaran.value.nomor_akun?.trim() || null;
            data.logo = metodePembayaran.value.logo || null;
            data.qr_code = metodePembayaran.value.qr_code || null;
            data.biaya_tambahan_tipe = metodePembayaran.value.biaya_tambahan_tipe;
            data.biaya_tambahan_nilai = metodePembayaran.value.biaya_tambahan_tipe !== 'none' ? metodePembayaran.value.biaya_tambahan_nilai : 0;
        }

        let response;
        if (isEdit.value) {
            response = await metodePembayaransApi.update(metodePembayaran.value.ulid, data);
        } else {
            response = await metodePembayaransApi.create(data);
        }

        if (response.data.success) {
            notify.success(response.data.message);
            metodePembayaranDialog.value = false;
            metodePembayaran.value = {};
            await loadData();
        }
    } catch (error) {
        notify.saveError(error);
    } finally {
        saving.value = false;
    }
}

function confirmToggleStatus(data) {
    const isActive = data.status === 'active';
    const action = isActive ? 'menonaktifkan' : 'mengaktifkan';

    confirm.require({
        message: `Apakah Anda yakin ingin ${action} metode pembayaran "${data.nama_pembayaran}"?`,
        header: isActive ? 'Konfirmasi Nonaktifkan' : 'Konfirmasi Aktifkan',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Batal',
            severity: 'secondary',
            outlined: true
        },
        acceptProps: {
            label: isActive ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan',
            severity: isActive ? 'warn' : 'success'
        },
        accept: () => toggleStatus(data)
    });
}

async function toggleStatus(data) {
    try {
        const response = await metodePembayaransApi.toggleStatus(data.ulid);
        if (response.data.success) {
            notify.success(response.data.message);
            await loadData();
        }
    } catch (error) {
        notify.statusChangeError('metode pembayaran', error);
    }
}

function confirmDelete(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin menghapus metode pembayaran "${data.nama_pembayaran}"? Data yang dihapus tidak dapat dikembalikan.`,
        header: 'Konfirmasi Hapus',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Batal',
            severity: 'secondary',
            outlined: true
        },
        acceptProps: {
            label: 'Ya, Hapus',
            severity: 'danger'
        },
        accept: () => deleteItem(data)
    });
}

async function deleteItem(data) {
    try {
        const response = await metodePembayaransApi.delete(data.ulid);
        if (response.data.success) {
            notify.deleted('metode pembayaran');
            await loadData();
        }
    } catch (error) {
        notify.deleteError(error);
    }
}

function getStatusSeverity(status) {
    return status === 'active' ? 'success' : 'danger';
}

function getStatusLabel(status) {
    return status === 'active' ? 'Aktif' : 'Nonaktif';
}

function getToggleLabel(status) {
    return status === 'active' ? 'Nonaktifkan' : 'Aktifkan';
}

function getToggleSeverity(status) {
    return status === 'active' ? 'warn' : 'success';
}

function getMetodeLabel(metode) {
    return metode === 'tunai' ? 'Tunai' : 'Non-Tunai';
}

function getMetodeSeverity(metode) {
    return metode === 'tunai' ? 'info' : 'secondary';
}

function getJenisLabel(jenis) {
    const found = jenisOptions.value.find((o) => o.value === jenis);
    return found ? found.label : '-';
}

function getBiayaDisplay(item) {
    if (item.biaya_tambahan_tipe === 'none') return '-';
    if (item.biaya_tambahan_tipe === 'percent') return `${item.biaya_tambahan_nilai}%`;
    return formatCurrency(item.biaya_tambahan_nilai);
}

function getBiayaTipeLabel(tipe) {
    const found = biayaTipeOptions.value.find((o) => o.value === tipe);
    return found ? found.label : '-';
}

// Export PDF
async function exportPdf() {
    const filterParts = [];
    const params = {
        page: 1,
        per_page: 999999,
        sort_field: lazyParams.value.sortField || 'created_at',
        sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
    };

    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedMetode.value) {
        params.metode = selectedMetode.value;
        filterParts.push(`Metode: ${selectedMetode.value === 'tunai' ? 'Tunai' : 'Non-Tunai'}`);
    }
    if (selectedStatus.value) {
        params.status = selectedStatus.value;
        filterParts.push(`Status: ${selectedStatus.value === 'active' ? 'Aktif' : 'Nonaktif'}`);
    }
    if (searchQuery.value) filterParts.push(`Search: "${searchQuery.value}"`);

    let allData;
    try {
        const response = await metodePembayaransApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.metode_pembayarans;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode', field: 'kode_pembayaran', width: 22 },
        { header: 'Nama', field: 'nama_pembayaran' },
        { header: 'Metode', width: 18, align: 'center', accessor: (row) => getMetodeLabel(row.metode) },
        { header: 'Jenis', width: 22, accessor: (row) => getJenisLabel(row.jenis) },
        { header: 'Biaya', width: 22, align: 'right', accessor: (row) => getBiayaDisplay(row) },
        { header: 'Status', width: 16, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Metode Pembayaran',
        filename: 'daftar_metode_pembayaran',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} metode pembayaran`
    });
}

async function viewDetail(data) {
    detailDialog.value = true;
    loadingDetail.value = true;
    detailData.value = {};
    try {
        const response = await metodePembayaransApi.get(data.ulid);
        if (response.data.success) {
            detailData.value = response.data.data.metode_pembayaran;
        }
    } catch (error) {
        notify.loadDetailError('metode pembayaran');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Metode Pembayaran" icon="pi pi-plus" severity="primary" @click="openNew" />
                </template>

                <template #end>
                    <div class="flex gap-2">
                        <Select v-model="selectedMetode" :options="metodeOptions" optionLabel="label" optionValue="value" placeholder="Filter Metode" class="w-40" filter filterPlaceholder="Cari..." @change="onFilter" />
                        <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Filter Status" class="w-40" filter filterPlaceholder="Cari..." @change="onFilter" />
                        <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                    </div>
                </template>
            </Toolbar>

            <DataTable
                ref="dt"
                :value="metodePembayarans"
                :lazy="true"
                :paginator="true"
                :rows="lazyParams.rows"
                :totalRecords="totalRecords"
                :loading="loading"
                :rowsPerPageOptions="[10, 25, 50]"
                :first="lazyParams.first"
                dataKey="ulid"
                @page="onPage"
                @sort="onSort"
                stripedRows
                showGridlines
                scrollable
            >
                <template #header>
                    <DataTableHeader v-model="searchQuery" title="Metode Pembayaran" placeholder="Cari kode, nama..." @search="doSearch" @clear="clearSearch">
                        <template #extra>
                            <div class="flex gap-2">
                                <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                                <Button v-if="canExport" icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                            </div>
                        </template>
                    </DataTableHeader>
                </template>

                <template #empty>
                    <div class="text-center py-4">
                        <i class="pi pi-credit-card text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data metode pembayaran</p>
                    </div>
                </template>

                <Column field="kode_pembayaran" header="Kode" sortable style="min-width: 120px"></Column>
                <Column field="nama_pembayaran" header="Nama" sortable style="min-width: 180px"></Column>
                <Column field="metode" header="Metode" sortable style="min-width: 100px">
                    <template #body="slotProps">
                        <Tag :value="getMetodeLabel(slotProps.data.metode)" :severity="getMetodeSeverity(slotProps.data.metode)" />
                    </template>
                </Column>
                <Column field="jenis" header="Jenis" sortable style="min-width: 120px">
                    <template #body="slotProps">
                        {{ getJenisLabel(slotProps.data.jenis) }}
                    </template>
                </Column>
                <Column header="Biaya" style="min-width: 100px">
                    <template #body="slotProps">
                        {{ getBiayaDisplay(slotProps.data) }}
                    </template>
                </Column>
                <Column field="status" header="Status" sortable style="min-width: 100px">
                    <template #body="slotProps">
                        <Tag :value="getStatusLabel(slotProps.data.status)" :severity="getStatusSeverity(slotProps.data.status)" />
                    </template>
                </Column>
                <Column :exportable="false" style="min-width: 220px" alignFrozen="right" frozen>
                    <template #body="slotProps">
                        <Button icon="pi pi-eye" outlined rounded class="mr-2" severity="info" @click="viewDetail(slotProps.data)" v-tooltip.top="'Lihat Detail'" />
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editItem(slotProps.data)" v-tooltip.top="'Edit'" />
                        <Button
                            v-if="canUpdate"
                            icon="pi pi-power-off"
                            outlined
                            rounded
                            class="mr-2"
                            :severity="getToggleSeverity(slotProps.data.status)"
                            @click="confirmToggleStatus(slotProps.data)"
                            v-tooltip.top="getToggleLabel(slotProps.data.status)"
                            :aria-label="getToggleLabel(slotProps.data.status)"
                        />
                        <Button v-if="canDelete" icon="pi pi-trash" outlined rounded severity="danger" @click="confirmDelete(slotProps.data)" v-tooltip.top="'Hapus'" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- Form Dialog -->
        <Dialog v-model:visible="metodePembayaranDialog" :style="{ width: '650px' }" :header="isEdit ? 'Edit Metode Pembayaran' : 'Tambah Metode Pembayaran'" :modal="true" :closable="!saving">
            <div class="grid grid-cols-2 gap-4">
                <!-- Kode Pembayaran -->
                <div>
                    <label class="block font-medium mb-2">
                        Kode <span class="text-red-500">*</span>
                        <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                    </label>
                    <InputText
                        v-model.trim="metodePembayaran.kode_pembayaran"
                        :invalid="submitted && !metodePembayaran.kode_pembayaran"
                        :disabled="isEdit"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan kode"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !metodePembayaran.kode_pembayaran" class="text-red-500">Kode wajib diisi</small>
                </div>

                <!-- Nama Pembayaran -->
                <div>
                    <label class="block font-medium mb-2">Nama <span class="text-red-500">*</span></label>
                    <InputText
                        v-model.trim="metodePembayaran.nama_pembayaran"
                        :invalid="submitted && !metodePembayaran.nama_pembayaran"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan nama"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !metodePembayaran.nama_pembayaran" class="text-red-500">Nama wajib diisi</small>
                </div>

                <!-- Metode -->
                <div>
                    <label class="block font-medium mb-2">Metode <span class="text-red-500">*</span></label>
                    <Select v-model="metodePembayaran.metode" :options="metodeFormOptions" optionLabel="label" optionValue="value" :invalid="submitted && !metodePembayaran.metode" fluid filter placeholder="Pilih metode" />
                    <small v-if="submitted && !metodePembayaran.metode" class="text-red-500">Metode wajib dipilih</small>
                </div>

                <!-- Jenis (only for non_tunai) -->
                <div v-if="isNonTunai">
                    <label class="block font-medium mb-2">Jenis <span class="text-red-500">*</span></label>
                    <Select v-model="metodePembayaran.jenis" :options="jenisOptions" optionLabel="label" optionValue="value" :invalid="submitted && isNonTunai && !metodePembayaran.jenis" fluid placeholder="Pilih jenis" />
                    <small v-if="submitted && isNonTunai && !metodePembayaran.jenis" class="text-red-500">Jenis wajib dipilih</small>
                </div>

                <!-- Non-Tunai Fields -->
                <template v-if="isNonTunai">
                    <!-- Nama Akun -->
                    <div>
                        <label class="block font-medium mb-2">Nama Akun</label>
                        <InputText v-model.trim="metodePembayaran.nama_akun" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Nama akun/rekening" autocomplete="off" />
                    </div>

                    <!-- Nomor Akun -->
                    <div>
                        <label class="block font-medium mb-2">Nomor Akun</label>
                        <InputText v-model.trim="metodePembayaran.nomor_akun" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Nomor akun/rekening" autocomplete="off" />
                    </div>

                    <!-- Logo -->
                    <div>
                        <label class="block font-medium mb-2">Logo</label>
                        <ImageUpload v-model="metodePembayaran.logo" folder="payments" label="Upload Logo" previewWidth="100px" previewHeight="100px" />
                    </div>

                    <!-- QR Code -->
                    <div>
                        <label class="block font-medium mb-2">QR Code</label>
                        <ImageUpload v-model="metodePembayaran.qr_code" folder="payments" label="Upload QR Code" previewWidth="100px" previewHeight="100px" />
                    </div>

                    <!-- Biaya Tambahan Tipe -->
                    <div>
                        <label class="block font-medium mb-2">Biaya Tambahan <span class="text-red-500">*</span></label>
                        <Select
                            v-model="metodePembayaran.biaya_tambahan_tipe"
                            :options="biayaTipeOptions"
                            optionLabel="label"
                            optionValue="value"
                            :invalid="submitted && isNonTunai && !metodePembayaran.biaya_tambahan_tipe"
                            fluid
                            placeholder="Pilih tipe biaya"
                        />
                    </div>

                    <!-- Biaya Tambahan Nilai -->
                    <div v-if="showBiayaNilai">
                        <label class="block font-medium mb-2"> Nilai Biaya <span class="text-red-500">*</span> </label>
                        <InputNumber
                            v-select-on-focus
                            v-model="metodePembayaran.biaya_tambahan_nilai"
                            :invalid="submitted && showBiayaNilai && !metodePembayaran.biaya_tambahan_nilai"
                            fluid
                            :min="0"
                            :locale="getLocale"
                            :minFractionDigits="metodePembayaran.biaya_tambahan_tipe === 'percent' ? numberSettings.percentDecimalPlaces : currencySettings.decimalPlaces"
                            :maxFractionDigits="metodePembayaran.biaya_tambahan_tipe === 'percent' ? numberSettings.percentDecimalPlaces : currencySettings.decimalPlaces"
                            :suffix="metodePembayaran.biaya_tambahan_tipe === 'percent' ? '%' : metodePembayaran.biaya_tambahan_tipe === 'nominal' && currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                            :prefix="metodePembayaran.biaya_tambahan_tipe === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                            placeholder="0"
                        />
                        <small v-if="submitted && showBiayaNilai && !metodePembayaran.biaya_tambahan_nilai" class="text-red-500"> Nilai biaya wajib diisi </small>
                    </div>
                </template>

                <!-- Status -->
                <div class="col-span-2 border-t pt-4 mt-2">
                    <label class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="metodePembayaran.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="submitted && !metodePembayaran.status"
                        class="w-48"
                    />
                    <small v-if="submitted && !metodePembayaran.status" class="text-red-500">Status wajib dipilih</small>
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveItem" :loading="saving" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Metode Pembayaran"
            :loading="loadingDetail"
            width="600px"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <!-- Informasi Dasar -->
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Kode Pembayaran" :value="detailData.kode_pembayaran" />
                    <DetailItem label="Nama Pembayaran" :value="detailData.nama_pembayaran" />
                    <DetailItem label="Metode" :value="getMetodeLabel(detailData.metode)" type="badge" :badge-severity="getMetodeSeverity(detailData.metode)" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                </div>

                <!-- Non-Tunai Details -->
                <template v-if="detailData.metode === 'non_tunai'">
                    <Divider />

                    <h6 class="text-surface-600 font-medium mb-3">Detail Non-Tunai</h6>
                    <div class="grid grid-cols-2 gap-4">
                        <DetailItem label="Jenis" :value="getJenisLabel(detailData.jenis)" />
                        <DetailItem label="Nama Akun" :value="detailData.nama_akun" />
                        <DetailItem label="Nomor Akun" :value="detailData.nomor_akun" />
                    </div>

                    <!-- Logo & QR Code -->
                    <div class="grid grid-cols-2 gap-4 mt-4" v-if="detailData.logo || detailData.qr_code">
                        <DetailItem v-if="detailData.logo" label="Logo" :value="detailData.logo" type="image" image-alt="Logo Pembayaran" />
                        <DetailItem v-if="detailData.qr_code" label="QR Code" :value="detailData.qr_code" type="image" image-alt="QR Code Pembayaran" />
                    </div>

                    <Divider />

                    <!-- Biaya Tambahan -->
                    <h6 class="text-surface-600 font-medium mb-3">Biaya Tambahan</h6>
                    <div class="grid grid-cols-2 gap-4">
                        <DetailItem label="Tipe Biaya" :value="getBiayaTipeLabel(detailData.biaya_tambahan_tipe)" />
                        <DetailItem v-if="detailData.biaya_tambahan_tipe !== 'none'" label="Nilai Biaya" :value="getBiayaDisplay(detailData)" />
                    </div>
                </template>
            </template>
        </DetailDialog>
    </div>
</template>

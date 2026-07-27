<script setup>
import { ref, computed, watch } from 'vue';
import { importApi } from '@/api/modules/import';
import { useNotification } from '@/composables/useNotification';
import { useAuthStore } from '@/stores/auth';

const notify = useNotification();
const authStore = useAuthStore();
const can = (perm) => authStore.can(perm);

const selectedEntity = ref(null);
const importMode = ref('create');
const uploading = ref(false);
const file = ref(null);
const results = ref(null);
const importError = ref(null);
const downloading = ref(false);
const fileUploadRef = ref(null);

const entities = [
    { key: 'brand', label: 'Brand', icon: 'pi pi-bookmark', permission: 'brand.create', updatePermission: 'brand.update', group: 'Master Produk' },
    { key: 'tipe', label: 'Tipe Produk', icon: 'pi pi-circle', permission: 'tipe.create', updatePermission: 'tipe.update', group: 'Master Produk' },
    { key: 'kategori', label: 'Kategori Produk', icon: 'pi pi-folder', permission: 'kategori.create', updatePermission: 'kategori.update', group: 'Master Produk', dep: 'Butuh Tipe sudah ada' },
    { key: 'grup', label: 'Grup Produk', icon: 'pi pi-circle', permission: 'grup.create', updatePermission: 'grup.update', group: 'Master Produk', dep: 'Butuh Kategori sudah ada' },
    { key: 'supplier', label: 'Supplier', icon: 'pi pi-truck', permission: 'supplier.create', updatePermission: 'supplier.update', group: 'Master Lainnya' },
    { key: 'warehouse', label: 'Warehouse', icon: 'pi pi-building', permission: 'warehouse.create', updatePermission: 'warehouse.update', group: 'Master Lainnya' },
    { key: 'metode_pembayaran', label: 'Metode Pembayaran', icon: 'pi pi-credit-card', permission: 'metode-bayar.create', updatePermission: 'metode-bayar.update', group: 'Master Lainnya' },
    { key: 'tipe_customer', label: 'Tipe Customer', icon: 'pi pi-id-card', permission: 'tipe-customer.create', updatePermission: 'tipe-customer.update', group: 'Master Customer' },
    { key: 'kategori_customer', label: 'Kategori Customer', icon: 'pi pi-id-card', permission: 'kategori-customer.create', updatePermission: 'kategori-customer.update', group: 'Master Customer' },
    { key: 'customer', label: 'Customer', icon: 'pi pi-users', permission: 'customer.create', updatePermission: 'customer.update', group: 'Master Customer', dep: 'Opsional: Tipe & Kategori Customer' },
    { key: 'produk', label: 'Produk', icon: 'pi pi-box', permission: 'produk.create', updatePermission: 'produk.update', group: 'Master Produk', dep: 'Opsional: Brand, Tipe, Kategori, Grup' }
];

const availableEntities = computed(() => entities.filter((e) => can(e.permission)));

const groupedEntities = computed(() => {
    const groups = {};
    for (const e of availableEntities.value) {
        if (!groups[e.group]) groups[e.group] = [];
        groups[e.group].push(e);
    }
    return groups;
});

const selectedEntityObj = computed(() => entities.find((e) => e.key === selectedEntity.value));

const canUpsert = computed(() => {
    const e = selectedEntityObj.value;
    return e ? can(e.updatePermission) : false;
});

watch(canUpsert, (ok) => {
    if (!ok && importMode.value === 'upsert') {
        importMode.value = 'create';
    }
});

const selectEntity = (key) => {
    selectedEntity.value = key;
    resetForm();
};

const downloadTemplate = async () => {
    if (!selectedEntity.value) {
        notify.warn('Pilih master terlebih dahulu');
        return;
    }
    downloading.value = true;
    try {
        const response = await importApi.downloadTemplate(selectedEntity.value);
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `template_import_${selectedEntity.value}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (e) {
        notify.error('Gagal download template');
    } finally {
        downloading.value = false;
    }
};

const onFileSelect = (event) => {
    file.value = event.files?.[0] || null;
    results.value = null;
    importError.value = null;
};

const onFileClear = () => {
    file.value = null;
    results.value = null;
    importError.value = null;
};

const doImport = async () => {
    if (uploading.value) return;
    if (!selectedEntity.value) {
        notify.warn('Pilih master terlebih dahulu');
        return;
    }
    if (!file.value) {
        notify.warn('Pilih file Excel terlebih dahulu');
        return;
    }
    if (importMode.value === 'upsert' && !canUpsert.value) {
        notify.error('Mode upsert membutuhkan izin update master terkait');
        return;
    }

    uploading.value = true;
    results.value = null;
    importError.value = null;

    try {
        const formData = new FormData();
        formData.append('file', file.value);
        formData.append('mode', importMode.value);

        const response = await importApi.import(selectedEntity.value, formData);
        results.value = response.data.data;
        notify.success(response.data.message);
    } catch (e) {
        const msg = e.response?.data?.message || 'Gagal import data';
        importError.value = msg;
        notify.error(msg);
        if (e.response?.data?.data) {
            results.value = e.response.data.data;
        }
    } finally {
        uploading.value = false;
    }
};

const resetForm = () => {
    file.value = null;
    results.value = null;
    importError.value = null;
    fileUploadRef.value?.clear?.();
};
</script>

<template>
    <div class="card">
        <div class="flex items-center gap-3 mb-4">
            <i class="pi pi-upload text-2xl text-primary"></i>
            <div>
                <h2 class="text-xl font-semibold m-0">Import Master Data</h2>
                <p class="text-surface-500 mt-1 mb-0">Import data master dari file Excel (.xlsx / .xls)</p>
            </div>
        </div>

        <Message severity="info" :closable="false" class="mb-4">
            Batas file: .xlsx/.xls, maks. 5 MB, maks. 10.000 baris data per import.
        </Message>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-1 flex flex-col gap-4">
                <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                    <h3 class="text-base font-medium mb-3">1. Pilih Master</h3>

                    <Message v-if="availableEntities.length === 0" severity="warn" :closable="false">
                        Tidak ada master yang bisa diimpor. Minta admin memberi izin create pada entity terkait.
                    </Message>

                    <div v-else class="flex flex-col gap-2" role="radiogroup" aria-label="Pilih master">
                        <template v-for="(items, group) in groupedEntities" :key="group">
                            <span class="text-xs font-semibold text-surface-400 uppercase mt-1">{{ group }}</span>
                            <button
                                v-for="e in items"
                                :key="e.key"
                                type="button"
                                role="radio"
                                :aria-checked="selectedEntity === e.key"
                                class="flex items-center gap-2 px-3 py-2 rounded-md text-left transition-colors border w-full"
                                :class="
                                    selectedEntity === e.key
                                        ? 'bg-primary-50 dark:bg-primary-900/20 border-primary-300 dark:border-primary-700'
                                        : 'hover:bg-surface-50 dark:hover:bg-surface-800 border-transparent'
                                "
                                @click="selectEntity(e.key)"
                            >
                                <i :class="e.icon" class="text-sm text-surface-400"></i>
                                <div class="flex-1">
                                    <span class="text-sm font-medium">{{ e.label }}</span>
                                    <span v-if="e.dep" class="text-xs text-surface-400 block">{{ e.dep }}</span>
                                </div>
                                <i v-if="selectedEntity === e.key" class="pi pi-check text-primary text-xs"></i>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                    <h3 class="text-base font-medium mb-3">2. Mode Import</h3>
                    <div class="flex flex-col gap-2">
                        <div class="flex items-start gap-2">
                            <RadioButton v-model="importMode" inputId="mode-create" value="create" />
                            <label for="mode-create" class="cursor-pointer">
                                <span class="text-sm font-medium">Hanya Tambah Baru</span>
                                <span class="text-xs text-surface-400 block">Skip jika kode sudah ada</span>
                            </label>
                        </div>
                        <div class="flex items-start gap-2">
                            <RadioButton v-model="importMode" inputId="mode-upsert" value="upsert" :disabled="!canUpsert" />
                            <label for="mode-upsert" class="cursor-pointer" :class="{ 'opacity-50': !canUpsert }">
                                <span class="text-sm font-medium">Tambah & Update</span>
                                <span class="text-xs text-surface-400 block">
                                    {{ canUpsert ? 'Update data jika kode sudah ada' : 'Butuh izin update master terkait' }}
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 flex flex-col gap-4">
                <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                    <h3 class="text-base font-medium mb-3">3. Upload File</h3>

                    <div v-if="!selectedEntity" class="text-center py-8 text-surface-400">
                        <i class="pi pi-arrow-left text-3xl mb-2 block"></i>
                        <span class="text-sm">Pilih master data terlebih dahulu</span>
                    </div>

                    <template v-else>
                        <div class="flex items-center gap-3 mb-4 p-3 bg-primary-50 dark:bg-primary-900/10 rounded-md">
                            <i class="pi pi-info-circle text-primary"></i>
                            <span class="text-sm flex-1">
                                Download template <strong>{{ selectedEntityObj?.label }}</strong>, isi data, lalu upload.
                            </span>
                            <Button label="Download Template" icon="pi pi-download" severity="info" size="small" outlined @click="downloadTemplate" :loading="downloading" />
                        </div>

                        <FileUpload
                            ref="fileUploadRef"
                            mode="basic"
                            accept=".xlsx,.xls"
                            :maxFileSize="5242880"
                            chooseLabel="Pilih File Excel"
                            class="w-full mb-4"
                            :disabled="uploading"
                            @select="onFileSelect"
                            @clear="onFileClear"
                            :auto="false"
                            chooseIcon="pi pi-file-excel"
                        />

                        <Message v-if="importError" severity="error" :closable="true" class="mb-3" @close="importError = null">
                            {{ importError }}
                        </Message>

                        <div class="flex gap-2">
                            <Button label="Import" icon="pi pi-upload" @click="doImport" :loading="uploading" :disabled="!file || uploading" />
                            <Button label="Reset" icon="pi pi-refresh" severity="secondary" outlined @click="resetForm" :disabled="uploading" />
                        </div>
                    </template>
                </div>

                <div v-if="results" class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                    <h3 class="text-base font-medium mb-3">Hasil Import</h3>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                        <div class="text-center p-3 rounded-md bg-green-50 dark:bg-green-900/10">
                            <div class="text-2xl font-bold text-green-600">{{ results.created }}</div>
                            <div class="text-xs text-green-600">Dibuat</div>
                        </div>
                        <div class="text-center p-3 rounded-md bg-blue-50 dark:bg-blue-900/10">
                            <div class="text-2xl font-bold text-blue-600">{{ results.updated }}</div>
                            <div class="text-xs text-blue-600">Diupdate</div>
                        </div>
                        <div class="text-center p-3 rounded-md bg-orange-50 dark:bg-orange-900/10">
                            <div class="text-2xl font-bold text-orange-600">{{ results.skipped }}</div>
                            <div class="text-xs text-orange-600">Dilewati</div>
                        </div>
                        <div class="text-center p-3 rounded-md bg-red-50 dark:bg-red-900/10">
                            <div class="text-2xl font-bold text-red-600">{{ results.errors?.length || 0 }}</div>
                            <div class="text-xs text-red-600">Error</div>
                        </div>
                    </div>

                    <Message v-if="results.errors?.length" severity="error" :closable="false" class="mb-2">
                        {{ results.errors.length }} baris gagal diproses
                    </Message>
                    <div v-if="results.errors?.length > 0" class="max-h-60 overflow-y-auto border border-red-200 dark:border-red-800 rounded-md">
                        <div v-for="(err, i) in results.errors" :key="i" class="px-3 py-2 text-sm border-b border-red-100 dark:border-red-900 last:border-b-0">
                            {{ err }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

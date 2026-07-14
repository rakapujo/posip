<script setup>
import { ref, computed } from 'vue';
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
const downloading = ref(false);

const entities = [
    { key: 'brand', label: 'Brand', icon: 'pi pi-bookmark', permission: 'brand.create', group: 'Master Produk' },
    { key: 'tipe', label: 'Tipe Produk', icon: 'pi pi-circle', permission: 'tipe.create', group: 'Master Produk' },
    { key: 'kategori', label: 'Kategori Produk', icon: 'pi pi-folder', permission: 'kategori.create', group: 'Master Produk', dep: 'Butuh Tipe sudah ada' },
    { key: 'grup', label: 'Grup Produk', icon: 'pi pi-circle', permission: 'grup.create', group: 'Master Produk', dep: 'Butuh Kategori sudah ada' },
    { key: 'supplier', label: 'Supplier', icon: 'pi pi-truck', permission: 'supplier.create', group: 'Master Lainnya' },
    { key: 'warehouse', label: 'Warehouse', icon: 'pi pi-building', permission: 'warehouse.create', group: 'Master Lainnya' },
    { key: 'metode_pembayaran', label: 'Metode Pembayaran', icon: 'pi pi-credit-card', permission: 'metode-bayar.create', group: 'Master Lainnya' },
    { key: 'tipe_customer', label: 'Tipe Customer', icon: 'pi pi-id-card', permission: 'tipe-customer.create', group: 'Master Customer' },
    { key: 'kategori_customer', label: 'Kategori Customer', icon: 'pi pi-id-card', permission: 'kategori-customer.create', group: 'Master Customer' },
    { key: 'customer', label: 'Customer', icon: 'pi pi-users', permission: 'customer.create', group: 'Master Customer', dep: 'Opsional: Tipe & Kategori Customer' },
    { key: 'produk', label: 'Produk', icon: 'pi pi-box', permission: 'produk.create', group: 'Master Produk', dep: 'Opsional: Brand, Tipe, Kategori, Grup' }
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
};

const onFileClear = () => {
    file.value = null;
    results.value = null;
};

const doImport = async () => {
    if (!selectedEntity.value) {
        notify.warn('Pilih master terlebih dahulu');
        return;
    }
    if (!file.value) {
        notify.warn('Pilih file Excel terlebih dahulu');
        return;
    }

    uploading.value = true;
    results.value = null;

    try {
        const formData = new FormData();
        formData.append('file', file.value);
        formData.append('mode', importMode.value);

        const response = await importApi.import(selectedEntity.value, formData);
        results.value = response.data.data;
        notify.success(response.data.message);
    } catch (e) {
        const msg = e.response?.data?.message || 'Gagal import data';
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
};
</script>

<template>
    <div class="card">
        <div class="flex items-center gap-3 mb-4">
            <i class="pi pi-upload text-2xl text-blue-500"></i>
            <div>
                <h2 class="text-xl font-semibold m-0">Import Master Data</h2>
                <p class="text-surface-500 mt-1 mb-0">Import data master dari file Excel (.xlsx)</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left: Settings -->
            <div class="lg:col-span-1 flex flex-col gap-4">
                <!-- Pilih Master -->
                <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                    <h3 class="text-base font-medium mb-3">1. Pilih Master</h3>
                    <div class="flex flex-col gap-2">
                        <template v-for="(items, group) in groupedEntities" :key="group">
                            <span class="text-xs font-semibold text-surface-400 uppercase mt-1">{{ group }}</span>
                            <div
                                v-for="e in items"
                                :key="e.key"
                                class="flex items-center gap-2 px-3 py-2 rounded-md cursor-pointer transition-colors"
                                :class="selectedEntity === e.key ? 'bg-blue-50 dark:bg-blue-900/20 border border-blue-300 dark:border-blue-700' : 'hover:bg-surface-50 dark:hover:bg-surface-800 border border-transparent'"
                                @click="
                                    selectedEntity = e.key;
                                    resetForm();
                                "
                            >
                                <i :class="e.icon" class="text-sm text-surface-400"></i>
                                <div class="flex-1">
                                    <span class="text-sm font-medium">{{ e.label }}</span>
                                    <span v-if="e.dep" class="text-xs text-surface-400 block">{{ e.dep }}</span>
                                </div>
                                <i v-if="selectedEntity === e.key" class="pi pi-check text-blue-500 text-xs"></i>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Mode -->
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
                            <RadioButton v-model="importMode" inputId="mode-upsert" value="upsert" />
                            <label for="mode-upsert" class="cursor-pointer">
                                <span class="text-sm font-medium">Tambah & Update</span>
                                <span class="text-xs text-surface-400 block">Update data jika kode sudah ada</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Upload & Results -->
            <div class="lg:col-span-2 flex flex-col gap-4">
                <!-- Template & Upload -->
                <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                    <h3 class="text-base font-medium mb-3">3. Upload File</h3>

                    <div v-if="!selectedEntity" class="text-center py-8 text-surface-400">
                        <i class="pi pi-arrow-left text-3xl mb-2 block"></i>
                        <span class="text-sm">Pilih master data terlebih dahulu</span>
                    </div>

                    <template v-else>
                        <!-- Download Template -->
                        <div class="flex items-center gap-3 mb-4 p-3 bg-blue-50 dark:bg-blue-900/10 rounded-md">
                            <i class="pi pi-info-circle text-blue-500"></i>
                            <span class="text-sm flex-1">
                                Download template <strong>{{ selectedEntityObj?.label }}</strong
                                >, isi data, lalu upload.
                            </span>
                            <Button label="Download Template" icon="pi pi-download" severity="info" size="small" outlined @click="downloadTemplate" :loading="downloading" />
                        </div>

                        <!-- File Upload -->
                        <FileUpload mode="basic" accept=".xlsx,.xls" :maxFileSize="5242880" chooseLabel="Pilih File Excel" class="w-full mb-4" @select="onFileSelect" @clear="onFileClear" :auto="false" chooseIcon="pi pi-file-excel" />

                        <div class="flex gap-2">
                            <Button label="Import" icon="pi pi-upload" @click="doImport" :loading="uploading" :disabled="!file" />
                            <Button label="Reset" icon="pi pi-refresh" severity="secondary" outlined @click="resetForm" :disabled="uploading" />
                        </div>
                    </template>
                </div>

                <!-- Results -->
                <div v-if="results" class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                    <h3 class="text-base font-medium mb-3">Hasil Import</h3>

                    <!-- Summary -->
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

                    <!-- Errors -->
                    <div v-if="results.errors?.length > 0">
                        <h4 class="text-sm font-medium mb-2 text-red-600">
                            <i class="pi pi-exclamation-circle mr-1"></i>
                            Detail Error ({{ results.errors.length }})
                        </h4>
                        <div class="max-h-60 overflow-y-auto border border-red-200 dark:border-red-800 rounded-md">
                            <div v-for="(err, i) in results.errors" :key="i" class="px-3 py-2 text-sm border-b border-red-100 dark:border-red-900 last:border-b-0">
                                {{ err }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { resetApi } from '@/api/modules/reset';
import { useNotification } from '@/composables/useNotification';
import { useConfirm } from 'primevue/useconfirm';

const notify = useNotification();
const confirm = useConfirm();
const counts = ref({});
const loading = ref(false);
const resetting = ref(false);

// Password dialog
const passwordDialog = ref(false);
const password = ref('');
const pendingTarget = ref('');
const pendingLabel = ref('');

// Backup
const backupInfo = ref({ database: '', tables: 0 });
const backupDialog = ref(false);
const backupPassword = ref('');
const downloading = ref(false);

// Restore
const restoreDialog = ref(false);
const restorePassword = ref('');
const restoreFile = ref(null);
const restoring = ref(false);

const loadCounts = async () => {
    loading.value = true;
    try {
        const { data } = await resetApi.getCounts();
        counts.value = data.data;
    } catch (e) {
        notify.error('Gagal memuat data');
    } finally {
        loading.value = false;
    }
};

const loadBackupInfo = async () => {
    try {
        const { data } = await resetApi.getBackupInfo();
        backupInfo.value = data.data;
    } catch {
        // silent
    }
};

const requestBackup = () => {
    backupPassword.value = '';
    backupDialog.value = true;
};

const executeBackup = async () => {
    if (!backupPassword.value) {
        notify.warn('Masukkan password untuk konfirmasi');
        return;
    }

    downloading.value = true;
    try {
        const response = await resetApi.downloadBackup({ password: backupPassword.value });

        // Check if response is an error (JSON blob)
        if (response.data.type === 'application/json') {
            const text = await response.data.text();
            const json = JSON.parse(text);
            notify.error(json.message || 'Gagal download backup');
            return;
        }

        // Create download link (ZIP contains database.sql + uploads/)
        const blob = new Blob([response.data], { type: 'application/zip' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `posip_backup_${new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-')}.zip`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);

        notify.success('Backup berhasil didownload');
        backupDialog.value = false;
        backupPassword.value = '';
    } catch (e) {
        // Blob error response needs special handling
        if (e.response?.data instanceof Blob) {
            try {
                const text = await e.response.data.text();
                const json = JSON.parse(text);
                notify.error(json.message || 'Gagal download backup');
            } catch {
                notify.error('Gagal download backup');
            }
        } else {
            notify.apiError(e, 'Gagal download backup');
        }
    } finally {
        downloading.value = false;
    }
};

const requestRestore = () => {
    restorePassword.value = '';
    restoreFile.value = null;
    restoreDialog.value = true;
};

const onRestoreFileSelect = (event) => {
    const file = event.files?.[0];
    if (!file) return;

    // Check extension
    const name = file.name.toLowerCase();
    if (!name.endsWith('.sql') && !name.endsWith('.zip')) {
        notify.error('File harus berformat .zip atau .sql');
        restoreFile.value = null;
        return;
    }

    // Check size (2GB)
    const maxSize = 2 * 1024 * 1024 * 1024;
    if (file.size > maxSize) {
        notify.error('Ukuran file maksimal 2GB');
        restoreFile.value = null;
        return;
    }

    restoreFile.value = file;
};

const onRestoreFileClear = () => {
    restoreFile.value = null;
};

const formatFileSize = (bytes) => {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < units.length - 1) {
        size /= 1024;
        i++;
    }
    return `${size.toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
};

const executeRestore = async () => {
    if (!restoreFile.value) {
        notify.warn('Pilih file backup .zip atau .sql terlebih dahulu');
        return;
    }
    if (!restorePassword.value) {
        notify.warn('Masukkan password untuk konfirmasi');
        return;
    }

    restoring.value = true;
    try {
        const formData = new FormData();
        formData.append('file', restoreFile.value);
        formData.append('password', restorePassword.value);

        await resetApi.restoreBackup(formData);

        notify.success('Database berhasil direstore dari backup');
        restoreDialog.value = false;
        restoreFile.value = null;
        restorePassword.value = '';
        await loadCounts();
        await loadBackupInfo();
    } catch (e) {
        const msg = e.response?.data?.message || 'Gagal restore database';
        notify.error(msg);
    } finally {
        restoring.value = false;
    }
};

onMounted(() => {
    loadCounts();
    loadBackupInfo();
});

const getCount = (key) => counts.value[key] ?? 0;

// ── Quick reset cards ──
const quickResets = [
    {
        key: 'all',
        label: 'Reset Semua',
        icon: 'pi pi-trash',
        desc: 'Hapus semua data master, transaksi, inventory & reset settings ke default',
        severity: 'danger'
    },
    {
        key: 'master',
        label: 'Reset Master',
        icon: 'pi pi-box',
        desc: 'Hapus semua data master (brand, tipe, kategori, supplier, customer, produk, dll). Transaksi juga akan direset',
        severity: 'warn'
    },
    {
        key: 'transaksi',
        label: 'Reset Transaksi',
        icon: 'pi pi-file',
        desc: 'Hapus semua transaksi (PO, penjualan, retur, adjustment, transfer, dll). Stok & kartu stok juga akan direset',
        severity: 'warn'
    }
];

// ── Per-table items ──
const masterTables = [
    { key: 'brand', label: 'Brand', icon: 'pi pi-bookmark' },
    { key: 'tipe', label: 'Tipe', icon: 'pi pi-circle' },
    { key: 'kategori', label: 'Kategori', icon: 'pi pi-folder' },
    { key: 'grup', label: 'Grup', icon: 'pi pi-circle' },
    { key: 'supplier', label: 'Supplier', icon: 'pi pi-truck' },
    { key: 'customer', label: 'Customer', icon: 'pi pi-users' },
    { key: 'tipe_customer', label: 'Tipe Customer', icon: 'pi pi-id-card' },
    { key: 'kategori_customer', label: 'Kategori Customer', icon: 'pi pi-id-card' },
    { key: 'warehouse', label: 'Warehouse', icon: 'pi pi-building' },
    { key: 'metode_pembayaran', label: 'Metode Pembayaran', icon: 'pi pi-credit-card' },
    { key: 'produk', label: 'Produk', icon: 'pi pi-box' },
    { key: 'pos_terminal', label: 'POS Terminal', icon: 'pi pi-desktop' }
];

const transaksiTables = [
    { key: 'purchase_order', label: 'Purchase Order', icon: 'pi pi-shopping-cart' },
    { key: 'purchase_return', label: 'Retur Pembelian', icon: 'pi pi-replay' },
    { key: 'sales', label: 'Penjualan', icon: 'pi pi-receipt' },
    { key: 'pembayaran_hutang', label: 'Pembayaran Hutang', icon: 'pi pi-money-bill' },
    { key: 'supplier_deposit', label: 'Deposit Supplier', icon: 'pi pi-dollar' },
    { key: 'adjustment', label: 'Adjustment', icon: 'pi pi-sliders-h' },
    { key: 'transfer', label: 'Transfer', icon: 'pi pi-arrows-h' },
    { key: 'repack', label: 'Repack', icon: 'pi pi-sync' },
    { key: 'stock_opname', label: 'Stock Opname', icon: 'pi pi-clipboard' },
    { key: 'hpp_correction', label: 'Koreksi HPP', icon: 'pi pi-pencil' },
    { key: 'price_change', label: 'Perubahan Harga', icon: 'pi pi-tag' },
    { key: 'serial_intake', label: 'Pembelian Serial', icon: 'pi pi-qrcode' },
    { key: 'serial_change', label: 'Perubahan Data Serial', icon: 'pi pi-pencil' },
    { key: 'serial_hpp_correction', label: 'Koreksi HPP Serial', icon: 'pi pi-dollar' },
    { key: 'shift', label: 'Shift', icon: 'pi pi-clock' }
];

const inventoryTables = [
    { key: 'inventory', label: 'Stok & Kartu Stok', icon: 'pi pi-database', countKeys: ['inventory_stock', 'stock_card'] },
    { key: 'settings', label: 'Settings', icon: 'pi pi-cog', countKeys: ['settings'] }
];

const getTableCount = (item) => {
    if (item.countKeys) {
        return item.countKeys.reduce((sum, k) => sum + getCount(k), 0);
    }
    return getCount(item.key);
};

// ── Reset flow ──
const requestReset = (targetKey, targetLabel) => {
    confirm.require({
        message: `Anda yakin ingin mereset ${targetLabel}? Data yang direset TIDAK DAPAT dikembalikan.`,
        header: `Reset ${targetLabel}`,
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Batal',
        acceptLabel: 'Ya, Lanjutkan',
        rejectProps: { severity: 'secondary', outlined: true },
        acceptProps: { severity: 'danger' },
        accept: () => {
            pendingTarget.value = targetKey;
            pendingLabel.value = targetLabel;
            password.value = '';
            passwordDialog.value = true;
        }
    });
};

const executeReset = async () => {
    if (!password.value) {
        notify.warn('Masukkan password untuk konfirmasi');
        return;
    }

    resetting.value = true;
    try {
        await resetApi.reset({
            target: pendingTarget.value,
            password: password.value
        });
        notify.success(`${pendingLabel.value} berhasil direset`);
        passwordDialog.value = false;
        password.value = '';
        await loadCounts();
    } catch (e) {
        const msg = e.response?.data?.message || 'Gagal mereset data';
        notify.error(msg);
    } finally {
        resetting.value = false;
    }
};

const formatNumber = (n) => {
    return new Intl.NumberFormat('id-ID').format(n);
};
</script>

<template>
    <!-- ═══ Backup Database ═══ -->
    <div class="card mb-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                    <i class="pi pi-download text-xl text-blue-500"></i>
                </div>
                <div>
                    <h2 class="text-xl font-semibold m-0">Backup Database</h2>
                    <p class="text-surface-500 mt-1 mb-0">
                        Database: <strong>{{ backupInfo.database || '...' }}</strong> &middot; {{ backupInfo.tables }} tabel
                    </p>
                </div>
            </div>
            <div class="flex gap-2">
                <Button label="Import Backup" icon="pi pi-upload" severity="warn" outlined @click="requestRestore" :loading="restoring" />
                <Button label="Download Backup (.zip)" icon="pi pi-download" @click="requestBackup" :loading="downloading" />
            </div>
        </div>
    </div>

    <!-- ═══ Reset Database ═══ -->
    <div class="card">
        <div class="flex items-center gap-3 mb-4">
            <i class="pi pi-database text-2xl text-red-500"></i>
            <div>
                <h2 class="text-xl font-semibold m-0">Reset Database</h2>
                <p class="text-surface-500 mt-1 mb-0">Kelola dan reset data aplikasi</p>
            </div>
        </div>

        <Message severity="warn" :closable="false" class="mb-6">
            <i class="pi pi-exclamation-triangle mr-2"></i>
            Data yang direset <strong>tidak dapat dikembalikan</strong>. Pastikan sudah melakukan backup sebelum reset.
        </Message>

        <!-- ═══ Reset Cepat ═══ -->
        <div class="mb-6">
            <h3 class="text-lg font-medium mb-3">Reset Cepat</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div v-for="item in quickResets" :key="item.key" class="border border-surface-200 dark:border-surface-700 rounded-lg p-5 flex flex-col">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center" :class="item.severity === 'danger' ? 'bg-red-100 dark:bg-red-900/30' : 'bg-orange-100 dark:bg-orange-900/30'">
                            <i :class="item.icon" class="text-lg" :style="{ color: item.severity === 'danger' ? 'var(--p-red-500)' : 'var(--p-orange-500)' }"></i>
                        </div>
                        <span class="font-semibold text-lg">{{ item.label }}</span>
                    </div>
                    <p class="text-surface-500 text-sm flex-1 mb-4">{{ item.desc }}</p>
                    <Button :label="item.label" :severity="item.severity" icon="pi pi-trash" class="w-full" outlined @click="requestReset(item.key, item.label)" :loading="resetting && pendingTarget === item.key" />
                </div>
            </div>
        </div>

        <!-- ═══ Reset per Tabel ═══ -->
        <div class="mb-6">
            <h3 class="text-lg font-medium mb-3">Master Data</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <div v-for="item in masterTables" :key="item.key" class="border border-surface-200 dark:border-surface-700 rounded-lg p-4 flex flex-col items-center text-center">
                    <i :class="item.icon" class="text-xl text-surface-400 mb-2"></i>
                    <span class="font-medium text-sm mb-1">{{ item.label }}</span>
                    <Tag :value="loading ? '...' : formatNumber(getCount(item.key))" :severity="getCount(item.key) > 0 ? 'info' : 'secondary'" class="mb-3" />
                    <Button label="Reset" severity="danger" size="small" text :disabled="getCount(item.key) === 0" @click="requestReset(item.key, item.label)" :loading="resetting && pendingTarget === item.key" />
                </div>
            </div>
        </div>

        <div class="mb-6">
            <h3 class="text-lg font-medium mb-3">Transaksi</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <div v-for="item in transaksiTables" :key="item.key" class="border border-surface-200 dark:border-surface-700 rounded-lg p-4 flex flex-col items-center text-center">
                    <i :class="item.icon" class="text-xl text-surface-400 mb-2"></i>
                    <span class="font-medium text-sm mb-1">{{ item.label }}</span>
                    <Tag :value="loading ? '...' : formatNumber(getCount(item.key))" :severity="getCount(item.key) > 0 ? 'info' : 'secondary'" class="mb-3" />
                    <Button label="Reset" severity="danger" size="small" text :disabled="getCount(item.key) === 0" @click="requestReset(item.key, item.label)" :loading="resetting && pendingTarget === item.key" />
                </div>
            </div>
        </div>

        <div class="mb-2">
            <h3 class="text-lg font-medium mb-3">Inventory & Settings</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <div v-for="item in inventoryTables" :key="item.key" class="border border-surface-200 dark:border-surface-700 rounded-lg p-4 flex flex-col items-center text-center">
                    <i :class="item.icon" class="text-xl text-surface-400 mb-2"></i>
                    <span class="font-medium text-sm mb-1">{{ item.label }}</span>
                    <Tag :value="loading ? '...' : formatNumber(getTableCount(item))" :severity="getTableCount(item) > 0 ? 'info' : 'secondary'" class="mb-3" />
                    <Button label="Reset" severity="danger" size="small" text :disabled="getTableCount(item) === 0" @click="requestReset(item.key, item.label)" :loading="resetting && pendingTarget === item.key" />
                </div>
            </div>
        </div>
    </div>

    <!-- Password Confirmation Dialog (Reset) -->
    <Dialog v-model:visible="passwordDialog" :header="'Konfirmasi Reset ' + pendingLabel" :style="{ width: '420px' }" modal :closable="!resetting">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                <i class="pi pi-lock text-red-500"></i>
            </div>
            <p class="text-surface-600 dark:text-surface-300 m-0 text-sm">
                Masukkan password Anda untuk mengonfirmasi reset <strong>{{ pendingLabel }}</strong
                >.
            </p>
        </div>
        <div class="mb-2">
            <label class="block text-sm font-medium mb-1">Password</label>
            <Password v-model="password" :feedback="false" toggleMask class="w-full" inputClass="w-full" @keyup.enter="executeReset" :disabled="resetting" />
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" outlined @click="passwordDialog = false" :disabled="resetting" />
            <Button label="Reset" severity="danger" icon="pi pi-trash" @click="executeReset" :loading="resetting" :disabled="!password" />
        </template>
    </Dialog>

    <!-- Password Confirmation Dialog (Backup) -->
    <Dialog v-model:visible="backupDialog" header="Konfirmasi Backup" :style="{ width: '420px' }" modal :closable="!downloading">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                <i class="pi pi-lock text-blue-500"></i>
            </div>
            <p class="text-surface-600 dark:text-surface-300 m-0 text-sm">Masukkan password Anda untuk mengonfirmasi download backup database.</p>
        </div>
        <div class="mb-2">
            <label class="block text-sm font-medium mb-1">Password</label>
            <Password v-model="backupPassword" :feedback="false" toggleMask class="w-full" inputClass="w-full" @keyup.enter="executeBackup" :disabled="downloading" />
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" outlined @click="backupDialog = false" :disabled="downloading" />
            <Button label="Download" icon="pi pi-download" @click="executeBackup" :loading="downloading" :disabled="!backupPassword" />
        </template>
    </Dialog>

    <!-- Restore Database Dialog -->
    <Dialog v-model:visible="restoreDialog" header="Import Database" :style="{ width: '500px' }" modal :closable="!restoring">
        <Message severity="warn" :closable="false" class="mb-4">
            <i class="pi pi-exclamation-triangle mr-2"></i>
            Import akan <strong>menimpa seluruh data</strong> di database saat ini. Pastikan file backup benar.
        </Message>

        <div class="mb-4">
            <label class="block text-sm font-medium mb-2">File Backup (.zip atau .sql)</label>
            <FileUpload mode="basic" accept=".zip,.sql" :maxFileSize="2147483648" chooseLabel="Pilih File Backup" chooseIcon="pi pi-upload" :auto="false" :disabled="restoring" @select="onRestoreFileSelect" @clear="onRestoreFileClear" />
            <p v-if="restoreFile" class="text-sm text-surface-500 mt-2 mb-0">
                <i class="pi pi-file mr-1"></i>
                {{ restoreFile.name }} ({{ formatFileSize(restoreFile.size) }})
            </p>
            <p class="text-xs text-surface-400 mt-1 mb-0">Maksimal 2GB. File .zip (DB + file upload) atau .sql (DB saja) dari backup POSIP.</p>
        </div>

        <div class="mb-2">
            <label class="block text-sm font-medium mb-1">Password</label>
            <Password v-model="restorePassword" :feedback="false" toggleMask class="w-full" inputClass="w-full" @keyup.enter="executeRestore" :disabled="restoring" />
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" outlined @click="restoreDialog = false" :disabled="restoring" />
            <Button label="Import Database" severity="warn" icon="pi pi-upload" @click="executeRestore" :loading="restoring" :disabled="!restoreFile || !restorePassword" />
        </template>
    </Dialog>
</template>

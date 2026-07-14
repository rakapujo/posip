<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { settingsApi, posTerminalsApi } from '@/api';
import { useSettingsStore } from '@/stores/settings';
import { useAuthStore } from '@/stores/auth';
import ImageUpload from '@/components/common/ImageUpload.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const notify = useNotification();
const settingsStore = useSettingsStore();
const authStore = useAuthStore();
const canUpdate = computed(() => authStore.can('settings.update'));
const { getLocale, numberSettings, currencySettings } = useFormatters();

// Active shifts — dipakai untuk warn admin sebelum ubah setting fiskal.
// Group-group yang terdampak: tax, rounding, promo, currency, calculation, stock.
const activeShifts = ref({ count: 0, shifts: [] });
async function refreshActiveShifts() {
    try {
        const res = await posTerminalsApi.getActiveShiftsSummary();
        activeShifts.value = res.data.data || { count: 0, shifts: [] };
    } catch {
        // Silent fail — banner tidak tampil kalau endpoint gagal, bukan blocker.
    }
}

// Lacak nilai awal negative_mode untuk deteksi "switch ke allow" → confirm dialog.
const prevNegativeMode = ref(null);
const negativeModeDialog = ref(false);

const printServiceDownloadUrl = '/downloads/posip-print-service-setup.exe';
const loading = ref(true);
const saving = ref({});
const activeTab = ref('0');

// Price mode lock status
const priceModeLocked = ref(false);
const priceModeLockMessage = ref('');

// Stock mode lock status
const stockModeLocked = ref(false);
const stockModeLockMessage = ref('');

// Prefix document state
const prefixes = ref([]);
const loadingPrefixes = ref(false);
const editingPrefix = ref({});
const savingPrefix = ref({});

// Define which groups belong to each tab
const tabGroups = {
    0: ['store'],
    1: ['regional', 'number', 'text'],
    2: ['currency'],
    3: ['tax', 'rounding'],
    4: ['stock', 'calculation', 'product'],
    5: ['promo'],
    6: ['scheduler'],
    7: ['prefix'],
    8: ['modules']
};

// Initialize settings dengan struktur default
const settings = ref({
    store: { name: '', address: '', phone: '', email: '', logo: '', icon: '', login_background: '', npwp: '', url: '', receipt_footer: '' },
    regional: { timezone: 'Asia/Jakarta', date_format: 'DD/MM/YYYY', time_format: 'HH:mm' },
    currency: { code: 'IDR', symbol: 'Rp', position: 'before', thousand_separator: '.', decimal_separator: ',', decimal_places: 0 },
    number: { qty_decimal_places: 0, percent_decimal_places: 2 },
    tax: { tax_purchase_name: 'PPN', tax_purchase_percent: 11, tax_purchase_included_in_hpp: false, tax_sales_name: 'PPN', tax_sales_percent: 11 },
    rounding: { purchase_method: 'none', purchase_precision: 0, sales_method: 'round', sales_precision: 100 },
    stock: { negative_mode: 'block' },
    calculation: { discount_mode: 'recursive', cost_allocation_mode: 'by_value' },
    promo: { enabled: true, allow_manual_discount: true, max_manual_discount_percent: 100, max_manual_discount_nominal: null },
    product: { price_input_mode: 'auto' },
    text: { uppercase_mode: 'code_only' },
    scheduler: { price_change_enabled: true, price_change_cooldown: 5, price_change_max_batch: 50 },
    prefix: { purchase_order: 'POR', purchase_return: 'RPB', sales: 'INV', sales_return: 'RPJ', payment_hutang: 'PBH', stock_opname: 'OPN', adjustment: 'ADJ', transfer: 'TRF', repack: 'RPK', price_change: 'PCH', hpp_correction: 'HPC', promo: 'PRM' },
    modules: { elektronik_enabled: true }
});

// Elektronik module lock status (terkunci aktif bila masih ada produk/unit serial)
const elektronikLocked = ref(false);
const elektronikLockMessage = ref('');

// Timezone options — hydrated from /settings/timezones on mount, with safe
// fallback to the 3 Indonesia timezones so the dropdown still works even if
// the endpoint is unreachable.
const timezoneGroups = ref([
    {
        label: 'Indonesia',
        items: [
            { label: 'WIB (Asia/Jakarta)', value: 'Asia/Jakarta' },
            { label: 'WITA (Asia/Makassar)', value: 'Asia/Makassar' },
            { label: 'WIT (Asia/Jayapura)', value: 'Asia/Jayapura' }
        ]
    }
]);

// Date format options
const dateFormatOptions = [
    { label: 'DD/MM/YYYY', value: 'DD/MM/YYYY' },
    { label: 'MM/DD/YYYY', value: 'MM/DD/YYYY' },
    { label: 'YYYY-MM-DD', value: 'YYYY-MM-DD' }
];

// Time format options
const timeFormatOptions = [
    { label: 'HH:mm (24 jam)', value: 'HH:mm' },
    { label: 'hh:mm A (12 jam)', value: 'hh:mm A' }
];

// Currency position options (dynamic based on current symbol)
const currencyPositionOptions = computed(() => {
    const symbol = settings.value.currency?.symbol || 'Rp';
    return [
        { label: `Sebelum (${symbol} 10.000)`, value: 'before' },
        { label: `Sesudah (10.000 ${symbol})`, value: 'after' }
    ];
});

// Rounding method options
const roundingMethodOptions = [
    { label: 'Tidak ada', value: 'none' },
    { label: 'Pembulatan (round)', value: 'round' },
    { label: 'Pembulatan ke bawah (floor)', value: 'floor' },
    { label: 'Pembulatan ke atas (ceil)', value: 'ceil' }
];

// Stock mode options
const stockModeOptions = [
    { label: 'Blokir jika stok negatif', value: 'block' },
    { label: 'Izinkan stok negatif', value: 'allow' }
];

// Discount mode options
const discountModeOptions = [
    { label: 'Rekursif (diskon dari sisa)', value: 'recursive' },
    { label: 'Penjumlahan (diskon dari total)', value: 'sum' }
];

// Price input mode options
const priceInputModeOptions = [
    { label: 'Otomatis (dari unit terbesar)', value: 'auto' },
    { label: 'Manual (semua diinput)', value: 'manual' }
];

// Uppercase mode options
const uppercaseModeOptions = [
    { label: 'Tidak ada', value: 'none' },
    { label: 'Hanya kode', value: 'code_only' },
    { label: 'Semua field', value: 'all' }
];

// Separator options (valid combinations for PrimeVue InputNumber locale)
const separatorOptions = [
    { label: 'Titik (.)', value: '.' },
    { label: 'Koma (,)', value: ',' },
    { label: 'Spasi ( )', value: ' ' },
    { label: "Apostrof (')", value: "'" }
];

// Filtered thousand separator options (exclude current decimal separator)
const thousandSeparatorOptions = computed(() => {
    const decimalSep = settings.value.currency?.decimal_separator;
    return separatorOptions.filter((opt) => opt.value !== decimalSep);
});

// Filtered decimal separator options (exclude current thousand separator)
const decimalSeparatorOptions = computed(() => {
    const thousandSep = settings.value.currency?.thousand_separator;
    return separatorOptions.filter((opt) => opt.value !== thousandSep);
});

// Fetch timezone list — silent fallback to the 3 Indonesia defaults on error.
// Backend: { groups: [{ region, timezones: [{ value, label, offset }] }] }
// Needs: [{ label, items: [{ label, value }] }] for PrimeVue grouped Select
const fetchTimezones = async () => {
    try {
        const res = await settingsApi.getTimezones();
        const rawGroups = res.data?.data?.groups;
        if (Array.isArray(rawGroups) && rawGroups.length > 0) {
            timezoneGroups.value = rawGroups.map((g) => ({
                label: g.region,
                items: g.timezones
            }));
        }
    } catch {
        // Keep hardcoded fallback — endpoint unreachable is non-fatal
    }
};

// Fetch settings on mount
onMounted(async () => {
    await Promise.all([fetchSettings(), fetchTimezones(), refreshActiveShifts()]);
    await fetchPrefixes();
    prevNegativeMode.value = settings.value.stock?.negative_mode;
});

// Watch negative_mode: kalau switch block→allow, konfirmasi dulu.
watch(
    () => settings.value.stock?.negative_mode,
    (newVal, oldVal) => {
        // Skip initial hydration dari API
        if (oldVal === undefined || newVal === prevNegativeMode.value) {
            prevNegativeMode.value = newVal;
            return;
        }
        if (newVal === 'allow' && prevNegativeMode.value === 'block') {
            negativeModeDialog.value = true;
        } else {
            prevNegativeMode.value = newVal;
        }
    }
);

function confirmNegativeModeAllow() {
    prevNegativeMode.value = 'allow';
    negativeModeDialog.value = false;
}

function cancelNegativeModeAllow() {
    settings.value.stock.negative_mode = 'block';
    prevNegativeMode.value = 'block';
    negativeModeDialog.value = false;
}

const fetchSettings = async () => {
    loading.value = true;
    try {
        // Fetch settings and lock statuses in parallel
        const [settingsRes, priceModeLockRes, stockModeLockRes, elektronikLockRes] = await Promise.all([settingsApi.getAll(), settingsApi.checkPriceModeLock(), settingsApi.checkStockModeLock(), settingsApi.checkElektronikLock()]);

        const loadedSettings = settingsRes.data.data.settings;

        // Merge loaded settings dengan default (untuk handle jika ada key yang hilang)
        Object.keys(loadedSettings).forEach((group) => {
            if (settings.value[group]) {
                settings.value[group] = { ...settings.value[group], ...loadedSettings[group] };
            } else {
                settings.value[group] = loadedSettings[group];
            }
        });

        // Set price mode lock status
        if (priceModeLockRes.data.success) {
            priceModeLocked.value = priceModeLockRes.data.data.locked;
            priceModeLockMessage.value = priceModeLockRes.data.data.message;
        }

        // Set stock mode lock status
        if (stockModeLockRes.data.success) {
            stockModeLocked.value = stockModeLockRes.data.data.locked;
            stockModeLockMessage.value = stockModeLockRes.data.data.message;
        }

        // Set elektronik module lock status (tak bisa dimatikan selama ada data serial)
        if (elektronikLockRes.data.success) {
            elektronikLocked.value = elektronikLockRes.data.data.locked;
            elektronikLockMessage.value = elektronikLockRes.data.data.message;
        }
    } catch (error) {
        notify.loadListError('settings');
    } finally {
        loading.value = false;
    }
};

const saveGroup = async (group) => {
    const groupSettings = settings.value[group];
    const settingsArray = Object.entries(groupSettings).map(([key, value]) => ({
        key,
        value: value === null ? '' : value
    }));

    await settingsApi.updateGroup(group, settingsArray);
};

// Save all groups in a tab
const saveTab = async (tabId) => {
    const groups = tabGroups[tabId];
    saving.value[tabId] = true;

    try {
        // Save all groups in this tab
        await Promise.all(groups.map((group) => saveGroup(group)));

        notify.success('Pengaturan berhasil disimpan');

        // Refresh public settings bila store ATAU modul diubah (agar menu/route serial ikut update)
        if (tabId === '0' || tabGroups[tabId].includes('modules')) {
            settingsStore.refresh();
        }
    } catch (error) {
        notify.saveError(error);
    } finally {
        saving.value[tabId] = false;
    }
};

// Prefix document functions
const fetchPrefixes = async () => {
    loadingPrefixes.value = true;
    try {
        const response = await settingsApi.getPrefixes();
        prefixes.value = response.data.data.prefixes;
    } catch (error) {
        notify.loadListError('prefixes');
    } finally {
        loadingPrefixes.value = false;
    }
};

const startEditPrefix = (item) => {
    if (item.is_locked) return;
    editingPrefix.value[item.type] = item.prefix;
};

const cancelEditPrefix = (item) => {
    delete editingPrefix.value[item.type];
};

const isEditingPrefix = (item) => {
    return editingPrefix.value[item.type] !== undefined;
};

const savePrefix = async (item) => {
    const newPrefix = editingPrefix.value[item.type]?.trim()?.toUpperCase();

    // Validate
    if (!newPrefix) {
        notify.error('Prefix tidak boleh kosong');
        return;
    }
    if (!/^[A-Za-z0-9]+$/.test(newPrefix)) {
        notify.error('Prefix hanya boleh berisi huruf dan angka');
        return;
    }
    if (newPrefix.length > 10) {
        notify.error('Prefix maksimal 10 karakter');
        return;
    }

    savingPrefix.value[item.type] = true;
    try {
        await settingsApi.updatePrefix(item.type, newPrefix);
        notify.success('Prefix berhasil diperbarui');
        delete editingPrefix.value[item.type];
        await fetchPrefixes(); // Refresh to get new preview
    } catch (error) {
        notify.saveError(error);
    } finally {
        savingPrefix.value[item.type] = false;
    }
};
</script>

<template>
    <div class="card">
        <div class="font-semibold text-xl mb-4">Pengaturan Sistem</div>

        <!-- Warning: ada shift aktif → perubahan setting fiskal bisa bikin kasir bingung -->
        <Message v-if="activeShifts.count > 0" severity="warn" :closable="false" class="mb-4">
            <div class="text-sm">
                <div class="font-medium mb-1">
                    <i class="pi pi-exclamation-triangle mr-2"></i>
                    Ada {{ activeShifts.count }} shift aktif
                </div>
                <div class="text-xs">
                    <span v-for="(s, i) in activeShifts.shifts" :key="s.id">
                        <b>{{ s.user?.name || '-' }}</b> @ {{ s.terminal?.nama_terminal || '-' }}<span v-if="i < activeShifts.shifts.length - 1">, </span>
                    </span>
                </div>
                <div class="text-xs mt-1">
                    Perubahan setting <b>Pajak / Pembulatan / Promo / Mata Uang / Kalkulasi</b> akan berlaku untuk transaksi berikutnya. Minta kasir yang sedang shift untuk <b>logout-login</b> ulang setelah save agar tampilan konsisten.
                </div>
            </div>
        </Message>

        <ProgressSpinner v-if="loading" class="flex justify-center" />

        <Tabs v-else v-model:value="activeTab">
            <TabList>
                <Tab value="0">Toko</Tab>
                <Tab value="1">Regional</Tab>
                <Tab value="2">Mata Uang</Tab>
                <Tab value="3">Pajak</Tab>
                <Tab value="4">Stok & Kalkulasi</Tab>
                <Tab value="5">Promo</Tab>
                <Tab value="6">Penjadwalan</Tab>
                <Tab value="7">Prefix Dokumen</Tab>
                <Tab value="8">Modul</Tab>
            </TabList>

            <TabPanels>
                <!-- STORE SETTINGS -->
                <TabPanel value="0">
                    <Fluid>
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col gap-2">
                                <label for="store_name">Nama Toko</label>
                                <InputText id="store_name" v-model="settings.store.name" />
                            </div>
                            <div class="flex flex-col gap-2">
                                <label for="store_address">Alamat</label>
                                <Textarea id="store_address" v-model="settings.store.address" rows="3" />
                            </div>
                            <div class="flex flex-col md:flex-row gap-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="store_phone">Telepon</label>
                                    <InputText id="store_phone" v-model="settings.store.phone" />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="store_email">Email</label>
                                    <InputText id="store_email" v-model="settings.store.email" type="email" />
                                </div>
                            </div>
                            <div class="flex flex-col gap-2">
                                <label for="store_npwp">NPWP</label>
                                <InputText id="store_npwp" v-model="settings.store.npwp" />
                            </div>
                            <div class="flex flex-col gap-2">
                                <label for="store_url">URL Website</label>
                                <InputText id="store_url" v-model="settings.store.url" placeholder="https://posip.tokomu.com" />
                                <small class="text-muted-color">Digunakan untuk link struk online. Kosongkan untuk otomatis dari browser.</small>
                            </div>
                            <div class="flex flex-col gap-2">
                                <label for="store_receipt_footer">Footer Struk</label>
                                <Textarea id="store_receipt_footer" v-model="settings.store.receipt_footer" rows="3" placeholder="Terima Kasih!" />
                                <small class="text-muted-color">Ditampilkan di bagian bawah struk (PDF, thermal, dan struk online). Bisa multi-baris.</small>
                            </div>
                            <div class="flex flex-col md:flex-row gap-6 mt-4">
                                <div class="flex flex-col gap-2">
                                    <label>Logo Toko</label>
                                    <ImageUpload v-model="settings.store.logo" folder="settings" label="Upload Logo" previewWidth="200px" previewHeight="100px" />
                                    <small class="text-muted-color">Ukuran maksimal 800x800px, akan dikonversi ke WebP</small>
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label>Icon Toko</label>
                                    <ImageUpload v-model="settings.store.icon" folder="settings" label="Upload Icon" previewWidth="100px" previewHeight="100px" />
                                    <small class="text-muted-color">Icon untuk favicon/shortcut</small>
                                </div>
                                <div class="flex flex-col gap-2">
                                    <label>Background Login</label>
                                    <ImageUpload v-model="settings.store.login_background" folder="settings" label="Upload Background" previewWidth="200px" previewHeight="120px" />
                                    <small class="text-muted-color">Gambar halaman login (rasio 16:9 disarankan)</small>
                                </div>
                            </div>
                            <div class="flex justify-end mt-4">
                                <Button label="Simpan" icon="pi pi-save" :disabled="!canUpdate" v-tooltip.top="!canUpdate ? 'Anda tidak punya akses untuk mengubah pengaturan' : ''" @click="saveTab('0')" :loading="saving['0']" />
                            </div>
                        </div>
                    </Fluid>
                </TabPanel>

                <!-- REGIONAL SETTINGS -->
                <TabPanel value="1">
                    <Fluid>
                        <div class="flex flex-col gap-4">
                            <div class="font-medium text-lg mb-2">Regional</div>
                            <div class="flex flex-col md:flex-row gap-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="regional_timezone">Timezone</label>
                                    <Select
                                        id="regional_timezone"
                                        v-model="settings.regional.timezone"
                                        :options="timezoneGroups"
                                        optionLabel="label"
                                        optionValue="value"
                                        optionGroupLabel="label"
                                        optionGroupChildren="items"
                                        filter
                                        filterPlaceholder="Cari timezone..."
                                    />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="regional_date_format">Format Tanggal</label>
                                    <Select id="regional_date_format" v-model="settings.regional.date_format" :options="dateFormatOptions" optionLabel="label" optionValue="value" filter filterPlaceholder="Cari..." />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="regional_time_format">Format Waktu</label>
                                    <Select id="regional_time_format" v-model="settings.regional.time_format" :options="timeFormatOptions" optionLabel="label" optionValue="value" filter filterPlaceholder="Cari..." />
                                </div>
                            </div>

                            <Divider />

                            <div class="font-medium text-lg mb-2">Format Angka</div>
                            <div class="flex flex-col md:flex-row gap-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="number_qty_decimal">Desimal Quantity</label>
                                    <InputNumber id="number_qty_decimal" v-model="settings.number.qty_decimal_places" :min="0" :max="4" />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="number_percent_decimal">Desimal Persen</label>
                                    <InputNumber id="number_percent_decimal" v-model="settings.number.percent_decimal_places" :min="0" :max="4" />
                                </div>
                            </div>

                            <Divider />

                            <div class="font-medium text-lg mb-2">Format Teks</div>
                            <div class="flex flex-col gap-2">
                                <label for="text_uppercase">Mode Uppercase</label>
                                <Select id="text_uppercase" v-model="settings.text.uppercase_mode" :options="uppercaseModeOptions" optionLabel="label" optionValue="value" filter filterPlaceholder="Cari..." />
                                <small class="text-muted-color">Mengatur apakah input teks otomatis di-uppercase</small>
                            </div>

                            <div class="flex justify-end mt-4">
                                <Button label="Simpan" icon="pi pi-save" :disabled="!canUpdate" v-tooltip.top="!canUpdate ? 'Anda tidak punya akses untuk mengubah pengaturan' : ''" @click="saveTab('1')" :loading="saving['1']" />
                            </div>
                        </div>
                    </Fluid>
                </TabPanel>

                <!-- CURRENCY SETTINGS -->
                <TabPanel value="2">
                    <Fluid>
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col md:flex-row gap-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="currency_code">Kode Mata Uang</label>
                                    <InputText id="currency_code" v-model="settings.currency.code" />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="currency_symbol">Simbol</label>
                                    <InputText id="currency_symbol" v-model="settings.currency.symbol" />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="currency_position">Posisi Simbol</label>
                                    <Select id="currency_position" v-model="settings.currency.position" :options="currencyPositionOptions" optionLabel="label" optionValue="value" filter filterPlaceholder="Cari..." />
                                </div>
                            </div>
                            <div class="flex flex-col md:flex-row gap-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="currency_thousand">Pemisah Ribuan</label>
                                    <Select id="currency_thousand" v-model="settings.currency.thousand_separator" :options="thousandSeparatorOptions" optionLabel="label" optionValue="value" placeholder="Pilih pemisah" />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="currency_decimal">Pemisah Desimal</label>
                                    <Select id="currency_decimal" v-model="settings.currency.decimal_separator" :options="decimalSeparatorOptions" optionLabel="label" optionValue="value" placeholder="Pilih pemisah" />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="currency_decimal_places">Jumlah Desimal</label>
                                    <InputNumber id="currency_decimal_places" v-model="settings.currency.decimal_places" :min="0" :max="4" />
                                </div>
                            </div>
                            <div class="flex justify-end mt-4">
                                <Button label="Simpan" icon="pi pi-save" :disabled="!canUpdate" v-tooltip.top="!canUpdate ? 'Anda tidak punya akses untuk mengubah pengaturan' : ''" @click="saveTab('2')" :loading="saving['2']" />
                            </div>
                        </div>
                    </Fluid>
                </TabPanel>

                <!-- TAX SETTINGS -->
                <TabPanel value="3">
                    <Fluid>
                        <div class="flex flex-col gap-4">
                            <div class="font-medium text-lg mb-2">Pajak Pembelian</div>
                            <div class="flex flex-col md:flex-row gap-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="tax_purchase_name">Nama Pajak</label>
                                    <InputText id="tax_purchase_name" v-model="settings.tax.tax_purchase_name" />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="tax_purchase_percent">Persentase (%)</label>
                                    <InputNumber
                                        id="tax_purchase_percent"
                                        v-model="settings.tax.tax_purchase_percent"
                                        :min="0"
                                        :max="100"
                                        :locale="getLocale"
                                        :minFractionDigits="numberSettings.percentDecimalPlaces"
                                        :maxFractionDigits="numberSettings.percentDecimalPlaces"
                                        suffix="%"
                                    />
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <ToggleSwitch v-model="settings.tax.tax_purchase_included_in_hpp" />
                                <label>Pajak termasuk dalam HPP</label>
                            </div>

                            <Divider />

                            <div class="font-medium text-lg mb-2">Pajak Penjualan</div>
                            <div class="flex flex-col md:flex-row gap-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="tax_sales_name">Nama Pajak</label>
                                    <InputText id="tax_sales_name" v-model="settings.tax.tax_sales_name" />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label for="tax_sales_percent">Persentase (%)</label>
                                    <InputNumber
                                        id="tax_sales_percent"
                                        v-model="settings.tax.tax_sales_percent"
                                        :min="0"
                                        :max="100"
                                        :locale="getLocale"
                                        :minFractionDigits="numberSettings.percentDecimalPlaces"
                                        :maxFractionDigits="numberSettings.percentDecimalPlaces"
                                        suffix="%"
                                    />
                                </div>
                            </div>

                            <Divider />

                            <div class="font-medium text-lg mb-2">Pembulatan Harga</div>
                            <div class="flex flex-col md:flex-row gap-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label>Pembulatan Pembelian</label>
                                    <Select v-model="settings.rounding.purchase_method" :options="roundingMethodOptions" optionLabel="label" optionValue="value" filter filterPlaceholder="Cari..." />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label>Presisi Pembelian</label>
                                    <InputNumber v-model="settings.rounding.purchase_precision" :min="0" />
                                    <small class="text-muted-color">Contoh: 100 = pembulatan ke kelipatan 100</small>
                                </div>
                            </div>
                            <div class="flex flex-col md:flex-row gap-4 mt-2">
                                <div class="flex flex-col gap-2 w-full">
                                    <label>Pembulatan Penjualan</label>
                                    <Select v-model="settings.rounding.sales_method" :options="roundingMethodOptions" optionLabel="label" optionValue="value" filter filterPlaceholder="Cari..." />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label>Presisi Penjualan</label>
                                    <InputNumber v-model="settings.rounding.sales_precision" :min="0" />
                                </div>
                            </div>

                            <div class="flex justify-end mt-4">
                                <Button label="Simpan" icon="pi pi-save" :disabled="!canUpdate" v-tooltip.top="!canUpdate ? 'Anda tidak punya akses untuk mengubah pengaturan' : ''" @click="saveTab('3')" :loading="saving['3']" />
                            </div>
                        </div>
                    </Fluid>
                </TabPanel>

                <!-- STOCK & CALCULATION SETTINGS -->
                <TabPanel value="4">
                    <Fluid>
                        <div class="flex flex-col gap-4">
                            <div class="font-medium text-lg mb-2">Pengaturan Stok</div>
                            <div class="flex flex-col gap-2">
                                <label>Mode Stok Negatif</label>
                                <Select v-model="settings.stock.negative_mode" :options="stockModeOptions" optionLabel="label" optionValue="value" :disabled="stockModeLocked" filter filterPlaceholder="Cari..." />
                                <Message v-if="stockModeLocked" severity="warn" :closable="false" class="mt-2">
                                    <i class="pi pi-lock mr-2"></i>
                                    {{ stockModeLockMessage }}
                                </Message>
                                <small v-else class="text-muted-color">Mengatur apakah transaksi diizinkan jika stok akan menjadi negatif</small>
                            </div>

                            <Divider />

                            <div class="font-medium text-lg mb-2">Pengaturan Kalkulasi</div>
                            <div class="flex flex-col gap-2">
                                <label>Mode Diskon</label>
                                <Select v-model="settings.calculation.discount_mode" :options="discountModeOptions" optionLabel="label" optionValue="value" filter filterPlaceholder="Cari..." />
                                <small class="text-muted-color">Cara menghitung diskon bertingkat</small>
                            </div>

                            <Divider />

                            <div class="font-medium text-lg mb-2">Pengaturan Produk</div>
                            <div class="flex flex-col gap-2">
                                <label>Mode Input Harga</label>
                                <Select v-model="settings.product.price_input_mode" :options="priceInputModeOptions" optionLabel="label" optionValue="value" :disabled="priceModeLocked" filter filterPlaceholder="Cari..." />
                                <Message v-if="priceModeLocked" severity="warn" :closable="false" class="mt-2">
                                    <i class="pi pi-lock mr-2"></i>
                                    {{ priceModeLockMessage }}
                                </Message>
                                <small v-else class="text-muted-color">
                                    <strong>Otomatis:</strong> Harga diinput dari unit terbesar, unit lain dihitung otomatis.<br />
                                    <strong>Manual:</strong> Semua harga per unit diinput satu per satu.
                                </small>
                            </div>

                            <div class="flex justify-end mt-4">
                                <Button label="Simpan" icon="pi pi-save" :disabled="!canUpdate" v-tooltip.top="!canUpdate ? 'Anda tidak punya akses untuk mengubah pengaturan' : ''" @click="saveTab('4')" :loading="saving['4']" />
                            </div>
                        </div>
                    </Fluid>
                </TabPanel>

                <!-- PROMO SETTINGS -->
                <TabPanel value="5">
                    <Fluid>
                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <ToggleSwitch v-model="settings.promo.enabled" />
                                <label class="font-medium">Aktifkan Promo</label>
                            </div>

                            <Divider />

                            <div class="flex items-center gap-2">
                                <ToggleSwitch v-model="settings.promo.allow_manual_discount" />
                                <label>Izinkan Diskon Manual oleh Kasir</label>
                            </div>

                            <div class="flex flex-col md:flex-row gap-4 mt-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label>Maksimal Diskon Manual (%)</label>
                                    <InputNumber
                                        v-model="settings.promo.max_manual_discount_percent"
                                        :min="0"
                                        :max="100"
                                        :locale="getLocale"
                                        :minFractionDigits="numberSettings.percentDecimalPlaces"
                                        :maxFractionDigits="numberSettings.percentDecimalPlaces"
                                        suffix="%"
                                    />
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label>Maksimal Diskon Manual (Nominal)</label>
                                    <InputNumber
                                        v-model="settings.promo.max_manual_discount_nominal"
                                        :min="0"
                                        :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                        :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                        :locale="getLocale"
                                        :minFractionDigits="currencySettings.decimalPlaces"
                                        :maxFractionDigits="currencySettings.decimalPlaces"
                                    />
                                    <small class="text-muted-color">Kosongkan jika tidak ada batas</small>
                                </div>
                            </div>
                            <div class="flex justify-end mt-4">
                                <Button label="Simpan" icon="pi pi-save" :disabled="!canUpdate" v-tooltip.top="!canUpdate ? 'Anda tidak punya akses untuk mengubah pengaturan' : ''" @click="saveTab('5')" :loading="saving['5']" />
                            </div>
                        </div>
                    </Fluid>
                </TabPanel>

                <!-- SCHEDULER SETTINGS -->
                <TabPanel value="6">
                    <Fluid>
                        <div class="flex flex-col gap-4">
                            <Message severity="info" :closable="false" class="mb-2">
                                <i class="pi pi-info-circle mr-2"></i>
                                Penjadwalan otomatis untuk memproses dokumen yang sudah dijadwalkan (seperti Perubahan Harga). Sistem akan memproses dokumen secara otomatis saat ada aktivitas user.
                            </Message>

                            <div class="font-medium text-lg mb-2">Perubahan Harga</div>
                            <div class="flex items-center gap-2">
                                <ToggleSwitch v-model="settings.scheduler.price_change_enabled" />
                                <label class="font-medium">Aktifkan Auto-Apply Perubahan Harga</label>
                            </div>
                            <small class="text-muted-color -mt-2"> Jika diaktifkan, dokumen perubahan harga yang sudah dijadwalkan akan diproses otomatis saat tanggal berlaku tercapai. </small>

                            <div class="flex flex-col md:flex-row gap-4 mt-4">
                                <div class="flex flex-col gap-2 w-full">
                                    <label>Cooldown (menit)</label>
                                    <InputNumber v-model="settings.scheduler.price_change_cooldown" :min="1" :max="60" :disabled="!settings.scheduler.price_change_enabled" />
                                    <small class="text-muted-color">Jeda minimum antar proses (default: 5 menit)</small>
                                </div>
                                <div class="flex flex-col gap-2 w-full">
                                    <label>Max Dokumen per Batch</label>
                                    <InputNumber v-model="settings.scheduler.price_change_max_batch" :min="1" :max="100" :disabled="!settings.scheduler.price_change_enabled" />
                                    <small class="text-muted-color">Jumlah maksimal dokumen yang diproses per batch (default: 50)</small>
                                </div>
                            </div>

                            <div class="flex justify-end mt-4">
                                <Button label="Simpan" icon="pi pi-save" :disabled="!canUpdate" v-tooltip.top="!canUpdate ? 'Anda tidak punya akses untuk mengubah pengaturan' : ''" @click="saveTab('6')" :loading="saving['6']" />
                            </div>
                        </div>
                    </Fluid>
                </TabPanel>

                <!-- PREFIX SETTINGS -->
                <TabPanel value="7">
                    <div class="flex flex-col gap-4">
                        <p class="text-muted-color mb-2">
                            Format nomor dokumen: <code class="bg-surface-100 dark:bg-surface-800 px-2 py-1 rounded">{PREFIX}-{YYMM}-{SEQUENCE}</code>
                            <span class="ml-2 text-sm">(Contoh: PO-2601-0001)</span>
                        </p>
                        <Message severity="info" :closable="false" class="mb-2">
                            <i class="pi pi-info-circle mr-2"></i>
                            Prefix yang sudah memiliki dokumen tidak dapat diubah.
                        </Message>

                        <DataTable :value="prefixes" :loading="loadingPrefixes" stripedRows size="small" class="p-datatable-sm">
                            <Column field="label" header="Tipe Dokumen" style="min-width: 180px">
                                <template #body="{ data }">
                                    <div class="flex items-center gap-2">
                                        <span>{{ data.label }}</span>
                                        <Tag v-if="!data.has_table" value="Belum Tersedia" severity="secondary" class="text-xs" />
                                    </div>
                                </template>
                            </Column>
                            <Column field="prefix" header="Prefix" style="min-width: 150px">
                                <template #body="{ data }">
                                    <div class="flex items-center gap-2">
                                        <template v-if="isEditingPrefix(data)">
                                            <InputText v-model="editingPrefix[data.type]" class="w-24 p-inputtext-sm" @keyup.enter="savePrefix(data)" @keyup.escape="cancelEditPrefix(data)" :style="{ textTransform: 'uppercase' }" maxlength="10" />
                                            <Button icon="pi pi-check" severity="success" text rounded size="small" @click="savePrefix(data)" :loading="savingPrefix[data.type]" />
                                            <Button icon="pi pi-times" severity="secondary" text rounded size="small" @click="cancelEditPrefix(data)" :disabled="savingPrefix[data.type]" />
                                        </template>
                                        <template v-else>
                                            <code class="bg-surface-100 dark:bg-surface-800 px-2 py-1 rounded font-semibold">{{ data.prefix }}</code>
                                            <Button v-if="!data.is_locked" icon="pi pi-pencil" severity="secondary" text rounded size="small" @click="startEditPrefix(data)" v-tooltip.top="'Edit prefix'" />
                                            <i v-else class="pi pi-lock text-muted-color" v-tooltip.top="`Terkunci: ${data.document_count} dokumen`"></i>
                                        </template>
                                    </div>
                                </template>
                            </Column>
                            <Column field="preview" header="Preview" style="min-width: 150px">
                                <template #body="{ data }">
                                    <code class="text-muted-color">{{ data.preview }}</code>
                                </template>
                            </Column>
                            <Column field="last_document" header="Dokumen Terakhir" style="min-width: 180px">
                                <template #body="{ data }">
                                    <template v-if="data.last_document">
                                        <code>{{ data.last_document }}</code>
                                    </template>
                                    <span v-else class="text-muted-color text-sm">-</span>
                                </template>
                            </Column>
                            <Column field="document_count" header="Jumlah" style="min-width: 100px" class="text-center">
                                <template #body="{ data }">
                                    <Tag v-if="data.document_count > 0" :value="data.document_count.toLocaleString()" severity="info" />
                                    <span v-else class="text-muted-color">0</span>
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </TabPanel>

                <!-- MODULE SETTINGS -->
                <TabPanel value="8">
                    <Fluid>
                        <div class="flex flex-col gap-4">
                            <Message severity="info" :closable="false" class="mb-2">
                                <i class="pi pi-info-circle mr-2"></i>
                                Aktifkan modul fitur opsional. <b>Retail selalu aktif.</b> Modul <b>Elektronik (Serial)</b>
                                mengelola barang ber-nomor-seri per unit (Pembelian Serial, Register Unit, Perubahan Data &amp; Koreksi HPP Serial).
                            </Message>

                            <Message v-if="elektronikLocked" severity="warn" :closable="false" class="mb-2"> <i class="pi pi-lock mr-2"></i>{{ elektronikLockMessage }} </Message>

                            <div class="font-medium text-lg mb-2">Modul Elektronik (Serial)</div>
                            <div class="flex items-center gap-2">
                                <ToggleSwitch v-model="settings.modules.elektronik_enabled" :disabled="!canUpdate || elektronikLocked" />
                                <label class="font-medium">Aktifkan Modul Elektronik (barang serial)</label>
                            </div>
                            <small class="text-muted-color -mt-2">
                                Saat nonaktif: menu &amp; fungsi serial disembunyikan, dan produk serial baru tak bisa dibuat.
                                <template v-if="elektronikLocked"> Tidak bisa dinonaktifkan selama masih ada produk/unit serial.</template>
                            </small>

                            <div class="flex justify-end mt-4">
                                <Button label="Simpan" icon="pi pi-save" :disabled="!canUpdate" v-tooltip.top="!canUpdate ? 'Anda tidak punya akses untuk mengubah pengaturan' : ''" @click="saveTab('8')" :loading="saving['8']" />
                            </div>
                        </div>
                    </Fluid>
                </TabPanel>
            </TabPanels>
        </Tabs>
    </div>

    <!-- Print Service (Legacy, opsional) -->
    <div class="card mt-4">
        <div class="font-semibold text-xl mb-4">Cetak Thermal</div>
        <p class="text-muted-color mb-4">
            <strong>Utama:</strong> pasangkan printer langsung dari browser (Chrome/Edge) di Master → POS Terminal.
            Struk otomatis hanya saat checkout jika <code>auto_print_receipt</code> aktif.
        </p>
        <p class="text-muted-color mb-4">Firefox/Safari/iOS: gunakan fallback PDF. Lihat <code>docs/print-support-matrix.md</code>.</p>
        <div class="font-medium mb-2 text-sm">Legacy — POSIP Print Service (opsional)</div>
        <p class="text-muted-color mb-4">Aplikasi lokal untuk printer Windows/network jika browser transport tidak tersedia. Install sekali, berjalan di background (:5123).</p>
        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4">
            <a :href="printServiceDownloadUrl" target="_blank">
                <Button label="Download Installer Legacy" icon="pi pi-download" severity="secondary" outlined />
            </a>
            <small class="text-muted-color">
                <i class="pi pi-info-circle mr-1"></i>
                Deprecated — prefer browser pairing. Legacy dipakai otomatis jika transport browser gagal dan ID printer terminal diisi.
            </small>
        </div>
    </div>

    <!-- Confirm Negative Stock Mode Dialog -->
    <Dialog v-model:visible="negativeModeDialog" :style="{ width: '450px' }" header="Konfirmasi Mode Stok Minus" :modal="true">
        <div class="flex items-start gap-4">
            <i class="pi pi-exclamation-triangle text-3xl text-orange-500 mt-1" />
            <div class="flex-1 text-sm">
                <p class="mb-2">Mode <b>"allow"</b> mengizinkan stok minus saat checkout.</p>
                <p class="mb-2 text-surface-600 dark:text-surface-400">Kalau lebih dari 1 terminal share warehouse yang sama, bisa terjadi <b>oversell</b> (2 kasir jual produk terakhir bersamaan).</p>
                <p class="text-surface-500 text-xs">Gunakan hanya jika: tidak strict tracking stok, ATAU setiap terminal pakai warehouse berbeda.</p>
            </div>
        </div>
        <template #footer>
            <Button label="Batal" icon="pi pi-times" text @click="cancelNegativeModeAllow" />
            <Button label="Ya, Aktifkan" icon="pi pi-check" severity="warn" @click="confirmNegativeModeAllow" />
        </template>
    </Dialog>
</template>

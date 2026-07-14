<script setup>
import { serialIntakesApi, warehousesApi, suppliersApi, produksApi, serialUnitsApi } from '@/api';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const notify = useNotification();
const router = useRouter();
const route = useRoute();
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Pembelian Serial' : 'Input Pembelian Serial'));
const {
    formatCurrency,
    formatNumber,
    shouldUppercase,
    getPrimeDateFormatShort,
    toDateTimeString,
    now,
    parseDateTime,
    getLocale,
    getCurrencyMinFractionDigits,
    getCurrencyMaxFractionDigits,
    getPercentMinFractionDigits,
    getPercentMaxFractionDigits,
    currencySettings
} = useFormatters();

// Options
const produkOptions = ref([]);
const warehouses = ref([]);
const suppliers = ref([]);
const loading = ref(false);
const saving = ref(false);

// Form
const form = ref({
    product_id: null, // ulid
    warehouse_id: null, // ulid
    supplier_id: null, // ulid (opsional)
    tanggal: now(),
    no_doc_referensi: '',
    notes: '',
    tempo_hari: 0,
    // Cash / lunas langsung (hutang dibuat lalu auto-lunas saat approve)
    cash_payment: false,
    cash_metode: 'cash',
    cash_no_referensi: '',
    cash_bank_nama: '',
    cash_bank_rekening: '',
    diskon_1_tipe: 'none',
    diskon_1_nilai: 0,
    diskon_2_tipe: 'none',
    diskon_2_nilai: 0,
    diskon_3_tipe: 'none',
    diskon_3_nilai: 0,
    biaya_kirim_tipe: 'none',
    biaya_kirim_nilai: 0,
    biaya_lain_nama: '',
    biaya_lain_tipe: 'none',
    biaya_lain_nilai: 0,
    units: []
});

const errors = ref({});

// Opsi tipe diskon/biaya (seperti PO)
const tipeOptions = [
    { label: 'Tidak Ada', value: 'none' },
    { label: 'Persen (%)', value: 'percent' },
    { label: 'Nominal (Rp)', value: 'nominal' }
];

const cashMetodeOptions = [
    { label: 'Cash', value: 'cash' },
    { label: 'Transfer', value: 'transfer' }
];

// Ringkasan finansial (dihitung backend — anti-tamper, DRY dgn PO)
const calculated = ref({ subtotal: 0, total_diskon_header: 0, total_setelah_diskon: 0, biaya_kirim_hasil: 0, biaya_lain_hasil: 0, total_biaya_tambahan: 0, dpp: 0, pajak_nama: '', pajak_persen: 0, pajak_nominal: 0, pembulatan: 0, grand_total: 0 });
let recalcTimer = null;

function recalc() {
    if (recalcTimer) clearTimeout(recalcTimer);
    recalcTimer = setTimeout(async () => {
        try {
            const res = await serialIntakesApi.calculate({
                units: form.value.units.map((u) => ({ harga_modal: Number(u.harga_modal) || 0 })),
                diskon_1_tipe: form.value.diskon_1_tipe,
                diskon_1_nilai: form.value.diskon_1_nilai,
                diskon_2_tipe: form.value.diskon_2_tipe,
                diskon_2_nilai: form.value.diskon_2_nilai,
                diskon_3_tipe: form.value.diskon_3_tipe,
                diskon_3_nilai: form.value.diskon_3_nilai,
                biaya_kirim_tipe: form.value.biaya_kirim_tipe,
                biaya_kirim_nilai: form.value.biaya_kirim_nilai,
                biaya_lain_nama: form.value.biaya_lain_nama,
                biaya_lain_tipe: form.value.biaya_lain_tipe,
                biaya_lain_nilai: form.value.biaya_lain_nilai
            });
            if (res.data.success) calculated.value = res.data.data.calculation;
        } catch (e) {
            /* abaikan error preview */
        }
    }, 400);
}

watch(
    () => [
        form.value.units,
        form.value.diskon_1_tipe,
        form.value.diskon_1_nilai,
        form.value.diskon_2_tipe,
        form.value.diskon_2_nilai,
        form.value.diskon_3_tipe,
        form.value.diskon_3_nilai,
        form.value.biaya_kirim_tipe,
        form.value.biaya_kirim_nilai,
        form.value.biaya_lain_tipe,
        form.value.biaya_lain_nilai
    ],
    recalc,
    { deep: true }
);

// Opsi atribut unit
const gradeOptions = ['A', 'B', 'C', 'D', 'E', 'F'];
const batteryConditionOptions = ['Original', 'Replacement', 'Service Center', 'Refurbished'];
const accountStatusOptions = [
    { label: 'Unlocked', value: 'unlocked' },
    { label: 'Locked', value: 'locked' }
];

// Key stabil per baris — cegah input kehilangan fokus saat mengetik
let rowUid = 0;

function normSn(s) {
    const v = (s ?? '').toString().trim();
    return shouldUppercase.value ? v.toUpperCase() : v;
}

onMounted(async () => {
    loading.value = true;
    await Promise.all([loadProduk(), loadWarehouses(), loadSuppliers()]);
    if (isEdit.value) {
        await loadIntake();
    }
    loading.value = false;
    if (form.value.units.length === 0) addEmptyRow();
    recalc();
});

async function loadIntake() {
    try {
        const res = await serialIntakesApi.get(route.params.ulid);
        if (!res.data.success) return;
        const d = res.data.data.serial_intake;
        if (d.status !== 'draft') {
            notify.warn('Hanya draft yang dapat diubah');
            router.push({ name: 'inventory-serial-intake' });
            return;
        }
        form.value = {
            product_id: d.product?.ulid ?? null,
            warehouse_id: d.warehouse?.ulid ?? null,
            supplier_id: d.supplier?.ulid ?? null,
            tanggal: d.tanggal ? parseDateTime(d.tanggal) : now(),
            no_doc_referensi: d.no_doc_referensi || '',
            notes: d.notes || '',
            tempo_hari: d.tempo_hari || 0,
            cash_payment: !!d.cash_payment,
            cash_metode: d.cash_metode || 'cash',
            cash_no_referensi: d.cash_no_referensi || '',
            cash_bank_nama: d.cash_bank_nama || '',
            cash_bank_rekening: d.cash_bank_rekening || '',
            diskon_1_tipe: d.diskon_1_tipe || 'none',
            diskon_1_nilai: Number(d.diskon_1_nilai) || 0,
            diskon_2_tipe: d.diskon_2_tipe || 'none',
            diskon_2_nilai: Number(d.diskon_2_nilai) || 0,
            diskon_3_tipe: d.diskon_3_tipe || 'none',
            diskon_3_nilai: Number(d.diskon_3_nilai) || 0,
            biaya_kirim_tipe: d.biaya_kirim_tipe || 'none',
            biaya_kirim_nilai: Number(d.biaya_kirim_nilai) || 0,
            biaya_lain_nama: d.biaya_lain_nama || '',
            biaya_lain_tipe: d.biaya_lain_tipe || 'none',
            biaya_lain_nilai: Number(d.biaya_lain_nilai) || 0,
            units: (d.units || []).map((u) => ({
                _uid: ++rowUid,
                serial_number: u.serial_number,
                kode_internal: u.kode_internal || '',
                harga_modal: Number(u.harga_modal),
                harga_jual: u.harga_jual != null ? Number(u.harga_jual) : null,
                grade: u.grade || null,
                battery_condition: u.battery_condition || null,
                battery_health: u.battery_health != null ? Number(u.battery_health) : null,
                account_status: u.account_status || null,
                catatan: u.catatan || ''
            }))
        };
    } catch (error) {
        notify.loadForEditError('pembelian serial');
        router.push({ name: 'inventory-serial-intake' });
    }
}

async function loadProduk() {
    try {
        const res = await produksApi.getAll({ is_serial: 1, status: 'active', per_page: 200, sort_field: 'nama_produk', sort_order: 'asc' });
        if (res.data.success) {
            produkOptions.value = res.data.data.produks.map((p) => ({
                label: `${p.kode_produk} — ${p.nama_produk}`,
                value: p.ulid
            }));
        }
    } catch (error) {
        notify.apiError(error, 'Gagal memuat produk serial');
    }
}

async function loadWarehouses() {
    try {
        const res = await warehousesApi.getList();
        if (res.data.success) warehouses.value = res.data.data.warehouses;
    } catch (error) {
        notify.apiError(error, 'Gagal memuat gudang');
    }
}

async function loadSuppliers() {
    try {
        const res = await suppliersApi.getList();
        if (res.data.success) suppliers.value = res.data.data.suppliers;
    } catch (error) {
        notify.apiError(error, 'Gagal memuat supplier');
    }
}

function addEmptyRow() {
    form.value.units.push({
        _uid: ++rowUid,
        serial_number: '',
        kode_internal: '',
        harga_modal: 0, // default 0 — bisa di-override; isi cost riil belakangan via Koreksi HPP Serial
        harga_jual: null,
        grade: null,
        battery_condition: null,
        battery_health: null,
        account_status: null,
        catatan: ''
    });
}

function removeUnit(index) {
    form.value.units.splice(index, 1);
}

// Generate kode internal baris ke-`index`: KI-####### lanjut dari nomor tertinggi di server,
// dengan mempertimbangkan kode KI-####### yang sudah ada di form (cegah dobel antar-baris).
const kodeGenLoading = ref(false);
async function generateKode(index) {
    kodeGenLoading.value = true;
    try {
        const res = await serialUnitsApi.peekKode();
        const data = res.data?.data || {};
        const prefix = data.prefix || 'KI-';
        const pad = Number(data.pad) || 7;
        const highest = Number(data.highest) || 0;

        const re = new RegExp('^' + prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(\\d+)$', 'i');
        let formMax = 0;
        form.value.units.forEach((u) => {
            const m = re.exec((u.kode_internal || '').trim().toUpperCase());
            if (m) formMax = Math.max(formMax, Number(m[1]));
        });

        const next = Math.max(highest, formMax) + 1;
        form.value.units[index].kode_internal = prefix + String(next).padStart(pad, '0');
        if (errors.value[`units.${index}.kode`]) delete errors.value[`units.${index}.kode`];
    } catch (e) {
        notify.apiError(e, 'Gagal generate kode internal');
    } finally {
        kodeGenLoading.value = false;
    }
}

function validate() {
    errors.value = {};
    if (!form.value.product_id) errors.value.product_id = 'Produk serial wajib dipilih';
    if (!form.value.warehouse_id) errors.value.warehouse_id = 'Gudang wajib dipilih';
    if (!form.value.supplier_id) errors.value.supplier_id = 'Supplier wajib dipilih';
    if (!form.value.tanggal) errors.value.tanggal = 'Tanggal wajib diisi';
    if (form.value.units.length === 0) errors.value.units = 'Minimal 1 unit (nomor seri)';

    // SN TIDAK perlu unik (boleh kembar). Kode internal = identitas unik → WAJIB & unik antar-baris.
    const seenKode = [];
    form.value.units.forEach((u, i) => {
        const sn = normSn(u.serial_number);
        if (!sn) errors.value[`units.${i}.sn`] = 'Nomor seri wajib diisi';
        const kode = (u.kode_internal || '').trim().toUpperCase();
        if (!kode) {
            errors.value[`units.${i}.kode`] = 'Kode internal wajib (klik Generate)';
        } else if (seenKode.includes(kode)) {
            errors.value[`units.${i}.kode`] = 'Kode internal duplikat';
        } else {
            seenKode.push(kode);
        }
        if (u.harga_modal == null || Number(u.harga_modal) < 0) errors.value[`units.${i}.modal`] = 'Modal wajib diisi';
        if (u.harga_jual == null || u.harga_jual === '' || Number(u.harga_jual) < 0) errors.value[`units.${i}.jual`] = 'Harga jual wajib diisi';
        if (!u.grade) errors.value[`units.${i}.grade`] = 'Grade wajib';
        if (!u.battery_condition) errors.value[`units.${i}.batcond`] = 'Baterai status wajib';
        if (u.battery_health == null || u.battery_health === '') errors.value[`units.${i}.bathealth`] = 'Health wajib';
        if (!u.account_status) errors.value[`units.${i}.akun`] = 'Status akun wajib';
    });

    return Object.keys(errors.value).length === 0;
}

async function save() {
    if (!validate()) {
        notify.formInvalid();
        return;
    }

    saving.value = true;
    try {
        const payload = {
            product_id: form.value.product_id,
            warehouse_id: form.value.warehouse_id,
            supplier_id: form.value.supplier_id || null,
            tanggal: toDateTimeString(form.value.tanggal),
            no_doc_referensi: form.value.no_doc_referensi || null,
            notes: form.value.notes || null,
            tempo_hari: Number(form.value.tempo_hari) || 0,
            cash_payment: !!form.value.cash_payment,
            cash_metode: form.value.cash_payment ? form.value.cash_metode : null,
            cash_no_referensi: form.value.cash_payment ? form.value.cash_no_referensi || null : null,
            cash_bank_nama: form.value.cash_payment && form.value.cash_metode === 'transfer' ? form.value.cash_bank_nama || null : null,
            cash_bank_rekening: form.value.cash_payment && form.value.cash_metode === 'transfer' ? form.value.cash_bank_rekening || null : null,
            diskon_1_tipe: form.value.diskon_1_tipe,
            diskon_1_nilai: Number(form.value.diskon_1_nilai) || 0,
            diskon_2_tipe: form.value.diskon_2_tipe,
            diskon_2_nilai: Number(form.value.diskon_2_nilai) || 0,
            diskon_3_tipe: form.value.diskon_3_tipe,
            diskon_3_nilai: Number(form.value.diskon_3_nilai) || 0,
            biaya_kirim_tipe: form.value.biaya_kirim_tipe,
            biaya_kirim_nilai: Number(form.value.biaya_kirim_nilai) || 0,
            biaya_lain_nama: form.value.biaya_lain_nama || null,
            biaya_lain_tipe: form.value.biaya_lain_tipe,
            biaya_lain_nilai: Number(form.value.biaya_lain_nilai) || 0,
            units: form.value.units.map((u) => ({
                serial_number: normSn(u.serial_number),
                kode_internal: (u.kode_internal || '').trim().toUpperCase() || null,
                harga_modal: Number(u.harga_modal) || 0,
                harga_jual: u.harga_jual != null && u.harga_jual !== '' ? Number(u.harga_jual) : null,
                grade: u.grade || null,
                battery_condition: u.battery_condition || null,
                battery_health: u.battery_health != null && u.battery_health !== '' ? Number(u.battery_health) : null,
                account_status: u.account_status || null,
                catatan: u.catatan || null
            }))
        };

        const res = isEdit.value ? await serialIntakesApi.update(route.params.ulid, payload) : await serialIntakesApi.create(payload);
        if (res.data.success) {
            notify.saveSuccess('Pembelian Serial', isEdit.value);
            router.push({ name: 'inventory-serial-intake' });
        }
    } catch (error) {
        notify.saveError(error);
        if (error.response?.data?.errors) {
            errors.value = { ...errors.value, ...error.response.data.errors };
        }
    } finally {
        saving.value = false;
    }
}

function cancel() {
    router.push({ name: 'inventory-serial-intake' });
}
</script>

<template>
    <div class="card">
        <!-- Header -->
        <div class="flex items-center gap-4 mb-6">
            <Button icon="pi pi-arrow-left" severity="secondary" text rounded @click="cancel" />
            <div>
                <h2 class="text-2xl font-semibold m-0">{{ pageTitle }}</h2>
                <small class="text-surface-500">Disimpan sebagai draft — stok & HPP produk diperbarui saat di-approve.</small>
            </div>
        </div>

        <!-- Header form -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-2">
            <div>
                <label class="block font-medium mb-1">Produk Serial <span class="text-red-500">*</span></label>
                <Select v-model="form.product_id" :options="produkOptions" optionLabel="label" optionValue="value" filter placeholder="Pilih produk serial" class="w-full" :class="{ 'p-invalid': errors.product_id }" />
                <small v-if="errors.product_id" class="text-red-500">{{ errors.product_id }}</small>
            </div>
            <div>
                <label class="block font-medium mb-1">Gudang <span class="text-red-500">*</span></label>
                <Select v-model="form.warehouse_id" :options="warehouses" optionLabel="nama_warehouse" optionValue="ulid" filter placeholder="Pilih gudang" class="w-full" :class="{ 'p-invalid': errors.warehouse_id }" />
                <small v-if="errors.warehouse_id" class="text-red-500">{{ errors.warehouse_id }}</small>
            </div>
            <div>
                <label class="block font-medium mb-1">Supplier <span class="text-red-500">*</span></label>
                <Select v-model="form.supplier_id" :options="suppliers" optionLabel="nama_supplier" optionValue="ulid" filter placeholder="Pilih supplier" class="w-full" :class="{ 'p-invalid': errors.supplier_id }" />
            </div>
            <div>
                <label class="block font-medium mb-1">Tanggal <span class="text-red-500">*</span></label>
                <DatePicker v-model="form.tanggal" showTime showIcon fluid :dateFormat="getPrimeDateFormatShort" :class="{ 'p-invalid': errors.tanggal }" />
                <small v-if="errors.tanggal" class="text-red-500">{{ errors.tanggal }}</small>
            </div>
            <div>
                <label class="block font-medium mb-1">No. Dok. Referensi <span class="text-surface-400">(opsional)</span></label>
                <InputText v-model="form.no_doc_referensi" placeholder="No. nota supplier" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" maxlength="100" />
            </div>
            <div>
                <label class="block font-medium mb-1">Catatan <span class="text-surface-400">(opsional)</span></label>
                <Textarea v-model="form.notes" rows="1" autoResize class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
            </div>
        </div>

        <!-- Cash / Lunas langsung -->
        <div class="mt-2 p-3 rounded-lg border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2">
                <Checkbox v-model="form.cash_payment" :binary="true" inputId="cash_payment" :disabled="!form.supplier_id" />
                <label for="cash_payment" class="font-medium cursor-pointer">Cash / Lunas langsung</label>
                <small v-if="!form.supplier_id" class="text-surface-400">(pilih supplier dulu)</small>
            </div>
            <small class="block text-surface-500 mt-1">Hutang tetap dibuat saat approve, lalu otomatis dilunasi penuh.</small>

            <div v-if="form.cash_payment" class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                <div>
                    <label class="block text-sm mb-1">Metode Bayar</label>
                    <Select v-model="form.cash_metode" :options="cashMetodeOptions" optionLabel="label" optionValue="value" class="w-full" />
                </div>
                <div>
                    <label class="block text-sm mb-1">No. Referensi <span class="text-surface-400">(bukti/kwitansi)</span></label>
                    <InputText v-model="form.cash_no_referensi" class="w-full" placeholder="No. bukti/kwitansi" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" maxlength="100" />
                </div>
                <template v-if="form.cash_metode === 'transfer'">
                    <div>
                        <label class="block text-sm mb-1">Nama Bank</label>
                        <InputText v-model="form.cash_bank_nama" class="w-full" maxlength="100" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                    </div>
                    <div>
                        <label class="block text-sm mb-1">No. Rekening</label>
                        <InputText v-model="form.cash_bank_rekening" class="w-full" maxlength="50" />
                    </div>
                </template>
            </div>
        </div>

        <Divider />

        <!-- Daftar unit -->
        <div class="flex items-center justify-between mb-3">
            <h6 class="font-medium m-0">Daftar Unit (Nomor Seri)</h6>
            <Button label="Tambah Baris" icon="pi pi-plus" size="small" @click="addEmptyRow" />
        </div>

        <small v-if="errors.units" class="text-red-500 block mb-2">{{ errors.units }}</small>
        <DataTable :value="form.units" dataKey="_uid" class="mb-3" :pt="{ table: { style: 'min-width: 78rem' } }">
            <template #empty>
                <div class="text-center py-4 text-surface-500">Belum ada unit. Klik "Tambah Baris" untuk menambah nomor seri.</div>
            </template>
            <Column header="#" style="width: 50px">
                <template #body="{ index }">{{ index + 1 }}</template>
            </Column>
            <Column header="Kode Internal *" style="min-width: 220px">
                <template #body="{ data, index }">
                    <div class="flex gap-1 items-start">
                        <InputText v-model="data.kode_internal" class="flex-1" style="text-transform: uppercase" placeholder="Generate / ketik" :class="{ 'p-invalid': errors[`units.${index}.kode`] }" maxlength="40" />
                        <Button icon="pi pi-bolt" severity="secondary" outlined :loading="kodeGenLoading" title="Generate kode internal" @click="generateKode(index)" />
                    </div>
                    <small v-if="errors[`units.${index}.kode`]" class="text-red-500">{{ errors[`units.${index}.kode`] }}</small>
                </template>
            </Column>
            <Column header="Nomor Seri *" style="min-width: 200px">
                <template #body="{ data, index }">
                    <InputText v-model="data.serial_number" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" :class="{ 'p-invalid': errors[`units.${index}.sn`] }" maxlength="100" />
                    <small v-if="errors[`units.${index}.sn`]" class="text-red-500">{{ errors[`units.${index}.sn`] }}</small>
                </template>
            </Column>
            <Column header="Harga Modal *" style="min-width: 170px">
                <template #body="{ data, index }">
                    <InputNumber
                        v-model="data.harga_modal"
                        v-select-on-focus
                        :min="0"
                        fluid
                        :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                        :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                        :locale="getLocale"
                        :minFractionDigits="getCurrencyMinFractionDigits"
                        :maxFractionDigits="getCurrencyMaxFractionDigits"
                        :class="{ 'p-invalid': errors[`units.${index}.modal`] }"
                    />
                </template>
            </Column>
            <Column header="Harga Jual *" style="min-width: 170px">
                <template #body="{ data, index }">
                    <InputNumber
                        v-model="data.harga_jual"
                        v-select-on-focus
                        :min="0"
                        fluid
                        :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                        :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                        :locale="getLocale"
                        :minFractionDigits="getCurrencyMinFractionDigits"
                        :maxFractionDigits="getCurrencyMaxFractionDigits"
                        :class="{ 'p-invalid': errors[`units.${index}.jual`] }"
                    />
                </template>
            </Column>
            <Column header="Grade *" style="min-width: 100px">
                <template #body="{ data, index }">
                    <Select v-model="data.grade" :options="gradeOptions" placeholder="Pilih" class="w-full" :class="{ 'p-invalid': errors[`units.${index}.grade`] }" />
                </template>
            </Column>
            <Column header="Baterai Status *" style="min-width: 170px">
                <template #body="{ data, index }">
                    <Select v-model="data.battery_condition" :options="batteryConditionOptions" placeholder="Pilih" class="w-full" :class="{ 'p-invalid': errors[`units.${index}.batcond`] }" />
                </template>
            </Column>
            <Column header="Baterai Health (%) *" style="min-width: 150px">
                <template #body="{ data, index }">
                    <InputNumber
                        v-model="data.battery_health"
                        v-select-on-focus
                        :min="0"
                        :max="100"
                        suffix=" %"
                        fluid
                        :locale="getLocale"
                        :minFractionDigits="getPercentMinFractionDigits"
                        :maxFractionDigits="getPercentMaxFractionDigits"
                        :class="{ 'p-invalid': errors[`units.${index}.bathealth`] }"
                    />
                </template>
            </Column>
            <Column header="Status Akun *" style="min-width: 140px">
                <template #body="{ data, index }">
                    <Select v-model="data.account_status" :options="accountStatusOptions" optionLabel="label" optionValue="value" placeholder="Pilih" class="w-full" :class="{ 'p-invalid': errors[`units.${index}.akun`] }" />
                </template>
            </Column>
            <Column header="Catatan" style="min-width: 160px">
                <template #body="{ data }">
                    <InputText v-model="data.catatan" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" maxlength="255" />
                </template>
            </Column>
            <Column header="" style="width: 60px">
                <template #body="{ index }">
                    <Button icon="pi pi-trash" severity="danger" text rounded @click="removeUnit(index)" v-tooltip.top="'Hapus'" />
                </template>
            </Column>
        </DataTable>

        <div class="flex items-center gap-3 mb-4">
            <Button label="Tambah Baris" icon="pi pi-plus" severity="secondary" outlined @click="addEmptyRow" />
            <span class="text-sm text-surface-500">Total {{ formatNumber(form.units.length) }} unit</span>
        </div>

        <Divider />

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-2">
            <!-- Diskon Header + Biaya Tambahan + Tempo -->
            <div class="flex flex-col gap-5">
                <div>
                    <h6 class="font-medium mb-2">Diskon Header</h6>
                    <div v-for="i in 3" :key="`disk${i}`" class="flex gap-2 mb-2">
                        <Select v-model="form[`diskon_${i}_tipe`]" :options="tipeOptions" optionLabel="label" optionValue="value" class="w-40 shrink-0" />
                        <InputNumber
                            v-if="form[`diskon_${i}_tipe`] !== 'none'"
                            v-model="form[`diskon_${i}_nilai`]"
                            v-select-on-focus
                            :min="0"
                            fluid
                            :prefix="form[`diskon_${i}_tipe`] === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                            :suffix="form[`diskon_${i}_tipe`] === 'percent' ? ' %' : currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                            :locale="getLocale"
                            :minFractionDigits="form[`diskon_${i}_tipe`] === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                            :maxFractionDigits="form[`diskon_${i}_tipe`] === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                        />
                    </div>
                </div>

                <div>
                    <h6 class="font-medium mb-2">Biaya Tambahan</h6>
                    <div class="flex gap-2 mb-2 items-center">
                        <span class="w-24 shrink-0 text-sm text-surface-600">Biaya Kirim</span>
                        <Select v-model="form.biaya_kirim_tipe" :options="tipeOptions" optionLabel="label" optionValue="value" class="w-36 shrink-0" />
                        <InputNumber
                            v-if="form.biaya_kirim_tipe !== 'none'"
                            v-model="form.biaya_kirim_nilai"
                            v-select-on-focus
                            :min="0"
                            fluid
                            :prefix="form.biaya_kirim_tipe === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                            :suffix="form.biaya_kirim_tipe === 'percent' ? ' %' : currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                            :locale="getLocale"
                            :minFractionDigits="form.biaya_kirim_tipe === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                            :maxFractionDigits="form.biaya_kirim_tipe === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                        />
                    </div>
                    <div class="flex gap-2 items-center">
                        <InputText v-model="form.biaya_lain_nama" placeholder="Biaya lain…" class="w-24 shrink-0" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                        <Select v-model="form.biaya_lain_tipe" :options="tipeOptions" optionLabel="label" optionValue="value" class="w-36 shrink-0" />
                        <InputNumber
                            v-if="form.biaya_lain_tipe !== 'none'"
                            v-model="form.biaya_lain_nilai"
                            v-select-on-focus
                            :min="0"
                            fluid
                            :prefix="form.biaya_lain_tipe === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                            :suffix="form.biaya_lain_tipe === 'percent' ? ' %' : currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                            :locale="getLocale"
                            :minFractionDigits="form.biaya_lain_tipe === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                            :maxFractionDigits="form.biaya_lain_tipe === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                        />
                    </div>
                </div>

                <div>
                    <label class="block font-medium mb-1">Tempo (hari)</label>
                    <InputNumber v-model="form.tempo_hari" v-select-on-focus :min="0" :locale="getLocale" placeholder="0" class="w-40" />
                    <small class="text-surface-500 block mt-1">Hutang supplier dibuat saat approve (bila supplier diisi).</small>
                </div>
            </div>

            <!-- Ringkasan -->
            <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4 h-fit">
                <h6 class="font-medium mb-3">Ringkasan</h6>
                <div class="flex flex-col gap-2 text-sm">
                    <div class="flex justify-between">
                        <span>Subtotal ({{ formatNumber(form.units.length) }} unit)</span><span>{{ formatCurrency(calculated.subtotal) }}</span>
                    </div>
                    <div v-if="calculated.total_diskon_header > 0" class="flex justify-between text-red-500">
                        <span>Diskon Header</span><span>-{{ formatCurrency(calculated.total_diskon_header) }}</span>
                    </div>
                    <div v-if="calculated.biaya_kirim_hasil > 0" class="flex justify-between">
                        <span>Biaya Kirim</span><span>{{ formatCurrency(calculated.biaya_kirim_hasil) }}</span>
                    </div>
                    <div v-if="calculated.biaya_lain_hasil > 0" class="flex justify-between">
                        <span>{{ form.biaya_lain_nama || 'Biaya Lain' }}</span
                        ><span>{{ formatCurrency(calculated.biaya_lain_hasil) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>DPP</span><span>{{ formatCurrency(calculated.dpp) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>{{ calculated.pajak_nama || 'Pajak' }} ({{ calculated.pajak_persen }}%)</span><span>{{ formatCurrency(calculated.pajak_nominal) }}</span>
                    </div>
                    <div v-if="calculated.pembulatan && Number(calculated.pembulatan) != 0" class="flex justify-between">
                        <span>Pembulatan</span><span>{{ formatCurrency(calculated.pembulatan) }}</span>
                    </div>
                    <Divider class="my-2" />
                    <div class="flex justify-between font-bold text-lg">
                        <span>Grand Total</span><span class="text-primary">{{ formatCurrency(calculated.grand_total) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <Divider />

        <div class="flex justify-end gap-2">
            <Button label="Batal" severity="secondary" outlined @click="cancel" :disabled="saving" />
            <Button label="Simpan" icon="pi pi-save" @click="save" :loading="saving" />
        </div>
    </div>
</template>

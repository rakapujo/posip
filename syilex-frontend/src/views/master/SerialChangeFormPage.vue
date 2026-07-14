<script setup>
import { serialChangesApi, produksApi } from '@/api';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const notify = useNotification();
const router = useRouter();
const route = useRoute();
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Perubahan Data Serial' : 'Perubahan Data Serial'));
const { shouldUppercase, getPrimeDateFormatShort, toDateTimeString, now, parseDateTime, getLocale, getCurrencyMinFractionDigits, getCurrencyMaxFractionDigits, getPercentMinFractionDigits, getPercentMaxFractionDigits, currencySettings } =
    useFormatters();

const produkOptions = ref([]);
const loading = ref(false);
const saving = ref(false);
const loadingUnits = ref(false);

const form = ref({ product_id: null, tanggal: now(), notes: '' });
const rows = ref([]); // unit tersedia (editable + _checked)
const bulkHarga = ref(null);
const errors = ref({});

const gradeOptions = ['A', 'B', 'C', 'D', 'E', 'F'];
const batteryConditionOptions = ['Original', 'Replacement', 'Service Center', 'Refurbished'];
const accountStatusOptions = [
    { label: 'Unlocked', value: 'unlocked' },
    { label: 'Locked', value: 'locked' }
];

const checkedCount = computed(() => rows.value.filter((r) => r._checked).length);

onMounted(async () => {
    loading.value = true;
    await loadProduk();
    if (isEdit.value) await loadChange();
    loading.value = false;
});

async function loadProduk() {
    try {
        const res = await produksApi.getAll({ is_serial: 1, status: 'active', per_page: 200, sort_field: 'nama_produk', sort_order: 'asc' });
        if (res.data.success) {
            produkOptions.value = res.data.data.produks.map((p) => ({ label: `${p.kode_produk} — ${p.nama_produk}`, value: p.ulid }));
        }
    } catch (error) {
        notify.apiError(error, 'Gagal memuat produk serial');
    }
}

function rowFromUnit(u, checked = false) {
    return {
        serial_unit_id: u.ulid,
        kode_internal: u.kode_internal || null,
        serial_number: u.serial_number,
        harga_jual: u.harga_jual != null ? Number(u.harga_jual) : null,
        grade: u.grade || null,
        battery_condition: u.battery_condition || null,
        battery_health: u.battery_health != null ? Number(u.battery_health) : null,
        account_status: u.account_status || null,
        _checked: checked
    };
}

async function loadUnits(productUlid, preset = null) {
    if (!productUlid) {
        rows.value = [];
        return;
    }
    loadingUnits.value = true;
    try {
        const res = await serialChangesApi.units(productUlid);
        if (res.data.success) {
            rows.value = res.data.data.units.map((u) => {
                const row = rowFromUnit(u, false);
                const pre = preset?.[u.ulid];
                if (pre) {
                    Object.assign(row, pre, { serial_unit_id: u.ulid, _checked: true });
                }
                return row;
            });
        }
    } catch (error) {
        notify.apiError(error, 'Gagal memuat unit');
    } finally {
        loadingUnits.value = false;
    }
}

async function onProductChange() {
    if (isEdit.value) return; // produk immutable saat edit
    await loadUnits(form.value.product_id);
}

async function loadChange() {
    try {
        const res = await serialChangesApi.get(route.params.ulid);
        if (!res.data.success) return;
        const d = res.data.data.serial_change;
        if (d.status !== 'draft') {
            notify.warn('Hanya draft yang dapat diubah');
            router.push({ name: 'master-serial-change' });
            return;
        }
        form.value = { product_id: d.product?.ulid ?? null, tanggal: d.tanggal ? parseDateTime(d.tanggal) : now(), notes: d.notes || '' };

        // Preset nilai baru dari detail (key by serial_unit ulid) — backend kirim serialUnit relasi? tidak.
        // Detail tak punya ulid unit; cocokkan via serial_number lama (before.serial_number) tak reliabel.
        // Strategi: muat unit tersedia, lalu cocokkan detail ke unit by serial_number BARU bila masih ada,
        // kalau tidak, biarkan unchecked. Simpel: muat unit, preset by serial_number lama (before).
        await loadUnits(form.value.product_id);
        (d.details || []).forEach((det) => {
            const beforeSn = det.before?.serial_number;
            const row = rows.value.find((r) => r.serial_number === beforeSn);
            if (row) {
                row.serial_number = det.serial_number;
                row.harga_jual = det.harga_jual != null ? Number(det.harga_jual) : null;
                row.grade = det.grade || null;
                row.battery_condition = det.battery_condition || null;
                row.battery_health = det.battery_health != null ? Number(det.battery_health) : null;
                row.account_status = det.account_status || null;
                row._checked = true;
            }
        });
    } catch (error) {
        notify.loadForEditError('perubahan data serial');
        router.push({ name: 'master-serial-change' });
    }
}

function applyBulkHarga() {
    if (bulkHarga.value == null || bulkHarga.value === '') return;
    rows.value.forEach((r) => {
        if (r._checked) r.harga_jual = Number(bulkHarga.value);
    });
}

function normSn(s) {
    const v = (s ?? '').toString().trim();
    return shouldUppercase.value ? v.toUpperCase() : v;
}

function validate() {
    errors.value = {};
    if (!form.value.product_id) errors.value.product_id = 'Produk wajib dipilih';
    const checked = rows.value.filter((r) => r._checked);
    if (checked.length === 0) errors.value.units = 'Centang minimal 1 unit untuk dikoreksi';
    const seen = [];
    rows.value.forEach((r, i) => {
        if (!r._checked) return;
        const sn = normSn(r.serial_number);
        if (!sn) errors.value[`r.${i}.sn`] = 'SN wajib';
        else if (seen.includes(sn)) errors.value[`r.${i}.sn`] = 'SN duplikat';
        seen.push(sn);
        if (r.harga_jual == null || Number(r.harga_jual) < 0) errors.value[`r.${i}.jual`] = 'Harga wajib';
        if (!r.grade) errors.value[`r.${i}.grade`] = 'wajib';
        if (!r.battery_condition) errors.value[`r.${i}.batcond`] = 'wajib';
        if (r.battery_health == null || r.battery_health === '') errors.value[`r.${i}.health`] = 'wajib';
        if (!r.account_status) errors.value[`r.${i}.akun`] = 'wajib';
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
        const units = rows.value
            .filter((r) => r._checked)
            .map((r) => ({
                serial_unit_id: r.serial_unit_id,
                serial_number: normSn(r.serial_number),
                harga_jual: Number(r.harga_jual) || 0,
                grade: r.grade,
                battery_condition: r.battery_condition,
                battery_health: r.battery_health != null && r.battery_health !== '' ? Number(r.battery_health) : null,
                account_status: r.account_status
            }));
        const payload = { product_id: form.value.product_id, tanggal: toDateTimeString(form.value.tanggal), notes: form.value.notes || null, units };
        const res = isEdit.value ? await serialChangesApi.update(route.params.ulid, payload) : await serialChangesApi.create(payload);
        if (res.data.success) {
            notify.saveSuccess('Perubahan Data Serial', isEdit.value);
            router.push({ name: 'master-serial-change' });
        }
    } catch (error) {
        notify.saveError(error);
        if (error.response?.data?.errors) errors.value = { ...errors.value, ...error.response.data.errors };
    } finally {
        saving.value = false;
    }
}

function cancel() {
    router.push({ name: 'master-serial-change' });
}
</script>

<template>
    <div class="card">
        <div class="flex items-center gap-4 mb-6">
            <Button icon="pi pi-arrow-left" severity="secondary" text rounded @click="cancel" />
            <div>
                <h2 class="text-2xl font-semibold m-0">{{ pageTitle }}</h2>
                <small class="text-surface-500">Koreksi data unit TERSEDIA (SN, harga jual, atribut). Diterapkan saat di-approve.</small>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="block font-medium mb-1">Produk Serial <span class="text-red-500">*</span></label>
                <Select
                    v-model="form.product_id"
                    :options="produkOptions"
                    optionLabel="label"
                    optionValue="value"
                    filter
                    :disabled="isEdit"
                    placeholder="Pilih produk serial"
                    class="w-full"
                    :class="{ 'p-invalid': errors.product_id }"
                    @change="onProductChange"
                />
            </div>
            <div>
                <label class="block font-medium mb-1">Tanggal <span class="text-red-500">*</span></label>
                <DatePicker v-model="form.tanggal" showTime showIcon fluid :dateFormat="getPrimeDateFormatShort" />
            </div>
            <div>
                <label class="block font-medium mb-1">Alasan / Catatan</label>
                <InputText v-model="form.notes" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" maxlength="255" placeholder="mis. salah input SN saat pembelian" />
            </div>
        </div>

        <Divider />

        <div class="flex items-center gap-3 mb-3 flex-wrap">
            <h6 class="font-medium m-0">
                Unit Tersedia <span class="text-surface-500 font-normal">(centang yang mau dikoreksi — {{ checkedCount }} dipilih)</span>
            </h6>
            <div class="flex items-center gap-2 ml-auto">
                <span class="text-sm text-surface-500">Harga Jual (semua dicentang):</span>
                <InputNumber
                    v-model="bulkHarga"
                    :min="0"
                    :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                    :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                    :locale="getLocale"
                    :minFractionDigits="getCurrencyMinFractionDigits"
                    :maxFractionDigits="getCurrencyMaxFractionDigits"
                    class="w-40"
                    placeholder="Rp 0"
                />
                <Button label="Terapkan" icon="pi pi-check" severity="secondary" outlined @click="applyBulkHarga" />
            </div>
        </div>

        <small v-if="errors.units" class="text-red-500 block mb-2">{{ errors.units }}</small>

        <DataTable :value="rows" :loading="loadingUnits" dataKey="serial_unit_id" class="mb-4" :pt="{ table: { style: 'min-width: 70rem' } }">
            <template #empty>
                <div class="text-center py-4 text-surface-500">{{ form.product_id ? 'Tidak ada unit tersedia untuk produk ini.' : 'Pilih produk serial dulu.' }}</div>
            </template>
            <Column header="" style="width: 50px">
                <template #body="{ data }"><Checkbox v-model="data._checked" :binary="true" /></template>
            </Column>
            <Column header="Kode Internal" style="min-width: 150px">
                <template #body="{ data }"
                    ><span class="font-mono">{{ data.kode_internal || '—' }}</span></template
                >
            </Column>
            <Column header="Nomor Seri *" style="min-width: 200px">
                <template #body="{ data, index }">
                    <InputText v-model="data.serial_number" :disabled="!data._checked" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" :class="{ 'p-invalid': errors[`r.${index}.sn`] }" maxlength="100" />
                </template>
            </Column>
            <Column header="Harga Jual *" style="min-width: 170px">
                <template #body="{ data, index }">
                    <InputNumber
                        v-model="data.harga_jual"
                        :disabled="!data._checked"
                        :min="0"
                        fluid
                        :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                        :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                        :locale="getLocale"
                        :minFractionDigits="getCurrencyMinFractionDigits"
                        :maxFractionDigits="getCurrencyMaxFractionDigits"
                        :class="{ 'p-invalid': errors[`r.${index}.jual`] }"
                    />
                </template>
            </Column>
            <Column header="Grade *" style="min-width: 100px">
                <template #body="{ data, index }"><Select v-model="data.grade" :disabled="!data._checked" :options="gradeOptions" class="w-full" :class="{ 'p-invalid': errors[`r.${index}.grade`] }" /></template>
            </Column>
            <Column header="Baterai Status *" style="min-width: 170px">
                <template #body="{ data, index }"><Select v-model="data.battery_condition" :disabled="!data._checked" :options="batteryConditionOptions" class="w-full" :class="{ 'p-invalid': errors[`r.${index}.batcond`] }" /></template>
            </Column>
            <Column header="Baterai Health (%) *" style="min-width: 150px">
                <template #body="{ data, index }">
                    <InputNumber
                        v-model="data.battery_health"
                        :disabled="!data._checked"
                        :min="0"
                        :max="100"
                        suffix=" %"
                        fluid
                        :locale="getLocale"
                        :minFractionDigits="getPercentMinFractionDigits"
                        :maxFractionDigits="getPercentMaxFractionDigits"
                        :class="{ 'p-invalid': errors[`r.${index}.health`] }"
                    />
                </template>
            </Column>
            <Column header="Status Akun *" style="min-width: 140px">
                <template #body="{ data, index }"
                    ><Select v-model="data.account_status" :disabled="!data._checked" :options="accountStatusOptions" optionLabel="label" optionValue="value" class="w-full" :class="{ 'p-invalid': errors[`r.${index}.akun`] }"
                /></template>
            </Column>
        </DataTable>

        <Divider />
        <div class="flex justify-end gap-2">
            <Button label="Batal" severity="secondary" outlined @click="cancel" :disabled="saving" />
            <Button label="Simpan" icon="pi pi-save" @click="save" :loading="saving" />
        </div>
    </div>
</template>

<script setup>
import { serialHppCorrectionsApi, produksApi } from '@/api';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const notify = useNotification();
const router = useRouter();
const route = useRoute();
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Koreksi HPP Serial' : 'Koreksi HPP Serial'));
const { shouldUppercase, getPrimeDateFormatShort, toDateTimeString, now, parseDateTime, getLocale, formatCurrency, getCurrencyMinFractionDigits, getCurrencyMaxFractionDigits, currencySettings } = useFormatters();

const produkOptions = ref([]);
const loading = ref(false);
const saving = ref(false);
const loadingUnits = ref(false);

const form = ref({ product_id: null, tanggal: now(), notes: '' });
const rows = ref([]); // unit tersedia (editable + _checked)
const tax = ref({ name: 'PPN', percent: 0, included_in_hpp: false });
const errors = ref({});

const checkedCount = computed(() => rows.value.filter((r) => r._checked).length);

const moneyProps = {
    min: 0,
    prefix: currencySettings.value.position === 'before' ? currencySettings.value.symbol + ' ' : '',
    suffix: currencySettings.value.position === 'after' ? ' ' + currencySettings.value.symbol : '',
    locale: getLocale.value,
    minFractionDigits: getCurrencyMinFractionDigits.value,
    maxFractionDigits: getCurrencyMaxFractionDigits.value
};

// ── Hitung landed live: DPP = modal + kirim + lain; pajak ikut setting ──
const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
const dppOf = (r) => (Number(r.harga_modal_baru) || 0) + (Number(r.biaya_kirim_baru) || 0) + (Number(r.biaya_lain_baru) || 0);
const pajakOf = (r) => (tax.value.included_in_hpp ? round2((dppOf(r) * tax.value.percent) / 100) : 0);
const landedOf = (r) => round2(dppOf(r) + pajakOf(r));

onMounted(async () => {
    loading.value = true;
    await loadProduk();
    if (isEdit.value) await loadCorrection();
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

function rowFromUnit(u) {
    return {
        serial_unit_id: u.ulid,
        kode_internal: u.kode_internal || null,
        serial_number: u.serial_number,
        cur_modal: u.harga_modal != null ? Number(u.harga_modal) : 0,
        cur_cost: u.cost_per_unit != null ? Number(u.cost_per_unit) : 0,
        harga_modal_baru: u.harga_modal != null ? Number(u.harga_modal) : 0,
        biaya_kirim_baru: 0,
        biaya_lain_baru: 0,
        _checked: false
    };
}

async function loadUnits(productUlid) {
    if (!productUlid) {
        rows.value = [];
        return;
    }
    loadingUnits.value = true;
    try {
        const res = await serialHppCorrectionsApi.units(productUlid);
        if (res.data.success) {
            rows.value = res.data.data.units.map(rowFromUnit);
            if (res.data.data.tax) tax.value = res.data.data.tax;
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

async function loadCorrection() {
    try {
        const res = await serialHppCorrectionsApi.get(route.params.ulid);
        if (!res.data.success) return;
        const d = res.data.data.serial_hpp_correction;
        if (d.status !== 'draft') {
            notify.warn('Hanya draft yang dapat diubah');
            router.push({ name: 'inventory-serial-hpp' });
            return;
        }
        form.value = { product_id: d.product?.ulid ?? null, tanggal: d.tanggal ? parseDateTime(d.tanggal) : now(), notes: d.notes || '' };

        await loadUnits(form.value.product_id);
        (d.details || []).forEach((det) => {
            const row = rows.value.find((r) => r.serial_unit_id === det.serial_unit?.ulid);
            if (row) {
                row.harga_modal_baru = det.harga_modal_baru != null ? Number(det.harga_modal_baru) : row.harga_modal_baru;
                row.biaya_kirim_baru = det.biaya_kirim_baru != null ? Number(det.biaya_kirim_baru) : 0;
                row.biaya_lain_baru = det.biaya_lain_baru != null ? Number(det.biaya_lain_baru) : 0;
                row._checked = true;
            }
        });
    } catch (error) {
        notify.loadForEditError('koreksi HPP serial');
        router.push({ name: 'inventory-serial-hpp' });
    }
}

function validate() {
    errors.value = {};
    if (!form.value.product_id) errors.value.product_id = 'Produk wajib dipilih';
    const checked = rows.value.filter((r) => r._checked);
    if (checked.length === 0) errors.value.units = 'Centang minimal 1 unit untuk dikoreksi';
    rows.value.forEach((r, i) => {
        if (!r._checked) return;
        if (r.harga_modal_baru == null || Number(r.harga_modal_baru) < 0) errors.value[`r.${i}.modal`] = 'wajib';
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
                harga_modal_baru: Number(r.harga_modal_baru) || 0,
                biaya_kirim_baru: Number(r.biaya_kirim_baru) || 0,
                biaya_lain_baru: Number(r.biaya_lain_baru) || 0
            }));
        const payload = { product_id: form.value.product_id, tanggal: toDateTimeString(form.value.tanggal), notes: form.value.notes || null, units };
        const res = isEdit.value ? await serialHppCorrectionsApi.update(route.params.ulid, payload) : await serialHppCorrectionsApi.create(payload);
        if (res.data.success) {
            notify.saveSuccess('Koreksi HPP Serial', isEdit.value);
            router.push({ name: 'inventory-serial-hpp' });
        }
    } catch (error) {
        notify.saveError(error);
        if (error.response?.data?.errors) errors.value = { ...errors.value, ...error.response.data.errors };
    } finally {
        saving.value = false;
    }
}

function cancel() {
    router.push({ name: 'inventory-serial-hpp' });
}
</script>

<template>
    <div class="card">
        <div class="flex items-center gap-4 mb-6">
            <Button icon="pi pi-arrow-left" severity="secondary" text rounded @click="cancel" />
            <div>
                <h2 class="text-2xl font-semibold m-0">{{ pageTitle }}</h2>
                <small class="text-surface-500">Isi komponen biaya per unit — HPP/Landed dihitung otomatis. Tidak mengubah avg_cost agregat produk.</small>
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
                <InputText v-model="form.notes" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" maxlength="255" placeholder="mis. salah input modal saat pembelian" />
            </div>
        </div>

        <Divider />

        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h6 class="font-medium m-0">
                Unit Tersedia <span class="text-surface-500 font-normal">(centang yang mau dikoreksi — {{ checkedCount }} dipilih)</span>
            </h6>
            <span class="text-xs text-surface-500">
                Pajak: <strong>{{ tax.name }} {{ tax.percent }}%</strong> —
                <span :class="tax.included_in_hpp ? 'text-green-600' : 'text-surface-400'">
                    {{ tax.included_in_hpp ? 'masuk HPP' : 'tidak masuk HPP' }}
                </span>
            </span>
        </div>
        <small v-if="errors.units" class="text-red-500 block mb-2">{{ errors.units }}</small>

        <Message v-if="checkedCount > 0" severity="info" :closable="false" class="mb-3">
            <span class="text-sm">Rincian biaya lama tidak tersimpan per unit — isi <strong>Biaya Kirim/Lain</strong> agar <strong>HPP/Landed Baru</strong> sesuai. Bandingkan dengan kolom <strong>HPP/Landed Lama</strong>.</span>
        </Message>

        <DataTable :value="rows" :loading="loadingUnits" dataKey="serial_unit_id" class="mb-4" :pt="{ table: { style: 'min-width: 60rem' } }">
            <template #empty>
                <div class="text-center py-4 text-surface-500">{{ form.product_id ? 'Tidak ada unit tersedia untuk produk ini.' : 'Pilih produk serial dulu.' }}</div>
            </template>
            <Column header="" style="width: 50px">
                <template #body="{ data }"><Checkbox v-model="data._checked" :binary="true" /></template>
            </Column>
            <Column header="Kode Internal" style="min-width: 150px">
                <template #body="{ data }"
                    ><span class="font-mono font-medium">{{ data.kode_internal || '—' }}</span></template
                >
            </Column>
            <Column header="Nomor Seri" style="min-width: 160px">
                <template #body="{ data }"
                    ><span class="font-mono">{{ data.serial_number }}</span></template
                >
            </Column>
            <Column style="min-width: 140px" bodyClass="text-right">
                <template #header>
                    <span v-tooltip.top="'Biaya pokok lama unit ini (sudah termasuk modal + biaya + pajak)'">HPP/Landed Lama</span>
                </template>
                <template #body="{ data }"
                    ><span class="text-surface-500">{{ formatCurrency(data.cur_cost) }}</span></template
                >
            </Column>
            <Column header="Modal Baru *" style="min-width: 160px">
                <template #body="{ data, index }">
                    <InputNumber v-model="data.harga_modal_baru" :disabled="!data._checked" v-bind="moneyProps" fluid :class="{ 'p-invalid': errors[`r.${index}.modal`] }" />
                </template>
            </Column>
            <Column header="Biaya Kirim" style="min-width: 150px">
                <template #body="{ data }">
                    <InputNumber v-model="data.biaya_kirim_baru" :disabled="!data._checked" v-bind="moneyProps" fluid />
                </template>
            </Column>
            <Column header="Biaya Lain" style="min-width: 150px">
                <template #body="{ data }">
                    <InputNumber v-model="data.biaya_lain_baru" :disabled="!data._checked" v-bind="moneyProps" fluid />
                </template>
            </Column>
            <Column header="Pajak" style="min-width: 130px" bodyClass="text-right">
                <template #body="{ data }">
                    <span v-if="tax.included_in_hpp" class="text-surface-600">{{ formatCurrency(pajakOf(data)) }}</span>
                    <span v-else class="text-surface-400 text-xs" v-tooltip.top="'PPN tidak masuk HPP (ikut setting pajak pembelian)'">— (tdk masuk HPP)</span>
                </template>
            </Column>
            <Column header="HPP/Landed Baru *" style="min-width: 170px" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-semibold text-primary">{{ formatCurrency(landedOf(data)) }}</span>
                </template>
            </Column>
        </DataTable>

        <Divider />
        <div class="flex justify-end gap-2">
            <Button label="Batal" severity="secondary" outlined @click="cancel" :disabled="saving" />
            <Button label="Simpan" icon="pi pi-save" @click="save" :loading="saving" />
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { promosApi, tipeCustomersApi, posTerminalsApi, grupsApi, kategorisApi, kategoriCustomersApi, produksApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useNotification } from '@/composables/useNotification';
import { useFormatters } from '@/composables/useFormatters';
import { useConfirm } from 'primevue/useconfirm';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const notify = useNotification();
const confirm = useConfirm();
const { formatCurrency, getPrimeDateFormatShort, toDateString, calculationSettings } = useFormatters();
const discountMode = computed(() => calculationSettings.value?.discountMode || 'recursive');

// ─── Mode ───
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Promo' : 'Buat Promo'));

// ─── Loading ───
const loading = ref(false);
const saving = ref(false);
const approving = ref(false);

// ─── Dropdown options ───
const tipeCustomerOptions = ref([]);
const kategoriCustomerOptions = ref([]);
const terminalOptions = ref([]);
const grupOptions = ref([]);
const kategoriOptions = ref([]);

// ─── Product autocomplete (for target_type='produk') ───
const productSuggestions = ref([]);
const loadingProducts = ref(false);

// ─── Form ───
const form = ref({
    nama_promo: '',
    deskripsi: '',
    customer_type_id: null,
    customer_category_id: null,
    terminal_id: null,
    tanggal_mulai: null,
    tanggal_selesai: null,
    jam_mulai: '',
    jam_selesai: '',
    details: []
});

// ─── Happy Hour toggle (Batasi jam aktif setiap hari) ───
const restrictHour = ref(false);

// Convert 'HH:mm' (or 'HH:mm:ss') string ↔ Date object (for PrimeVue DatePicker timeOnly)
function parseTimeString(hhmm) {
    if (!hhmm) return null;
    const [h, m] = hhmm.split(':').map(Number);
    const d = new Date();
    d.setHours(h || 0, m || 0, 0, 0);
    return d;
}
function formatTimeString(date) {
    if (!(date instanceof Date) || isNaN(date.getTime())) return '';
    const h = String(date.getHours()).padStart(2, '0');
    const m = String(date.getMinutes()).padStart(2, '0');
    return `${h}:${m}`;
}

const jamMulaiDate = computed({
    get: () => parseTimeString(form.value.jam_mulai),
    set: (v) => {
        form.value.jam_mulai = v ? formatTimeString(v) : '';
    }
});
const jamSelesaiDate = computed({
    get: () => parseTimeString(form.value.jam_selesai),
    set: (v) => {
        form.value.jam_selesai = v ? formatTimeString(v) : '';
    }
});

// When user toggles checkbox off → clear jam. When toggled on with empty jam → default 08:00-17:00
watch(restrictHour, (val) => {
    if (!val) {
        form.value.jam_mulai = '';
        form.value.jam_selesai = '';
    } else if (!form.value.jam_mulai) {
        form.value.jam_mulai = '08:00';
        form.value.jam_selesai = '17:00';
    }
});

const originalStatus = ref('draft');
const errors = ref({});

// ─── Empty detail row factory ───
function newDetailRow() {
    return {
        target_type: 'semua',
        target_id: null,
        _target_obj: null, // for autocomplete binding
        min_qty: 1,
        diskon_1_tipe: 'none',
        diskon_1_nilai: 0,
        diskon_2_tipe: 'none',
        diskon_2_nilai: 0,
        diskon_3_tipe: 'none',
        diskon_3_nilai: 0,
        diskon_4_tipe: 'none',
        diskon_4_nilai: 0,
        keterangan: ''
    };
}

const diskonTipeOptions = [
    { label: 'Tidak Ada', value: 'none' },
    { label: 'Persen (%)', value: 'percent' },
    { label: 'Nominal (Rp)', value: 'nominal' }
];

const targetTypeOptions = [
    { label: 'Semua Produk', value: 'semua' },
    { label: 'Grup Produk', value: 'grup' },
    { label: 'Kategori', value: 'kategori' },
    { label: 'Produk', value: 'produk' }
];

// ─── Load reference data ───
async function loadOptions() {
    const [tipeRes, katCustRes, termRes, grupRes, katRes] = await Promise.all([
        tipeCustomersApi.getList().catch(() => null),
        kategoriCustomersApi.getList().catch(() => null),
        posTerminalsApi.getList().catch(() => null),
        grupsApi.getList().catch(() => null),
        kategorisApi.getList().catch(() => null)
    ]);

    if (tipeRes?.data?.success) {
        tipeCustomerOptions.value = (tipeRes.data.data?.tipe_customers ?? []).map((t) => ({ label: `${t.kode_tipe} - ${t.nama_tipe}`, value: t.id }));
    }
    if (katCustRes?.data?.success) {
        kategoriCustomerOptions.value = (katCustRes.data.data?.kategori_customers ?? []).map((k) => ({ label: `${k.kode_kategori} - ${k.nama_kategori}`, value: k.id }));
    }
    if (termRes?.data?.success) {
        terminalOptions.value = (termRes.data.data?.terminals ?? []).map((t) => ({ label: `${t.kode_terminal} - ${t.nama_terminal}`, value: t.id }));
    }
    if (grupRes?.data?.success) {
        grupOptions.value = (grupRes.data.data?.grups ?? []).map((g) => ({ label: `${g.kode_grup} - ${g.nama_grup}`, value: g.id }));
    }
    if (katRes?.data?.success) {
        kategoriOptions.value = (katRes.data.data?.kategoris ?? []).map((k) => ({ label: `${k.kode_kategori} - ${k.nama_kategori}`, value: k.id }));
    }
}

// ─── Load existing promo (edit mode) ───
async function loadPromo() {
    loading.value = true;
    try {
        const res = await promosApi.get(route.params.ulid);
        if (!res.data.success) return;
        const p = res.data.data.promo;
        originalStatus.value = p.display_status ?? p.status;

        // Guard: hanya promo status 'draft' yang bisa diedit.
        // Konsisten dengan PriceChangeFormPage / AdjustmentFormPage.
        if (p.status !== 'draft') {
            notify.cannotEdit('Promo');
            router.push({ name: 'master-promo' });
            return;
        }

        form.value = {
            nama_promo: p.nama_promo,
            deskripsi: p.deskripsi ?? '',
            customer_type_id: p.customer_type?.id ?? null,
            customer_category_id: p.customer_category?.id ?? null,
            terminal_id: p.terminal?.id ?? null,
            tanggal_mulai: p.tanggal_mulai ? new Date(p.tanggal_mulai) : null,
            tanggal_selesai: p.tanggal_selesai ? new Date(p.tanggal_selesai) : null,
            jam_mulai: p.jam_mulai ? p.jam_mulai.substring(0, 5) : '',
            jam_selesai: p.jam_selesai ? p.jam_selesai.substring(0, 5) : '',
            details: []
        };
        // Sync Happy Hour checkbox sesuai data backend
        restrictHour.value = !!p.jam_mulai;
        // Lanjut populate details (dilakukan setelah form sudah dibuat agar watch restrictHour tidak mengganggu)
        form.value.details = (p.details ?? []).map((d) => ({
            target_type: d.target_type,
            target_id: d.target_id ?? null,
            _target_obj: d.target_name ? { id: d.target_id, label: d.target_name } : null,
            min_qty: d.min_qty ?? 1,
            diskon_1_tipe: d.diskon_1_tipe,
            diskon_1_nilai: Number(d.diskon_1_nilai),
            diskon_2_tipe: d.diskon_2_tipe,
            diskon_2_nilai: Number(d.diskon_2_nilai),
            diskon_3_tipe: d.diskon_3_tipe,
            diskon_3_nilai: Number(d.diskon_3_nilai),
            diskon_4_tipe: d.diskon_4_tipe,
            diskon_4_nilai: Number(d.diskon_4_nilai),
            keterangan: d.keterangan ?? ''
        }));
    } catch (e) {
        notify.loadForEditError('promo');
    } finally {
        loading.value = false;
    }
}

function resetForm() {
    form.value = {
        nama_promo: '',
        deskripsi: '',
        customer_type_id: null,
        customer_category_id: null,
        terminal_id: null,
        tanggal_mulai: null,
        tanggal_selesai: null,
        jam_mulai: '',
        jam_selesai: '',
        details: [newDetailRow()]
    };
    restrictHour.value = false;
    originalStatus.value = 'draft';
    errors.value = {};
}

onMounted(async () => {
    await loadOptions();
    if (isEdit.value) {
        await loadPromo();
    } else {
        form.value.details.push(newDetailRow());
    }
});

// React to route param change (create → edit, or edit → create via new route)
watch(
    () => route.params.ulid,
    async (newUlid, oldUlid) => {
        if (newUlid === oldUlid) return;
        if (newUlid) {
            await loadPromo();
        } else {
            resetForm();
        }
    }
);

// ─── Product autocomplete ───
async function searchProducts(event) {
    loadingProducts.value = true;
    try {
        // Pakai endpoint /produks/list — endpoint itu makeVisible('id'), sedangkan
        // getAll() menyembunyikan id karena $hidden di model MasterProduk.
        const res = await produksApi.getList({ search: event.query });
        productSuggestions.value = (res.data.data?.produks ?? []).map((p) => ({
            id: p.id,
            kode_produk: p.kode_produk,
            nama_produk: p.nama_produk,
            label: `${p.kode_produk} - ${p.nama_produk}`
        }));
    } catch {
        productSuggestions.value = [];
    } finally {
        loadingProducts.value = false;
    }
}

function onProductSelect(detail, item) {
    // PrimeVue v4 AutoComplete @item-select payload: { originalEvent, value }
    const product = item?.value ?? item;
    if (product && typeof product === 'object' && product.id) {
        detail.target_id = product.id;
        detail._target_obj = product;
    }
}

// Sync target_id from _target_obj (defensive: AutoComplete v-model can update
// without @item-select firing, e.g. when clicking inside custom option template)
function syncTargetIds() {
    form.value.details.forEach((d) => {
        if (d.target_type === 'produk' && d._target_obj && typeof d._target_obj === 'object' && d._target_obj.id) {
            d.target_id = d._target_obj.id;
        }
    });
}

// ─── When target_type changes, reset target_id ───
function onTargetTypeChange(detail) {
    detail.target_id = null;
    detail._target_obj = null;
}

// ─── Detail rows ───
function addDetail() {
    form.value.details.push(newDetailRow());
}
function removeDetail(idx) {
    form.value.details.splice(idx, 1);
}

// ─── Preview: simulate total diskon for a row (client-side, against harga = 100,000 base) ───
// Returns step-by-step breakdown matching active discount mode ('recursive' or 'sum')
function computeDiskonPreview(detail) {
    const base = 100000;
    const mode = discountMode.value; // 'recursive' or 'sum'
    const steps = [];
    let running = base;
    let total = 0;

    for (let i = 1; i <= 4; i++) {
        const tipe = detail[`diskon_${i}_tipe`];
        const nilai = Number(detail[`diskon_${i}_nilai`]);
        if (!tipe || tipe === 'none' || nilai <= 0) continue;

        // Recursive: each slot applies on remaining balance after previous slots
        // Sum:       each slot applies on the original bruto (base)
        const b = mode === 'recursive' ? running : base;
        const d = tipe === 'percent' ? Math.round((b * Math.min(100, nilai)) / 100) : Math.min(b, Math.round(nilai));

        steps.push({
            slot: i,
            tipe,
            nilai,
            basis: b,
            diskon: d,
            sisa: mode === 'recursive' ? b - d : null,
            formula: tipe === 'percent' ? `${formatCurrency(b)} × ${nilai}% = ${formatCurrency(d)}` : `min(${formatCurrency(b)}, ${formatCurrency(nilai)}) = ${formatCurrency(d)}`
        });

        total += d;
        running -= d;
    }

    if (steps.length === 0) return null;
    const pct = ((total / base) * 100).toFixed(1);
    return { mode, steps, total, base, pct, netto: base - total };
}

// ─── Build payload ───
function buildPayload() {
    return {
        nama_promo: form.value.nama_promo,
        deskripsi: form.value.deskripsi || null,
        customer_type_id: form.value.customer_type_id || null,
        customer_category_id: form.value.customer_category_id || null,
        terminal_id: form.value.terminal_id || null,
        tanggal_mulai: form.value.tanggal_mulai ? toDateString(form.value.tanggal_mulai) : null,
        tanggal_selesai: form.value.tanggal_selesai ? toDateString(form.value.tanggal_selesai) : null,
        jam_mulai: restrictHour.value ? form.value.jam_mulai || null : null,
        jam_selesai: restrictHour.value ? form.value.jam_selesai || null : null,
        details: form.value.details.map((d) => ({
            target_type: d.target_type,
            target_id: d.target_id ?? null,
            min_qty: d.min_qty,
            diskon_1_tipe: d.diskon_1_tipe,
            diskon_1_nilai: d.diskon_1_nilai,
            diskon_2_tipe: d.diskon_2_tipe,
            diskon_2_nilai: d.diskon_2_nilai,
            diskon_3_tipe: d.diskon_3_tipe,
            diskon_3_nilai: d.diskon_3_nilai,
            diskon_4_tipe: d.diskon_4_tipe,
            diskon_4_nilai: d.diskon_4_nilai,
            keterangan: d.keterangan || null
        }))
    };
}

// ─── Save ───
// Frontend validation — catch issues before hitting backend
function validateForm() {
    // Safety net: AutoComplete v-model may have updated _target_obj without
    // firing @item-select — ensure target_id reflects the currently selected product.
    syncTargetIds();
    const errs = {};

    // Header-level
    if (!form.value.nama_promo?.trim()) {
        errs['nama_promo'] = ['Nama promo wajib diisi'];
    }
    if (!form.value.tanggal_mulai) {
        errs['tanggal_mulai'] = ['Tanggal mulai wajib diisi'];
    }

    // Happy Hour
    if (restrictHour.value) {
        if (!form.value.jam_mulai) {
            errs['jam_mulai'] = ['Jam mulai wajib diisi saat Happy Hour aktif'];
        }
        if (!form.value.jam_selesai) {
            errs['jam_selesai'] = ['Jam selesai wajib diisi saat Happy Hour aktif'];
        }
        if (form.value.jam_mulai && form.value.jam_selesai && form.value.jam_mulai >= form.value.jam_selesai) {
            errs['jam_selesai'] = ['Jam selesai harus lebih besar dari jam mulai'];
        }
    }

    // Details
    if (form.value.details.length === 0) {
        errs['details'] = ['Minimal harus ada 1 baris diskon'];
    }

    const targetKeys = new Map();
    form.value.details.forEach((d, idx) => {
        // target_id wajib saat bukan 'semua'
        if (d.target_type !== 'semua' && !d.target_id) {
            errs[`details.${idx}.target_id`] = ['Target wajib dipilih'];
        }
        // min_qty minimal 1
        if (!d.min_qty || d.min_qty < 1) {
            errs[`details.${idx}.min_qty`] = ['Min qty minimal 1'];
        }
        // Minimal 1 slot diskon non-'none' dengan nilai > 0
        const hasDiscount = [1, 2, 3, 4].some((i) => {
            return d[`diskon_${i}_tipe`] !== 'none' && Number(d[`diskon_${i}_nilai`]) > 0;
        });
        if (!hasDiscount) {
            errs[`details.${idx}.diskon`] = ['Minimal 1 slot diskon harus terisi dengan nilai > 0'];
        }
        // Validasi nilai per slot
        for (let i = 1; i <= 4; i++) {
            const tipe = d[`diskon_${i}_tipe`];
            const nilai = Number(d[`diskon_${i}_nilai`]);
            if (tipe !== 'none' && nilai <= 0) {
                errs[`details.${idx}.diskon_${i}_nilai`] = ['Nilai diskon harus > 0'];
            }
            if (tipe === 'percent' && nilai > 100) {
                errs[`details.${idx}.diskon_${i}_nilai`] = ['Persen maksimal 100'];
            }
        }
        // Duplicate detection: sama target_type + target_id
        const key = `${d.target_type}:${d.target_id ?? ''}`;
        if (targetKeys.has(key)) {
            const prevIdx = targetKeys.get(key);
            errs[`details.${idx}.target_id`] = [`Target sama dengan baris ${prevIdx + 1}`];
        } else {
            targetKeys.set(key, idx);
        }
    });

    return errs;
}

async function save() {
    errors.value = {};

    // Client-side validation first
    const clientErrs = validateForm();
    if (Object.keys(clientErrs).length > 0) {
        errors.value = clientErrs;
        notify.formInvalid();
        return;
    }

    saving.value = true;
    try {
        const payload = buildPayload();
        if (isEdit.value) {
            await promosApi.update(route.params.ulid, payload);
            notify.updated('Promo');
        } else {
            const res = await promosApi.create(payload);
            notify.created('Promo');
            // Navigate to edit mode so user can approve directly
            const ulid = res.data.data?.promo?.ulid;
            if (ulid) router.replace({ name: 'master-promo-edit', params: { ulid } });
        }
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {};
            notify.formInvalid();
        } else {
            notify.saveError(e);
        }
    } finally {
        saving.value = false;
    }
}

// ─── Save & Approve ───
async function saveAndApprove() {
    errors.value = {};

    // Client-side validation first
    const clientErrs = validateForm();
    if (Object.keys(clientErrs).length > 0) {
        errors.value = clientErrs;
        notify.formInvalid();
        return;
    }

    confirm.require({
        message: 'Simpan lalu approve promo ini? Promo akan aktif sesuai periode yang ditentukan.',
        header: 'Konfirmasi Simpan & Approve',
        icon: 'pi pi-question-circle',
        acceptLabel: 'Ya, Simpan & Approve',
        rejectLabel: 'Batal',
        acceptClass: 'p-button-success',
        accept: async () => {
            approving.value = true;
            try {
                const payload = buildPayload();
                if (isEdit.value) {
                    await promosApi.update(route.params.ulid, payload);
                    await promosApi.approve(route.params.ulid);
                } else {
                    const res = await promosApi.create(payload);
                    const ulid = res.data.data?.promo?.ulid;
                    if (ulid) await promosApi.approve(ulid);
                }
                notify.approved('Promo');
                router.push({ name: 'master-promo' });
            } catch (e) {
                if (e.response?.status === 422) {
                    errors.value = e.response.data.errors ?? {};
                    notify.formInvalid();
                } else {
                    notify.approveError(e);
                }
            } finally {
                approving.value = false;
            }
        }
    });
}

function goBack() {
    router.push({ name: 'master-promo' });
}

// ─── Permissions ───
const canApprovePerm = computed(() => authStore.can('promo.approve'));
const isDraftOrNew = computed(() => !isEdit.value || originalStatus.value === 'draft');
</script>

<template>
    <div class="card">
        <!-- Header -->
        <div class="flex items-center gap-3 mb-6">
            <Button icon="pi pi-arrow-left" severity="secondary" text rounded @click="goBack" />
            <div>
                <h2 class="text-xl font-bold m-0">{{ pageTitle }}</h2>
                <p class="text-surface-500 text-sm m-0 mt-1" v-if="isEdit && !isDraftOrNew">Promo sudah di-approve. Batalkan approval untuk mengedit.</p>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-12">
            <ProgressSpinner />
        </div>

        <div v-else>
            <!-- ── Header Form ── -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <!-- Nama Promo -->
                <div class="md:col-span-2 flex flex-col gap-2">
                    <label class="font-medium">Nama Promo <span class="text-red-500">*</span></label>
                    <InputText v-model="form.nama_promo" placeholder="Contoh: Promo Akhir Tahun 2025" class="w-full" :class="{ 'p-invalid': errors['nama_promo'] }" :disabled="!isDraftOrNew" />
                    <small class="text-red-500" v-if="errors['nama_promo']">{{ errors['nama_promo'][0] }}</small>
                </div>

                <!-- Deskripsi -->
                <div class="md:col-span-2 flex flex-col gap-2">
                    <label class="font-medium">Deskripsi</label>
                    <Textarea v-model="form.deskripsi" rows="2" class="w-full" placeholder="Keterangan tambahan (opsional)" :disabled="!isDraftOrNew" />
                </div>

                <!-- Tanggal Mulai -->
                <div class="flex flex-col gap-2">
                    <label class="font-medium">Tanggal Mulai <span class="text-red-500">*</span></label>
                    <DatePicker v-model="form.tanggal_mulai" :dateFormat="getPrimeDateFormatShort" showIcon fluid showButtonBar :class="{ 'p-invalid': errors['tanggal_mulai'] }" :disabled="!isDraftOrNew" />
                    <small class="text-red-500" v-if="errors['tanggal_mulai']">{{ errors['tanggal_mulai'][0] }}</small>
                </div>

                <!-- Tanggal Selesai -->
                <div class="flex flex-col gap-2">
                    <label class="font-medium">Tanggal Selesai <span class="text-surface-400 text-xs">(kosong = tanpa batas)</span></label>
                    <DatePicker v-model="form.tanggal_selesai" :dateFormat="getPrimeDateFormatShort" :minDate="form.tanggal_mulai" showIcon fluid showButtonBar showClear :class="{ 'p-invalid': errors['tanggal_selesai'] }" :disabled="!isDraftOrNew" />
                    <small class="text-red-500" v-if="errors['tanggal_selesai']">{{ errors['tanggal_selesai'][0] }}</small>
                </div>

                <!-- Happy Hour: toggle + jam aktif (span full) -->
                <div class="md:col-span-2 border border-surface-200 rounded-lg p-3 bg-surface-50">
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="restrictHour" binary inputId="restrictHour" :disabled="!isDraftOrNew" />
                        <label for="restrictHour" class="text-sm font-medium cursor-pointer select-none"> Batasi jam aktif setiap hari </label>
                        <span class="text-xs text-surface-400">(Happy Hour)</span>
                    </div>

                    <div v-if="restrictHour" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Jam Mulai <span class="text-red-500">*</span></label>
                            <DatePicker v-model="jamMulaiDate" timeOnly hourFormat="24" showIcon iconDisplay="input" fluid :class="{ 'p-invalid': errors['jam_mulai'] }" :disabled="!isDraftOrNew" />
                            <small class="text-red-500" v-if="errors['jam_mulai']">{{ errors['jam_mulai'][0] }}</small>
                        </div>
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Jam Selesai <span class="text-red-500">*</span></label>
                            <DatePicker v-model="jamSelesaiDate" timeOnly hourFormat="24" showIcon iconDisplay="input" fluid :class="{ 'p-invalid': errors['jam_selesai'] }" :disabled="!isDraftOrNew" />
                            <small class="text-red-500" v-if="errors['jam_selesai']">{{ errors['jam_selesai'][0] }}</small>
                        </div>
                    </div>
                    <div v-if="restrictHour" class="mt-2 text-xs text-surface-500">
                        <i class="pi pi-info-circle mr-1"></i>
                        Promo aktif setiap hari dalam periode tanggal di atas, hanya pada jam ini.
                    </div>
                </div>

                <!-- Tipe Customer (opsional) -->
                <div class="flex flex-col gap-2">
                    <label class="font-medium">Tipe Customer <span class="text-surface-400 text-xs">(kosong = semua tipe)</span></label>
                    <Select v-model="form.customer_type_id" :options="tipeCustomerOptions" optionLabel="label" optionValue="value" placeholder="Semua tipe customer" class="w-full" filter showClear :disabled="!isDraftOrNew" />
                </div>

                <!-- Kategori Customer (opsional) -->
                <div class="flex flex-col gap-2">
                    <label class="font-medium">Kategori Customer <span class="text-surface-400 text-xs">(kosong = semua kategori)</span></label>
                    <Select v-model="form.customer_category_id" :options="kategoriCustomerOptions" optionLabel="label" optionValue="value" placeholder="Semua kategori customer" class="w-full" filter showClear :disabled="!isDraftOrNew" />
                </div>

                <!-- Terminal (opsional) -->
                <div class="flex flex-col gap-2">
                    <label class="font-medium">Terminal <span class="text-surface-400 text-xs">(kosong = semua terminal)</span></label>
                    <Select v-model="form.terminal_id" :options="terminalOptions" optionLabel="label" optionValue="value" placeholder="Semua terminal" class="w-full" filter showClear :disabled="!isDraftOrNew" />
                </div>
            </div>

            <!-- ── Detail Rows ── -->
            <div class="mb-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-semibold m-0">Baris Diskon</h3>
                    <Button v-if="isDraftOrNew" label="Tambah Baris" icon="pi pi-plus" severity="secondary" outlined size="small" @click="addDetail" />
                </div>

                <Message v-if="errors['details']" severity="error" class="mb-3">{{ errors['details'][0] }}</Message>

                <div v-for="(detail, idx) in form.details" :key="idx" class="border border-surface-200 rounded-lg p-4 mb-3 bg-surface-50">
                    <div class="flex items-start justify-between mb-3">
                        <span class="font-medium text-sm text-surface-600">Baris {{ idx + 1 }}</span>
                        <Button v-if="isDraftOrNew && form.details.length > 1" icon="pi pi-times" severity="danger" text rounded size="small" @click="removeDetail(idx)" />
                    </div>

                    <!-- Target & Min Qty -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Target</label>
                            <Select v-model="detail.target_type" :options="targetTypeOptions" optionLabel="label" optionValue="value" class="w-full" :disabled="!isDraftOrNew" @change="onTargetTypeChange(detail)" />
                        </div>

                        <!-- Target ID — conditional by target_type -->
                        <div v-if="detail.target_type === 'grup'">
                            <label class="block text-xs text-surface-500 mb-1">Pilih Grup <span class="text-red-500">*</span></label>
                            <Select
                                v-model="detail.target_id"
                                :options="grupOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Pilih grup..."
                                class="w-full"
                                filter
                                showClear
                                :disabled="!isDraftOrNew"
                                :class="{ 'p-invalid': errors[`details.${idx}.target_id`] }"
                            />
                            <small v-if="errors[`details.${idx}.target_id`]" class="text-red-500">
                                {{ errors[`details.${idx}.target_id`][0] }}
                            </small>
                        </div>
                        <div v-else-if="detail.target_type === 'kategori'">
                            <label class="block text-xs text-surface-500 mb-1">Pilih Kategori <span class="text-red-500">*</span></label>
                            <Select
                                v-model="detail.target_id"
                                :options="kategoriOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Pilih kategori..."
                                class="w-full"
                                filter
                                showClear
                                :disabled="!isDraftOrNew"
                                :class="{ 'p-invalid': errors[`details.${idx}.target_id`] }"
                            />
                            <small v-if="errors[`details.${idx}.target_id`]" class="text-red-500">
                                {{ errors[`details.${idx}.target_id`][0] }}
                            </small>
                        </div>
                        <div v-else-if="detail.target_type === 'produk'">
                            <label class="block text-xs text-surface-500 mb-1">Pilih Produk <span class="text-red-500">*</span></label>
                            <AutoComplete
                                v-model="detail._target_obj"
                                :suggestions="productSuggestions"
                                optionLabel="label"
                                :loading="loadingProducts"
                                placeholder="Cari / pilih produk..."
                                class="w-full"
                                fluid
                                :disabled="!isDraftOrNew"
                                dropdown
                                forceSelection
                                :class="{ 'p-invalid': errors[`details.${idx}.target_id`] }"
                                @complete="searchProducts"
                                @item-select="(e) => onProductSelect(detail, e.value)"
                                @clear="
                                    () => {
                                        detail.target_id = null;
                                        detail._target_obj = null;
                                    }
                                "
                            >
                                <template #option="{ option }">
                                    <div class="flex flex-col py-1">
                                        <span class="font-medium text-sm">{{ option.kode_produk }}</span>
                                        <span class="text-xs text-surface-500">{{ option.nama_produk }}</span>
                                    </div>
                                </template>
                            </AutoComplete>
                            <small v-if="errors[`details.${idx}.target_id`]" class="text-red-500">
                                {{ errors[`details.${idx}.target_id`][0] }}
                            </small>
                            <div v-if="detail._target_obj && typeof detail._target_obj === 'object'" class="text-xs text-surface-500 mt-1"><i class="pi pi-box mr-1"></i>{{ detail._target_obj.kode_produk }} — {{ detail._target_obj.nama_produk }}</div>
                        </div>
                        <div v-else>
                            <!-- spacer when target_type='semua' -->
                            <div></div>
                        </div>

                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Min Qty <span class="text-red-500">*</span></label>
                            <InputNumber v-model="detail.min_qty" :min="1" :max="9999" showButtons class="w-full" :disabled="!isDraftOrNew" :class="{ 'p-invalid': errors[`details.${idx}.min_qty`] }" />
                            <small v-if="errors[`details.${idx}.min_qty`]" class="text-red-500">
                                {{ errors[`details.${idx}.min_qty`][0] }}
                            </small>
                        </div>
                    </div>

                    <!-- Error untuk "minimal 1 diskon harus terisi" -->
                    <Message v-if="errors[`details.${idx}.diskon`]" severity="error" class="mb-2" :closable="false">
                        {{ errors[`details.${idx}.diskon`][0] }}
                    </Message>

                    <!-- 4 Diskon Slots -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <div v-for="i in 4" :key="i" class="border border-surface-200 rounded p-2 bg-white">
                            <label class="block text-xs text-surface-500 mb-1 font-medium">Diskon {{ i }}</label>
                            <Select v-model="detail[`diskon_${i}_tipe`]" :options="diskonTipeOptions" optionLabel="label" optionValue="value" class="w-full mb-1" :disabled="!isDraftOrNew" />
                            <InputNumber
                                v-if="detail[`diskon_${i}_tipe`] !== 'none'"
                                v-model="detail[`diskon_${i}_nilai`]"
                                :min="0"
                                :max="detail[`diskon_${i}_tipe`] === 'percent' ? 100 : 999999999"
                                :suffix="detail[`diskon_${i}_tipe`] === 'percent' ? '%' : ''"
                                :prefix="detail[`diskon_${i}_tipe`] === 'nominal' ? 'Rp ' : ''"
                                :minFractionDigits="0"
                                :maxFractionDigits="detail[`diskon_${i}_tipe`] === 'percent' ? 2 : 0"
                                class="w-full"
                                :disabled="!isDraftOrNew"
                                :class="{ 'p-invalid': errors[`details.${idx}.diskon_${i}_nilai`] }"
                            />
                            <small v-if="errors[`details.${idx}.diskon_${i}_nilai`]" class="text-red-500 block mt-1">
                                {{ errors[`details.${idx}.diskon_${i}_nilai`][0] }}
                            </small>
                        </div>
                    </div>

                    <!-- Preview: detailed breakdown of discount calculation -->
                    <div v-if="computeDiskonPreview(detail)" class="mt-2 border border-primary-200 rounded bg-primary-50 px-3 py-2">
                        <div class="flex items-center justify-between mb-1">
                            <div class="text-xs font-semibold text-primary flex items-center">
                                <i class="pi pi-calculator mr-1"></i>
                                Simulasi Diskon
                                <span class="ml-2 text-[10px] font-normal text-surface-500">
                                    Mode: <strong>{{ computeDiskonPreview(detail).mode === 'recursive' ? 'Rekursif (berantai)' : 'Sum (bruto tetap)' }}</strong>
                                </span>
                            </div>
                            <div class="text-xs text-surface-500">Contoh harga Rp 100.000</div>
                        </div>

                        <!-- Step-by-step breakdown -->
                        <div class="space-y-0.5 font-mono text-[11px] leading-relaxed">
                            <div v-for="step in computeDiskonPreview(detail).steps" :key="step.slot" class="flex items-center gap-2 text-surface-700">
                                <span class="inline-block w-12 text-surface-500">Slot {{ step.slot }}:</span>
                                <span class="flex-1">
                                    {{ step.formula }}
                                    <span v-if="step.sisa !== null" class="text-surface-400"> → sisa {{ formatCurrency(step.sisa) }} </span>
                                </span>
                            </div>
                        </div>

                        <!-- Total summary -->
                        <div class="mt-1.5 pt-1.5 border-t border-primary-200 text-[11px] flex items-center justify-between">
                            <span class="text-surface-600">
                                Total hemat:
                                <strong class="text-primary">{{ formatCurrency(computeDiskonPreview(detail).total) }}</strong>
                                <span class="text-surface-400 ml-1">({{ computeDiskonPreview(detail).pct }}%)</span>
                            </span>
                            <span class="text-surface-600">
                                Harga akhir:
                                <strong class="text-emerald-600">{{ formatCurrency(computeDiskonPreview(detail).netto) }}</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Keterangan -->
                    <div class="mt-3">
                        <label class="block text-xs text-surface-500 mb-1">Keterangan Baris (opsional)</label>
                        <InputText v-model="detail.keterangan" placeholder="Catatan untuk baris ini..." class="w-full" :disabled="!isDraftOrNew" />
                    </div>
                </div>

                <div v-if="form.details.length === 0" class="text-center text-surface-400 py-4 border border-dashed border-surface-300 rounded-lg">Tambah minimal 1 baris diskon</div>
            </div>

            <!-- ── Action Buttons ── -->
            <div class="flex justify-between items-center pt-4 border-t border-surface-200">
                <Button label="Kembali" icon="pi pi-arrow-left" severity="secondary" outlined @click="goBack" />
                <div class="flex gap-2" v-if="isDraftOrNew">
                    <Button label="Simpan Draft" icon="pi pi-save" severity="secondary" :loading="saving" @click="save" />
                    <Button v-if="canApprovePerm" label="Simpan & Approve" icon="pi pi-check" severity="success" :loading="approving" @click="saveAndApprove" />
                </div>
            </div>
        </div>
    </div>
</template>

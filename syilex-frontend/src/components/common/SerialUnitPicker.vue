<script setup>
/**
 * Pemilih unit serial (reusable) — tabel SN tersedia + checkbox.
 * Dipakai di form Transfer / Adjustment-keluar / Opname / Retur Beli / Penjualan.
 *
 * v-model = array ulid unit terpilih.
 *
 * Jangan deep-watch `selected`: PrimeVue sering mutasi nested di object baris →
 * deep watch emit terus → parent update → sync → loop. Pakai @update:selection.
 */
import { ref, watch, computed } from 'vue';
import { serialUnitsApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useSettingsStore } from '@/stores/settings';

const props = defineProps({
    productId: { type: [String, Number], default: null },
    warehouseId: { type: [String, Number], default: null },
    // null/undefined = belum diinisialisasi (defaultAll boleh centang semua).
    // Array (termasuk []) = nilai eksplisit.
    modelValue: { type: Array, default: null },
    showSell: { type: Boolean, default: true },
    defaultAll: { type: Boolean, default: false },
    /** tersedia (retur beli) | terjual (retur jual) */
    status: { type: String, default: 'tersedia' },
    customerId: { type: [String, Number], default: null },
    salesId: { type: [String, Number], default: null },
    saleDetailId: { type: [String, Number], default: null },
    /** Batasi unit ke dokumen PBS (retur beli linked) */
    intakeId: { type: [String, Number], default: null },
    /** Free retur beli + require_purchased: filter unit asal supplier */
    supplierId: { type: [String, Number], default: null },
    /** Q5: retur jual SN tetap boleh saat elektronik OFF */
    allowWhenDisabled: { type: Boolean, default: false }
});
const emit = defineEmits(['update:modelValue', 'change']);

const { formatCurrency, formatPercent } = useFormatters();
const notify = useNotification();
const settingsStore = useSettingsStore();
const serialEnabled = computed(() => settingsStore.serialEnabled || props.allowWhenDisabled);

const units = ref([]);
const selected = ref([]);
const loading = ref(false);

const scanInput = ref('');
const scanFeedback = ref(null);
/** Abaikan response load usang (remount / ganti gudang cepat). */
let loadSeq = 0;

function idsKey(ids) {
    return [...(ids || [])].map(String).sort().join('|');
}

function selectedIds() {
    return selected.value.map((u) => u.ulid);
}

function applyIdsToSelected(ids) {
    const set = new Set([...(ids || [])].map(String));
    selected.value = units.value.filter((u) => set.has(String(u.ulid)));
}

function emitIfChanged(rows) {
    const list = rows || [];
    const ids = list.map((u) => u.ulid);
    if (idsKey(ids) === idsKey(props.modelValue)) return;
    emit('update:modelValue', ids);
    emit('change', list);
}

/** Hanya dari klik user / scan / tombol — bukan dari sync props. */
function onSelectionUpdate(rows) {
    selected.value = rows || [];
    emitIfChanged(selected.value);
}

function onScan() {
    const val = (scanInput.value || '').trim();
    if (!val) return;
    const norm = val.toLowerCase();
    const eq = (a) => String(a ?? '') === val;
    const ci = (a) => String(a ?? '').toLowerCase() === norm;
    const unit =
        units.value.find((u) => eq(u.kode_internal)) ||
        units.value.find((u) => ci(u.kode_internal)) ||
        units.value.find((u) => eq(u.serial_number)) ||
        units.value.find((u) => ci(u.serial_number));
    const labelOf = (u) => u.kode_internal || u.serial_number;

    if (!unit) {
        scanFeedback.value = { ok: false, msg: `Kode "${val}" tidak ada di unit ${props.status === 'terjual' ? 'terjual' : 'tersedia'} gudang ini.` };
    } else if (selected.value.some((u) => u.ulid === unit.ulid)) {
        scanFeedback.value = { ok: false, msg: `${labelOf(unit)} sudah ditandai.` };
    } else {
        onSelectionUpdate([...selected.value, unit]);
        scanFeedback.value = { ok: true, msg: `✓ ${labelOf(unit)} ditandai (${selected.value.length} dipilih).` };
    }
    scanInput.value = '';
}

function selectAll() {
    onSelectionUpdate([...units.value]);
}
function clearAll() {
    onSelectionUpdate([]);
}

async function load() {
    const seq = ++loadSeq;
    if (!serialEnabled.value || !props.productId) {
        units.value = [];
        selected.value = [];
        return;
    }
    loading.value = true;
    try {
        const res = await serialUnitsApi.available({
            product_id: props.productId,
            warehouse_id: props.warehouseId || undefined,
            status: props.status || 'tersedia',
            customer_id: props.customerId || undefined,
            sales_id: props.salesId || undefined,
            sale_detail_id: props.saleDetailId || undefined,
            intake_id: props.intakeId || undefined,
            supplier_id: props.supplierId || undefined
        });
        if (seq !== loadSeq) return;
        units.value = res.data?.success ? res.data.data.items : [];
        const uninitialized = props.modelValue === null || props.modelValue === undefined;
        if (props.defaultAll && uninitialized && units.value.length > 0) {
            onSelectionUpdate([...units.value]);
        } else {
            applyIdsToSelected(props.modelValue);
        }
    } catch (e) {
        if (seq !== loadSeq) return;
        notify.apiError(e, 'Gagal memuat unit serial');
        units.value = [];
    } finally {
        if (seq === loadSeq) loading.value = false;
    }
}

const batteryText = (u) =>
    [
        u.battery_health != null ? formatPercent(u.battery_health) : null,
        u.battery_cycle_count != null ? `Cyc ${u.battery_cycle_count}` : null,
        u.battery_condition
    ]
        .filter(Boolean)
        .join(' ') || '—';

watch(
    () => `${serialEnabled.value ? 1 : 0}|${props.productId ?? ''}|${props.warehouseId ?? ''}|${props.status ?? ''}|${props.customerId ?? ''}|${props.salesId ?? ''}|${props.saleDetailId ?? ''}|${props.intakeId ?? ''}|${props.supplierId ?? ''}`,
    load,
    { immediate: true }
);

watch(
    () => props.modelValue,
    (ids) => {
        if (idsKey(selectedIds()) === idsKey(ids)) return;
        applyIdsToSelected(ids);
    }
);
</script>

<template>
    <div v-if="serialEnabled">
        <div v-if="productId" class="flex flex-wrap items-center gap-2 mb-2">
            <IconField iconPosition="left" class="flex-1" style="min-width: 220px">
                <InputIcon class="pi pi-qrcode" />
                <InputText v-model="scanInput" @keyup.enter="onScan" placeholder="Scan / ketik kode internal / nomor seri lalu Enter…" class="w-full" />
            </IconField>
            <Button label="Centang semua" icon="pi pi-check-square" size="small" severity="secondary" outlined @click="selectAll" :disabled="!units.length" />
            <Button label="Kosongkan" icon="pi pi-eraser" size="small" severity="secondary" text @click="clearAll" :disabled="!selected.length" />
        </div>
        <small v-if="scanFeedback" :class="scanFeedback.ok ? 'text-green-600' : 'text-red-500'" class="block mb-2 text-xs">{{ scanFeedback.msg }}</small>

        <DataTable
            :value="units"
            :selection="selected"
            dataKey="ulid"
            :loading="loading"
            size="small"
            scrollable
            scrollHeight="260px"
            stripedRows
            class="text-sm"
            @update:selection="onSelectionUpdate"
        >
            <Column selectionMode="multiple" headerStyle="width: 3rem" />
            <Column field="kode_internal" header="Kode Internal">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.kode_internal || '—' }}</span>
                </template>
            </Column>
            <Column field="serial_number" header="Nomor Seri">
                <template #body="{ data }">
                    <span class="font-mono">{{ data.serial_number }}</span>
                </template>
            </Column>
            <Column header="Grade" style="width: 70px; text-align: center">
                <template #body="{ data }">{{ data.grade || '—' }}</template>
            </Column>
            <Column header="Baterai">
                <template #body="{ data }">{{ batteryText(data) }}</template>
            </Column>
            <Column header="Catatan" style="min-width: 120px">
                <template #body="{ data }">{{ data.catatan || '—' }}</template>
            </Column>
            <Column header="Modal" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.harga_modal) }}</template>
            </Column>
            <Column v-if="showSell" header="Harga Jual" bodyClass="text-right">
                <template #body="{ data }">{{ data.harga_jual != null ? formatCurrency(data.harga_jual) : '—' }}</template>
            </Column>

            <template #empty>
                <div class="text-center text-surface-500 py-3 text-sm">
                    {{ productId ? 'Tidak ada unit tersedia di gudang ini.' : 'Pilih produk dulu.' }}
                </div>
            </template>
        </DataTable>

        <div class="text-xs mt-1" :class="selected.length ? 'text-primary font-medium' : 'text-surface-500'">
            <i class="pi pi-check-circle mr-1" v-if="selected.length"></i>{{ selected.length }} unit dipilih
        </div>
    </div>
</template>

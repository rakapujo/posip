<script setup>
/**
 * Pemilih unit serial (reusable) — tabel SN tersedia + checkbox.
 * Dipakai di form Transfer / Adjustment-keluar / Opname / Retur Beli.
 * Memuat unit dari endpoint serial-units/available (status tersedia, filter gudang).
 *
 * v-model = array ulid unit terpilih.
 */
import { ref, watch } from 'vue';
import { serialUnitsApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const props = defineProps({
    productId: { type: [String, Number], default: null }, // ulid produk
    warehouseId: { type: [String, Number], default: null }, // ulid/id gudang sumber (opsional)
    // array ulid unit terpilih. null/undefined = BELUM diinisialisasi (untuk defaultAll: centang
    // semua). Array (termasuk []) = nilai eksplisit yang dihormati (uncheck "nempel", tahan re-mount).
    modelValue: { type: Array, default: null },
    showSell: { type: Boolean, default: true }, // tampilkan kolom harga jual
    defaultAll: { type: Boolean, default: false } // centang semua saat pertama muat (mis. Opname)
});
const emit = defineEmits(['update:modelValue', 'change']);

const { formatCurrency, formatPercent } = useFormatters();
const notify = useNotification();

const units = ref([]);
const selected = ref([]);
const loading = ref(false);

// ── Scan SN: cocokkan ke unit yang sudah dimuat (client-side, tanpa endpoint) ──
const scanInput = ref('');
const scanFeedback = ref(null); // { ok: bool, msg: string }

function onScan() {
    const val = (scanInput.value || '').trim();
    if (!val) return;
    const norm = val.toLowerCase();
    const eq = (a) => String(a ?? '') === val;
    const ci = (a) => String(a ?? '').toLowerCase() === norm;
    // Label barcode = kode_internal (unik) → cocokkan itu dulu, lalu nomor seri (boleh kembar).
    const unit = units.value.find((u) => eq(u.kode_internal)) || units.value.find((u) => ci(u.kode_internal)) || units.value.find((u) => eq(u.serial_number)) || units.value.find((u) => ci(u.serial_number));
    const labelOf = (u) => u.kode_internal || u.serial_number;

    if (!unit) {
        scanFeedback.value = { ok: false, msg: `Kode "${val}" tidak ada di unit tersedia gudang ini.` };
    } else if (selected.value.some((u) => u.ulid === unit.ulid)) {
        scanFeedback.value = { ok: false, msg: `${labelOf(unit)} sudah ditandai.` };
    } else {
        selected.value = [...selected.value, unit];
        scanFeedback.value = { ok: true, msg: `✓ ${labelOf(unit)} ditandai (${selected.value.length} dipilih).` };
    }
    scanInput.value = '';
}

function selectAll() {
    selected.value = [...units.value];
}
function clearAll() {
    selected.value = [];
}

function syncSelectionFromModel() {
    const set = new Set(props.modelValue || []);
    selected.value = units.value.filter((u) => set.has(u.ulid));
}

async function load() {
    if (!props.productId) {
        units.value = [];
        selected.value = [];
        return;
    }
    loading.value = true;
    try {
        const res = await serialUnitsApi.available({
            product_id: props.productId,
            warehouse_id: props.warehouseId || undefined
        });
        units.value = res.data?.success ? res.data.data.items : [];
        // Auto-centang-semua HANYA saat modelValue belum diinisialisasi (null/undefined) — BUKAN []
        // yang berarti user sengaja mengosongkan. Sekali parent menyimpan array, defaultAll tak
        // menyala lagi (tahan re-mount) → uncheck (termasuk produk 1 SN) tetap nempel.
        const uninitialized = props.modelValue === null || props.modelValue === undefined;
        if (props.defaultAll && uninitialized && units.value.length > 0) {
            selected.value = [...units.value];
        } else {
            syncSelectionFromModel();
        }
    } catch (e) {
        notify.apiError(e, 'Gagal memuat unit serial');
        units.value = [];
    } finally {
        loading.value = false;
    }
}

const batteryText = (u) => [u.battery_health != null ? formatPercent(u.battery_health) : null, u.battery_condition].filter(Boolean).join(' ') || '—';

watch(() => [props.productId, props.warehouseId], load, { immediate: true });

// Sinkron bila modelValue diubah dari luar (mis. muat draft)
watch(
    () => props.modelValue,
    () => {
        const cur = new Set(selected.value.map((u) => u.ulid));
        const incoming = new Set(props.modelValue || []);
        if (cur.size !== incoming.size || [...incoming].some((x) => !cur.has(x))) {
            syncSelectionFromModel();
        }
    }
);

watch(
    selected,
    (val) => {
        emit(
            'update:modelValue',
            val.map((u) => u.ulid)
        );
        emit('change', val); // objek unit (untuk konsumen yang butuh harga_modal dll)
    },
    { deep: true }
);
</script>

<template>
    <div>
        <!-- Scan SN (dari barcode label unit) → tandai unit -->
        <div v-if="productId" class="flex flex-wrap items-center gap-2 mb-2">
            <IconField iconPosition="left" class="flex-1" style="min-width: 220px">
                <InputIcon class="pi pi-qrcode" />
                <InputText v-model="scanInput" @keyup.enter="onScan" placeholder="Scan / ketik kode internal / nomor seri lalu Enter…" class="w-full" />
            </IconField>
            <Button label="Centang semua" icon="pi pi-check-square" size="small" severity="secondary" outlined @click="selectAll" :disabled="!units.length" />
            <Button label="Kosongkan" icon="pi pi-eraser" size="small" severity="secondary" text @click="clearAll" :disabled="!selected.length" />
        </div>
        <small v-if="scanFeedback" :class="scanFeedback.ok ? 'text-green-600' : 'text-red-500'" class="block mb-2 text-xs">{{ scanFeedback.msg }}</small>

        <DataTable :value="units" v-model:selection="selected" dataKey="ulid" :loading="loading" size="small" scrollable scrollHeight="260px" stripedRows class="text-sm">
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

        <div class="text-xs mt-1" :class="selected.length ? 'text-primary font-medium' : 'text-surface-500'"><i class="pi pi-check-circle mr-1" v-if="selected.length"></i>{{ selected.length }} unit dipilih</div>
    </div>
</template>

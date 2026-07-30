<script setup>
/**
 * Reusable right drawer: search products → list product×unit (or 1 row/product).
 * Parent injects fetch + taken rules; emit select({ product, unit, unitObj, is_serial }).
 */
import { ref, watch, computed } from 'vue';
import { flattenProductUnitRows, productUnitKey } from '@/utils/productUnitLineHelpers';

const props = defineProps({
    visible: { type: Boolean, default: false },
    query: { type: String, default: '' },
    title: { type: String, default: 'Pilih Produk' },
    /** async (query: string) => Product[] */
    fetchProducts: { type: Function, required: true },
    /** (product, unitObj) => number|null */
    getUnitPrice: { type: Function, default: null },
    /** (row) => boolean — row already on form */
    isRowTaken: { type: Function, default: null },
    /** Set of keys from productUnitKey / row.key — alternative to isRowTaken */
    takenKeys: { type: Object, default: null },
    expandUnits: { type: Boolean, default: true },
    serialOnly: { type: Boolean, default: false },
    includeSerial: { type: Boolean, default: true },
    showPrice: { type: Boolean, default: true },
    showKonversi: { type: Boolean, default: true },
    takenLabel: { type: String, default: 'Sudah ada' },
    /** Ringkas mode picker (histori / stok / dokumen) — tampil di drawer */
    modeHint: { type: String, default: '' },
    /** (n) => string */
    formatPrice: { type: Function, default: null }
});

const emit = defineEmits(['update:visible', 'select', 'taken-click']);

const localQuery = ref('');
const loading = ref(false);
const products = ref([]);
const searched = ref(false);
let searchSeq = 0;

const rows = computed(() =>
    flattenProductUnitRows(products.value, {
        expandUnits: props.expandUnits,
        serialOnly: props.serialOnly,
        includeSerial: props.includeSerial,
        getUnitPrice: props.getUnitPrice
    })
);

function isTaken(row) {
    if (props.isRowTaken) return !!props.isRowTaken(row);
    if (props.takenKeys && typeof props.takenKeys.has === 'function') {
        const serialKey = productUnitKey(row.product.id, row.unit, row.is_serial);
        return props.takenKeys.has(serialKey) || props.takenKeys.has(row.key);
    }
    return false;
}

function displayPrice(n) {
    if (n == null || Number.isNaN(n)) return '—';
    if (props.formatPrice) return props.formatPrice(n);
    return String(n);
}

async function runSearch(q) {
    const seq = ++searchSeq;
    loading.value = true;
    searched.value = true;
    try {
        const list = await props.fetchProducts((q || '').trim());
        if (seq !== searchSeq) return;
        products.value = Array.isArray(list) ? list : [];
    } catch (e) {
        if (seq !== searchSeq) return;
        products.value = [];
        console.error(e);
    } finally {
        if (seq === searchSeq) loading.value = false;
    }
}

function onEnter() {
    runSearch(localQuery.value);
}

function close() {
    emit('update:visible', false);
}

function onPick(row) {
    if (isTaken(row)) {
        emit('taken-click', row);
        return;
    }
    emit('select', {
        product: row.product,
        unit: row.unit,
        konversi: row.konversi,
        unitObj: row.unitObj,
        is_serial: row.is_serial,
        price: row.price
    });
    close();
}

watch(
    () => props.visible,
    (v) => {
        if (v) {
            localQuery.value = props.query || '';
            products.value = [];
            searched.value = false;
            if ((localQuery.value || '').trim()) {
                runSearch(localQuery.value);
            }
        }
    }
);
</script>

<template>
    <Drawer :visible="visible" position="right" class="product-unit-picker-drawer" :style="{ width: 'min(420px, 100vw)' }" @update:visible="emit('update:visible', $event)">
        <template #header>
            <span class="font-semibold text-lg">{{ title }}</span>
        </template>

        <div class="flex flex-col gap-3 h-full">
            <div v-if="modeHint" class="text-xs text-surface-500 leading-snug px-0.5">{{ modeHint }}</div>
            <div class="flex gap-2">
                <InputText v-model="localQuery" class="flex-1" placeholder="Cari kode/nama/barcode/KI/SN…" @keydown.enter.prevent="onEnter" />
                <Button icon="pi pi-search" :loading="loading" @click="onEnter" aria-label="Cari" />
            </div>

            <div v-if="loading" class="text-sm text-surface-500 py-6 text-center">Mencari…</div>
            <div v-else-if="searched && rows.length === 0" class="text-sm text-surface-500 py-6 text-center">Tidak ada produk.</div>
            <div v-else-if="!searched" class="text-sm text-surface-500 py-6 text-center">Ketik lalu Enter untuk mencari.</div>

            <div v-else class="flex flex-col gap-2 overflow-y-auto flex-1 pb-4">
                <button
                    v-for="row in rows"
                    :key="row.key + (row.is_serial ? '-s' : '')"
                    type="button"
                    class="text-left w-full p-3 rounded-lg border transition-colors"
                    :class="
                        isTaken(row) ? 'border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800 opacity-70 cursor-not-allowed' : 'border-surface-200 dark:border-surface-700 hover:border-primary hover:bg-primary/5 cursor-pointer'
                    "
                    :disabled="isTaken(row)"
                    @click="onPick(row)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-medium truncate">{{ row.product.nama_produk }}</div>
                            <div class="text-xs text-surface-500">{{ row.product.kode_produk }}</div>
                            <div class="flex flex-wrap items-center gap-2 mt-1.5 text-sm">
                                <Tag v-if="row.is_serial" value="Serial" severity="info" class="text-xs" />
                                <span class="font-semibold">{{ row.unit }}</span>
                                <span v-if="showKonversi" class="text-surface-500 text-xs">×{{ row.konversi }}</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <div v-if="showPrice" class="font-semibold text-primary text-sm">{{ displayPrice(row.price) }}</div>
                            <div v-if="isTaken(row)" class="text-xs text-orange-600 dark:text-orange-400 mt-1">{{ takenLabel }}</div>
                        </div>
                    </div>
                </button>
            </div>
        </div>
    </Drawer>
</template>

<style scoped>
.product-unit-picker-drawer :deep(.p-drawer-content) {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding-top: 0.5rem;
}
</style>

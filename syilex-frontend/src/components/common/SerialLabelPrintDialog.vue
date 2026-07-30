<script setup>
import { ref, computed, watch } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useSerialLabelPrint } from '@/composables/useSerialLabelPrint';

const props = defineProps({
    visible: { type: Boolean, default: false },
    // Unit mentah (dari Register / dokumen PBS). Untuk dokumen PBS, produk/PBS diambil dari `context`.
    units: { type: Array, default: () => [] },
    // Fallback header (dokumen PBS): { kode_produk, nama_produk, nomor_dokumen, tanggal }
    context: { type: Object, default: () => ({}) },
    title: { type: String, default: 'Cetak Label Unit Serial' }
});
const emit = defineEmits(['update:visible']);

const { formatCurrency, formatPercent, formatDate } = useFormatters();
const {
    generating,
    loadSettings,
    saveSettings,
    resetSettings,
    applySizePreset,
    resolveGrid,
    generateBarcodeDataURL,
    printSerialLabels,
    downloadSerialLabels,
    PAPER_PRESETS
} = useSerialLabelPrint();

const settings = ref(loadSettings());
const keterangan = ref('');

const sizeOptions = [
    { label: 'Kecil (40×30)', value: 'Kecil' },
    { label: 'Sedang (50×40)', value: 'Sedang' },
    { label: 'Besar (60×45)', value: 'Besar' },
    { label: 'Custom', value: 'Custom' }
];
const orientationOptions = [
    { label: 'Portrait', value: 'portrait' },
    { label: 'Landscape', value: 'landscape' }
];
const columnOptions = [
    { label: 'Otomatis', value: 'auto' },
    { label: '1 kolom', value: 1 },
    { label: '2 kolom', value: 2 },
    { label: '3 kolom', value: 3 },
    { label: '4 kolom', value: 4 },
    { label: '5 kolom', value: 5 },
    { label: '6 kolom', value: 6 }
];

// ── Label items (string SUDAH terformat) ──
const labelItems = computed(() =>
    (props.units || []).map((u) => {
        const ctx = props.context || {};
        const kode = u.product?.kode_produk ?? ctx.kode_produk ?? '';
        const nama = u.product?.nama_produk ?? ctx.nama_produk ?? '';
        const nomor = u.intake?.nomor_dokumen ?? ctx.nomor_dokumen ?? '';
        const tgl = u.intake?.tanggal ?? ctx.tanggal ?? null;
        const health = u.battery_health != null ? formatPercent(u.battery_health) : '';
        const cycle = u.battery_cycle_count != null ? `Cyc ${u.battery_cycle_count}` : '';
        const bat = [health, cycle, u.battery_condition].filter(Boolean).join(' ');
        return {
            kode_produk: kode,
            nama_produk: nama,
            kode_internal: u.kode_internal,
            serial_number: u.serial_number,
            spek: `Grade ${u.grade || '-'} · Bat ${bat || '-'}`,
            akun: `Akun ${u.account_status || '-'}`,
            catatan: u.catatan || '',
            harga: u.harga_jual != null ? formatCurrency(u.harga_jual) : '',
            pbs: [nomor, tgl ? formatDate(tgl) : ''].filter(Boolean).join(' · ')
        };
    })
);

const gridInfo = computed(() => resolveGrid(settings.value));
const perPage = computed(() => Math.max(1, gridInfo.value.perPage));
const totalPages = computed(() => (labelItems.value.length ? Math.ceil(labelItems.value.length / perPage.value) : 0));

// ── HTML preview (halaman 1) — mirror PrintBarcodePage, bukan iframe PDF ──
const previewLabels = ref([]);
const previewing = ref(false);
let previewTimer = null;

const previewGridStyle = computed(() => ({
    display: 'grid',
    gridTemplateColumns: `repeat(${gridInfo.value.cols}, ${settings.value.label.width}mm)`,
    gap: `${settings.value.grid.gapV}mm ${settings.value.grid.gapH}mm`,
    padding: `${settings.value.grid.margin}mm`
}));

const labelStyle = computed(() => ({
    width: `${settings.value.label.width}mm`,
    height: `${settings.value.label.height}mm`,
    fontSize: `${settings.value.fontBase}pt`
}));

function rebuildPreview() {
    if (!props.visible || labelItems.value.length === 0) {
        previewLabels.value = [];
        return;
    }
    previewing.value = true;
    try {
        const first = labelItems.value.slice(0, perPage.value);
        const cache = {};
        previewLabels.value = first.map((item) => {
            const key = item.kode_internal || '';
            if (key && !cache[key]) cache[key] = generateBarcodeDataURL(key);
            return { ...item, barcodeImg: key ? cache[key] : '' };
        });
    } finally {
        previewing.value = false;
    }
}

function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(rebuildPreview, 400);
}

watch(
    () => props.visible,
    (v) => {
        if (v) rebuildPreview();
        else previewLabels.value = [];
    }
);
watch([settings, keterangan, labelItems], schedulePreview, { deep: true });

function onSizeChange() {
    if (settings.value.sizePreset !== 'Custom') {
        applySizePreset(settings.value, settings.value.sizePreset);
    }
    schedulePreview();
}

function onPaperPresetChange() {
    const dims = PAPER_PRESETS[settings.value.paper.preset];
    if (dims) {
        settings.value.paper.width = dims.width;
        settings.value.paper.height = dims.height;
    } else {
        if (!Number(settings.value.paper.width)) settings.value.paper.width = 210;
        if (!Number(settings.value.paper.height)) settings.value.paper.height = 297;
    }
    schedulePreview();
}

function onOrientationChange() {
    const w = Number(settings.value.paper.width) || 0;
    settings.value.paper.width = Number(settings.value.paper.height) || 0;
    settings.value.paper.height = w;
    schedulePreview();
}

function onReset() {
    settings.value = resetSettings();
    schedulePreview();
}

async function onPrint() {
    saveSettings(settings.value);
    await printSerialLabels(labelItems.value, settings.value, { keterangan: keterangan.value });
}

async function onDownload() {
    saveSettings(settings.value);
    const fname = props.context?.nomor_dokumen ? `label-${props.context.nomor_dokumen}` : 'label-unit-serial';
    await downloadSerialLabels(labelItems.value, settings.value, { keterangan: keterangan.value }, fname);
}

function close() {
    emit('update:visible', false);
}
</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal :header="title" :style="{ width: '860px' }" :breakpoints="{ '960px': '95vw' }">
        <!-- preview dulu di mobile (flex-col-reverse); lg: side-by-side -->
        <div class="flex flex-col-reverse gap-4 lg:grid lg:grid-cols-2">
            <!-- Kontrol -->
            <div class="flex flex-col gap-3">
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs text-surface-500 mb-1">Ukuran Label (preset)</label>
                        <Select v-model="settings.sizePreset" :options="sizeOptions" optionLabel="label" optionValue="value" class="w-full" @change="onSizeChange" />
                    </div>
                    <div>
                        <label class="block text-xs text-surface-500 mb-1">Jumlah Kolom</label>
                        <Select v-model="settings.columns" :options="columnOptions" optionLabel="label" optionValue="value" class="w-full" @change="schedulePreview" />
                    </div>
                </div>

                <fieldset class="border border-surface-200 dark:border-surface-700 rounded-lg p-3">
                    <legend class="text-xs font-semibold px-2">Ukuran Label (mm)</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Lebar</label>
                            <InputNumber v-model="settings.label.width" :min="5" :max="200" fluid class="w-full" />
                        </div>
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Tinggi</label>
                            <InputNumber v-model="settings.label.height" :min="5" :max="200" fluid class="w-full" />
                        </div>
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Font (pt)</label>
                            <InputNumber v-model="settings.fontBase" :min="4" :max="12" fluid class="w-full" />
                        </div>
                    </div>
                </fieldset>

                <fieldset class="border border-surface-200 dark:border-surface-700 rounded-lg p-3">
                    <legend class="text-xs font-semibold px-2">Jarak (mm)</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Gap Horizontal</label>
                            <InputNumber v-model="settings.grid.gapH" :min="0" :max="20" fluid class="w-full" @input="schedulePreview" />
                        </div>
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Gap Vertikal</label>
                            <InputNumber v-model="settings.grid.gapV" :min="0" :max="20" fluid class="w-full" @input="schedulePreview" />
                        </div>
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Margin</label>
                            <InputNumber v-model="settings.grid.margin" :min="0" :max="30" fluid class="w-full" @input="schedulePreview" />
                        </div>
                    </div>
                </fieldset>

                <fieldset class="border border-surface-200 dark:border-surface-700 rounded-lg p-3">
                    <legend class="text-xs font-semibold px-2">Kertas</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Preset</label>
                            <Select v-model="settings.paper.preset" :options="['A4', 'A5', 'Custom']" class="w-full" @change="onPaperPresetChange" />
                        </div>
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Orientasi</label>
                            <Select v-model="settings.paper.orientation" :options="orientationOptions" optionLabel="label" optionValue="value" class="w-full" @change="onOrientationChange" />
                        </div>
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Lebar (mm)</label>
                            <InputNumber v-model="settings.paper.width" :min="10" :max="500" fluid class="w-full" @input="schedulePreview" />
                        </div>
                        <div>
                            <label class="block text-xs text-surface-500 mb-1">Tinggi (mm)</label>
                            <InputNumber v-model="settings.paper.height" :min="10" :max="500" fluid class="w-full" @input="schedulePreview" />
                        </div>
                    </div>
                </fieldset>

                <div>
                    <label class="block text-xs text-surface-500 mb-1">Keterangan batch (opsional)</label>
                    <InputText v-model="keterangan" class="w-full" placeholder="Contoh: Garansi 1 bln — berlaku semua label" />
                    <p class="text-[11px] text-surface-400 mt-1">Catatan per unit dari data serial ikut tercetak otomatis. Field ini tambahan manual untuk seluruh cetakan.</p>
                </div>

                <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3 text-sm">
                    <div>
                        <i class="pi pi-tag mr-1 text-primary"></i> <strong>{{ labelItems.length }}</strong> label
                    </div>
                    <div class="text-surface-500 mt-1">Grid {{ gridInfo.cols }} × {{ gridInfo.rows }} = {{ perPage }}/halaman · {{ totalPages }} halaman</div>
                </div>

                <Button label="Reset Pengaturan" icon="pi pi-refresh" severity="secondary" text size="small" class="self-start" @click="onReset" />
            </div>

            <!-- Preview HTML -->
            <div>
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm font-medium">Preview (halaman 1)</span>
                    <ProgressSpinner v-if="previewing" style="width: 18px; height: 18px" strokeWidth="6" />
                </div>
                <div class="border border-surface-200 dark:border-surface-700 rounded-lg overflow-auto bg-white dark:bg-surface-900" style="max-height: 360px; min-height: 160px">
                    <div v-if="previewLabels.length" :style="previewGridStyle">
                        <div
                            v-for="(label, idx) in previewLabels"
                            :key="idx"
                            :style="labelStyle"
                            class="border border-surface-300 dark:border-surface-600 rounded-sm flex flex-col items-stretch justify-between overflow-hidden p-1"
                        >
                            <div class="truncate font-bold leading-tight">{{ label.kode_produk }} · {{ label.nama_produk }}</div>
                            <img v-if="label.barcodeImg" :src="label.barcodeImg" alt="barcode" class="max-w-full flex-1 object-contain my-0.5" style="min-height: 0" />
                            <div class="truncate text-[0.85em] leading-tight">{{ label.kode_internal }}</div>
                            <div class="truncate text-[0.8em] text-surface-600 leading-tight">SN {{ label.serial_number }}</div>
                            <div class="truncate text-[0.75em] text-surface-500 leading-tight">{{ label.spek }}</div>
                            <div v-if="label.harga" class="font-semibold text-[0.85em] leading-tight">{{ label.harga }}</div>
                            <div v-if="keterangan" class="truncate italic text-[0.7em] text-surface-400 leading-tight">{{ keterangan }}</div>
                        </div>
                    </div>
                    <div v-else class="h-40 flex items-center justify-center text-surface-400 text-sm">
                        {{ labelItems.length ? 'Memuat preview…' : 'Tidak ada unit untuk dicetak' }}
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button label="Tutup" severity="secondary" outlined @click="close" />
            <Button label="Download PDF" icon="pi pi-download" severity="secondary" :loading="generating" :disabled="!labelItems.length" @click="onDownload" />
            <Button label="Print" icon="pi pi-print" :loading="generating" :disabled="!labelItems.length" @click="onPrint" />
        </template>
    </Dialog>
</template>

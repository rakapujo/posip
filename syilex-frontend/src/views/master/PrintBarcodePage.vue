<script setup>
import { ref, computed, watch } from 'vue';
import { produksApi } from '@/api';
import { useNotification } from '@/composables/useNotification';
import { useFormatters } from '@/composables/useFormatters';
import { useBarcodePrint } from '@/composables/useBarcodePrint';

const { formatCurrency } = useFormatters();
const notify = useNotification();
const { generating, loadSettings, saveSettings, resetSettings, generateBarcodeDataURL, calcGrid, buildBarcodePdf, PAPER_PRESETS } = useBarcodePrint();

// ── Product Search ──
const searchQuery = ref('');
const products = ref([]);
const loadingProducts = ref(false);
const selectedProducts = ref([]);

const searchProducts = async () => {
    if (!searchQuery.value?.trim()) return;
    loadingProducts.value = true;
    try {
        const res = await produksApi.getAll({
            search: searchQuery.value,
            status: 'active',
            is_serial: 0, // produk serial dicetak via Register Unit Serial → Cetak Label
            per_page: 20
        });
        products.value = res.data?.data?.produks || [];
    } catch {
        notify.loadListError('produk');
    } finally {
        loadingProducts.value = false;
    }
};

const clearSearch = () => {
    searchQuery.value = '';
    products.value = [];
    selectedProducts.value = [];
};

// ── Unit Picker Dialog ──
const unitDialog = ref(false);
const unitDialogForm = ref([]);

const openUnitPicker = () => {
    if (selectedProducts.value.length === 0) {
        notify.selectFirst('produk');
        return;
    }

    unitDialogForm.value = selectedProducts.value.map((p) => {
        const units = getProductUnits(p);
        return {
            product: p,
            units,
            selectedUnit: units[0]?.value || null,
            qty: 1,
            keterangan: ''
        };
    });
    unitDialog.value = true;
};

const getProductUnits = (product) => {
    const units = [];
    const seen = new Set();
    for (let i = 1; i <= 4; i++) {
        const satuan = product[`unit_${i}`];
        const konversi = i === 4 ? 1 : product[`konversi_${i}`];
        const harga = product[`harga_${i}`];
        if (!satuan) continue;
        const key = `${satuan}-${konversi}-${harga}`;
        if (seen.has(key)) continue;
        seen.add(key);
        units.push({
            value: i,
            label: `${satuan} (${konversi}) - ${formatCurrency(harga)}`,
            satuan,
            konversi,
            harga
        });
    }
    return units;
};

const addToPrintList = () => {
    let added = 0;
    for (const entry of unitDialogForm.value) {
        if (!entry.selectedUnit || entry.qty < 1) continue;
        const unit = entry.units.find((u) => u.value === entry.selectedUnit);
        if (!unit) continue;

        const existing = printList.value.find((item) => item.produkUlid === entry.product.ulid && item.unitIndex === entry.selectedUnit);

        if (existing) {
            existing.qty += entry.qty;
        } else {
            printList.value.push({
                produkUlid: entry.product.ulid,
                kode_produk: entry.product.kode_produk,
                nama_produk: entry.product.nama_produk,
                barcode: entry.product.barcode,
                satuan: unit.satuan,
                konversi: unit.konversi,
                harga: formatCurrency(unit.harga),
                unitIndex: entry.selectedUnit,
                qty: entry.qty,
                keterangan: entry.keterangan || ''
            });
        }
        added++;
    }

    unitDialog.value = false;
    selectedProducts.value = [];
    if (added > 0) {
        notify.success(`${added} produk ditambahkan ke daftar cetak`);
    }
};

// ── Print List ──
const printList = ref([]);

const totalLabels = computed(() => printList.value.reduce((sum, item) => sum + item.qty, 0));

const removeFromList = (index) => {
    printList.value.splice(index, 1);
};

const clearPrintList = () => {
    printList.value = [];
    previewLabels.value = [];
};

// ── Settings ──
const settings = ref(loadSettings());
const settingsDialog = ref(false);

const presetOptions = [
    { label: 'A4', value: 'A4' },
    { label: 'A5', value: 'A5' },
    { label: 'Custom', value: 'Custom' }
];

const orientationOptions = [
    { label: 'Portrait', value: 'portrait' },
    { label: 'Landscape', value: 'landscape' }
];

const onPresetChange = (preset) => {
    const dims = PAPER_PRESETS[preset];
    if (dims) {
        settings.value.paper.width = dims.width;
        settings.value.paper.height = dims.height;
    }
};

// Toggle orientasi = tukar Lebar <-> Tinggi (halaman pakai dimensi apa adanya)
const onOrientationChange = () => {
    const w = Number(settings.value.paper.width) || 0;
    settings.value.paper.width = Number(settings.value.paper.height) || 0;
    settings.value.paper.height = w;
};

const onSettingsSave = () => {
    saveSettings(settings.value);
    settingsDialog.value = false;
    previewPage.value = 1;
    generatePreview();
};

const onSettingsReset = () => {
    settings.value = resetSettings();
};

// ── Auto-calculated grid ──
const gridInfo = computed(() => {
    const s = settings.value;
    return calcGrid(Number(s.paper.width) || 210, Number(s.paper.height) || 297, Number(s.label.width) || 48, Number(s.label.height) || 30, Number(s.grid.gapH) ?? 2, Number(s.grid.gapV) ?? 0, Number(s.grid.margin) || 0);
});

const labelsPerPage = computed(() => gridInfo.value.cols * gridInfo.value.rows);

// ── Preview ──
const previewLabels = ref([]);
const previewPage = ref(1);
const loadingPreview = ref(false);

const totalPages = computed(() => {
    if (totalLabels.value === 0) return 0;
    return Math.ceil(totalLabels.value / labelsPerPage.value);
});

const currentPageLabels = computed(() => {
    const start = (previewPage.value - 1) * labelsPerPage.value;
    const end = start + labelsPerPage.value;
    return previewLabels.value.slice(start, end);
});

const previewGridStyle = computed(() => ({
    display: 'grid',
    gridTemplateColumns: `repeat(${gridInfo.value.cols}, ${settings.value.label.width}mm)`,
    gap: `${settings.value.grid.gapV}mm ${settings.value.grid.gapH}mm`,
    padding: `${settings.value.grid.margin}mm`
}));

const labelStyle = computed(() => ({
    width: `${settings.value.label.width}mm`,
    height: `${settings.value.label.height}mm`
}));

const generatePreview = () => {
    if (printList.value.length === 0) {
        previewLabels.value = [];
        return;
    }

    loadingPreview.value = true;
    try {
        const barcodeCache = {};
        for (const item of printList.value) {
            const val = item.barcode || item.kode_produk;
            if (!barcodeCache[val]) {
                barcodeCache[val] = generateBarcodeDataURL(val);
            }
        }

        const expanded = [];
        for (const item of printList.value) {
            const img = barcodeCache[item.barcode || item.kode_produk];
            for (let i = 0; i < item.qty; i++) {
                expanded.push({ ...item, barcodeImg: img });
            }
        }

        previewLabels.value = expanded;
    } catch (err) {
        console.error('Preview generation error:', err);
        notify.error('Gagal generate preview barcode');
    } finally {
        loadingPreview.value = false;
    }
};

let previewTimer = null;
watch(
    printList,
    () => {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(() => {
            previewPage.value = 1;
            generatePreview();
        }, 500);
    },
    { deep: true }
);

// ── Print / Download ──
const onPrint = async () => {
    if (printList.value.length === 0) {
        notify.selectFirst('produk untuk dicetak');
        return;
    }

    generating.value = true;
    try {
        const doc = await buildBarcodePdf(printList.value, settings.value);
        const blob = doc.output('blob');
        const url = URL.createObjectURL(blob);
        const win = window.open(url, '_blank');
        if (win) {
            win.addEventListener('load', () => win.print());
        }
    } catch (err) {
        console.error('Print error:', err);
        notify.error('Gagal generate PDF barcode');
    } finally {
        generating.value = false;
    }
};

const onDownload = async () => {
    if (printList.value.length === 0) {
        notify.selectFirst('produk untuk dicetak');
        return;
    }

    generating.value = true;
    try {
        const doc = await buildBarcodePdf(printList.value, settings.value);
        doc.save('barcode-labels.pdf');
    } catch (err) {
        console.error('Download error:', err);
        notify.error('Gagal generate PDF barcode');
    } finally {
        generating.value = false;
    }
};
</script>

<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-4">
            <template #start>
                <h4 class="m-0 font-semibold">Print Barcode</h4>
            </template>
            <template #end>
                <Button label="Pengaturan Cetak" icon="pi pi-cog" severity="secondary" outlined @click="settingsDialog = true" />
            </template>
        </Toolbar>

        <!-- Main Content: 2 columns -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Left Panel: Pilih Produk -->
            <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                <h5 class="mt-0 mb-3 font-semibold text-lg">Pilih Produk</h5>

                <div class="flex gap-2 mb-3">
                    <IconField class="flex-1">
                        <InputIcon class="pi pi-search" />
                        <InputText v-model="searchQuery" placeholder="Cari kode, barcode, nama produk..." class="w-full" autocomplete="off" @keyup.enter="searchProducts" />
                        <InputIcon v-if="searchQuery" class="pi pi-times cursor-pointer hover:!text-surface-600" @click="clearSearch" />
                    </IconField>
                    <Button icon="pi pi-search" @click="searchProducts" :loading="loadingProducts" />
                </div>
                <p class="text-xs text-surface-400 mb-3 -mt-1"><i class="pi pi-info-circle mr-1"></i>Produk serial tidak ditampilkan — cetak labelnya via <span class="font-medium">Register Unit Serial → Cetak Label</span>.</p>

                <DataTable v-model:selection="selectedProducts" :value="products" :loading="loadingProducts" dataKey="ulid" scrollable scrollHeight="350px" size="small" :paginator="false">
                    <Column selectionMode="multiple" headerStyle="width: 3rem" />
                    <Column field="kode_produk" header="Kode" style="min-width: 100px">
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.kode_produk }}</span>
                        </template>
                    </Column>
                    <Column field="nama_produk" header="Nama Produk" style="min-width: 180px" />
                    <Column field="barcode" header="Barcode" style="min-width: 120px">
                        <template #body="{ data }">
                            {{ data.barcode || '-' }}
                        </template>
                    </Column>
                    <template #empty>
                        <div class="text-center text-surface-400 py-4">Cari produk untuk menampilkan hasil</div>
                    </template>
                </DataTable>

                <div class="mt-3">
                    <Button label="Tambah ke Daftar" icon="pi pi-arrow-right" iconPos="right" :disabled="selectedProducts.length === 0" @click="openUnitPicker" />
                </div>
            </div>

            <!-- Right Panel: Daftar Cetak -->
            <div class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                    <h5 class="m-0 font-semibold text-lg">Daftar Cetak</h5>
                    <Button label="Clear" icon="pi pi-trash" severity="danger" text size="small" :disabled="printList.length === 0" @click="clearPrintList" />
                </div>

                <DataTable :value="printList" scrollable scrollHeight="300px" size="small" :paginator="false">
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>
                    <Column field="kode_produk" header="Produk" style="min-width: 100px">
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.kode_produk }}</span>
                        </template>
                    </Column>
                    <Column header="Unit" style="min-width: 100px">
                        <template #body="{ data }"> {{ data.satuan }} ({{ data.konversi }}) </template>
                    </Column>
                    <Column field="harga" header="Harga" style="min-width: 100px" />
                    <Column header="Keterangan" style="min-width: 160px">
                        <template #body="{ data }">
                            <InputText v-model="data.keterangan" class="w-full" size="small" placeholder="Exp, Batch..." />
                        </template>
                    </Column>
                    <Column header="Qty" style="width: 100px">
                        <template #body="{ data }">
                            <InputNumber v-select-on-focus v-model="data.qty" :min="1" :max="999" inputClass="w-16 text-center" size="small" />
                        </template>
                    </Column>
                    <Column style="width: 50px">
                        <template #body="{ index }">
                            <Button icon="pi pi-times" severity="danger" text size="small" rounded @click="removeFromList(index)" />
                        </template>
                    </Column>
                    <template #empty>
                        <div class="text-center text-surface-400 py-4">Belum ada produk di daftar cetak</div>
                    </template>
                </DataTable>

                <!-- Summary & Actions -->
                <div class="mt-3 flex items-center justify-between">
                    <div class="text-sm text-surface-500">
                        Total: <strong>{{ totalLabels }}</strong> label
                        <span class="ml-2 text-surface-400">({{ gridInfo.cols }} kolom x {{ gridInfo.rows }} baris = {{ labelsPerPage }} label/hal)</span>
                    </div>
                    <div class="flex gap-2">
                        <Button label="Download PDF" icon="pi pi-download" severity="secondary" :loading="generating" :disabled="printList.length === 0" @click="onDownload" />
                        <Button label="Print Barcode" icon="pi pi-print" :loading="generating" :disabled="printList.length === 0" @click="onPrint" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview Section -->
        <div v-if="previewLabels.length > 0" class="mt-4 border border-surface-200 dark:border-surface-700 rounded-lg p-4">
            <div class="flex items-center justify-between mb-3">
                <h5 class="m-0 font-semibold text-lg">Preview</h5>
                <div v-if="totalPages > 1" class="flex items-center gap-2">
                    <Button icon="pi pi-chevron-left" text size="small" :disabled="previewPage <= 1" @click="previewPage--" />
                    <span class="text-sm">Halaman {{ previewPage }} / {{ totalPages }}</span>
                    <Button icon="pi pi-chevron-right" text size="small" :disabled="previewPage >= totalPages" @click="previewPage++" />
                </div>
            </div>

            <ProgressBar v-if="loadingPreview" mode="indeterminate" style="height: 4px" class="mb-3" />

            <div class="overflow-x-auto bg-white dark:bg-surface-900 border border-surface-100 dark:border-surface-800 rounded-md">
                <div :style="previewGridStyle">
                    <div v-for="(label, idx) in currentPageLabels" :key="idx" :style="labelStyle" class="border border-surface-300 dark:border-surface-600 rounded-sm flex flex-col items-center justify-between overflow-hidden" style="padding: 1.5mm">
                        <div class="text-center truncate w-full">
                            <div class="font-bold" :style="{ fontSize: `${Math.max(settings.font.codeSize - 2, 5)}pt` }">{{ label.kode_produk }}</div>
                            <div class="truncate" :style="{ fontSize: `${Math.max(settings.font.codeSize - 2, 5)}pt` }">{{ label.nama_produk }}</div>
                            <div v-if="label.keterangan" class="truncate italic text-surface-500" :style="{ fontSize: `${Math.max(settings.font.codeSize - 3, 4)}pt` }">{{ label.keterangan }}</div>
                        </div>
                        <img v-if="label.barcodeImg" :src="label.barcodeImg" alt="barcode" class="max-w-full flex-1 object-contain" style="min-height: 0" />
                        <div class="flex justify-between w-full" :style="{ fontSize: `${settings.font.priceSize}pt` }">
                            <span>{{ label.satuan }} ({{ label.konversi }})</span>
                            <span class="font-bold">{{ label.harga }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Unit Picker Dialog -->
    <Dialog v-model:visible="unitDialog" header="Pilih Unit & Jumlah" modal :style="{ width: '500px' }">
        <div class="flex flex-col gap-4">
            <div v-for="(entry, idx) in unitDialogForm" :key="idx" class="border border-surface-200 dark:border-surface-700 rounded-lg p-3">
                <div class="font-medium mb-2">{{ entry.product.kode_produk }} - {{ entry.product.nama_produk }}</div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Unit</label>
                        <Select v-model="entry.selectedUnit" :options="entry.units" optionLabel="label" optionValue="value" placeholder="Pilih unit" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Jumlah Label</label>
                        <InputNumber v-select-on-focus v-model="entry.qty" :min="1" :max="999" class="w-full" />
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm text-surface-500 mb-1">Keterangan (opsional)</label>
                        <InputText v-model="entry.keterangan" class="w-full" placeholder="Contoh: Exp 2027-01, Batch A1" />
                    </div>
                </div>
            </div>
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" text @click="unitDialog = false" />
            <Button label="Tambah" icon="pi pi-plus" @click="addToPrintList" />
        </template>
    </Dialog>

    <!-- Settings Dialog -->
    <Dialog v-model:visible="settingsDialog" header="Pengaturan Cetak" modal :style="{ width: '450px' }">
        <div class="flex flex-col gap-4">
            <!-- Ukuran Kertas -->
            <fieldset class="border border-surface-200 dark:border-surface-700 rounded-lg p-3">
                <legend class="text-sm font-semibold px-2">Ukuran Kertas</legend>
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="block text-sm text-surface-500 mb-1">Preset</label>
                        <Select v-model="settings.paper.preset" :options="presetOptions" optionLabel="label" optionValue="value" class="w-full" @change="onPresetChange(settings.paper.preset)" />
                    </div>
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Lebar (mm)</label>
                        <InputNumber v-model="settings.paper.width" :min="10" :max="500" suffix=" mm" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Tinggi (mm)</label>
                        <InputNumber v-model="settings.paper.height" :min="10" :max="500" suffix=" mm" class="w-full" />
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm text-surface-500 mb-1">Orientasi</label>
                        <SelectButton v-model="settings.paper.orientation" :options="orientationOptions" optionLabel="label" optionValue="value" @change="onOrientationChange" />
                    </div>
                </div>
            </fieldset>

            <!-- Ukuran Label -->
            <fieldset class="border border-surface-200 dark:border-surface-700 rounded-lg p-3">
                <legend class="text-sm font-semibold px-2">Ukuran Label</legend>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Lebar (mm)</label>
                        <InputNumber v-model="settings.label.width" :min="5" :max="200" suffix=" mm" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Tinggi (mm)</label>
                        <InputNumber v-model="settings.label.height" :min="5" :max="200" suffix=" mm" class="w-full" />
                    </div>
                </div>
            </fieldset>

            <!-- Jarak -->
            <fieldset class="border border-surface-200 dark:border-surface-700 rounded-lg p-3">
                <legend class="text-sm font-semibold px-2">Jarak</legend>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Gap Horizontal (mm)</label>
                        <InputNumber v-model="settings.grid.gapH" :min="0" :max="20" suffix=" mm" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Gap Vertikal (mm)</label>
                        <InputNumber v-model="settings.grid.gapV" :min="0" :max="20" suffix=" mm" class="w-full" />
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm text-surface-500 mb-1">Margin (mm)</label>
                        <InputNumber v-model="settings.grid.margin" :min="0" :max="30" suffix=" mm" class="w-full" />
                    </div>
                </div>
            </fieldset>

            <!-- Font -->
            <fieldset class="border border-surface-200 dark:border-surface-700 rounded-lg p-3">
                <legend class="text-sm font-semibold px-2">Font</legend>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Ukuran Kode (pt)</label>
                        <InputNumber v-model="settings.font.codeSize" :min="4" :max="16" suffix=" pt" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-sm text-surface-500 mb-1">Ukuran Harga (pt)</label>
                        <InputNumber v-model="settings.font.priceSize" :min="4" :max="16" suffix=" pt" class="w-full" />
                    </div>
                </div>
            </fieldset>

            <!-- Auto-calculated info -->
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3 text-sm">
                <i class="pi pi-info-circle mr-1 text-blue-500"></i>
                Otomatis: <strong>{{ gridInfo.cols }}</strong> kolom x <strong>{{ gridInfo.rows }}</strong> baris = <strong>{{ labelsPerPage }}</strong> label/halaman
            </div>
        </div>

        <template #footer>
            <Button label="Reset Default" severity="secondary" text @click="onSettingsReset" />
            <Button label="Simpan" icon="pi pi-check" @click="onSettingsSave" />
        </template>
    </Dialog>
</template>

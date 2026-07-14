<script setup>
import { produksApi, brandsApi, tipesApi, kategorisApi, grupsApi } from '@/api';
import { useRouter } from 'vue-router';
import { onMounted, ref, computed, watch } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useNotification } from '@/composables/useNotification';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';

// Formatters from global settings
const { formatCurrency, formatCurrencyShort, formatQty, getLocale, getQtyMinFractionDigits, getQtyMaxFractionDigits, shouldUppercase, currencySettings, todayString } = useFormatters();

const notify = useNotification();
const confirm = useConfirm();
const router = useRouter();
const settingsStore = useSettingsStore();
// Modul Elektronik (serial) on/off — sembunyikan toggle "Produk Serial" saat nonaktif
const serialEnabled = computed(() => settingsStore.serialEnabled);
const authStore = useAuthStore();
const { exporting, exportListPdf, exportDocumentPdf } = useExportPdf();

// Permissions
const canCreate = computed(() => authStore.can('produk.create'));
const canUpdate = computed(() => authStore.can('produk.update'));
const canDelete = computed(() => authStore.can('produk.delete'));
const canExport = computed(() => authStore.can('laporan.export'));
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));

// Unit/Price detail table columns
const unitPriceColumns = [
    { field: 'unit_number', header: 'Unit', width: '60px' },
    { field: 'satuan', header: 'Satuan' },
    { field: 'konversi', header: 'Konversi', align: 'right', width: '100px' },
    { field: 'harga', header: 'Harga', align: 'right', width: '120px' }
];

// Stock per warehouse columns
const stockPerWarehouseColumns = [
    { field: '#', header: '#', width: '40px' },
    { field: 'warehouse', header: 'Gudang' },
    { field: 'qty', header: 'Stok', align: 'right', width: '140px' },
    { field: 'status', header: 'Status', width: '100px' },
    { field: 'action', header: '', width: '50px' }
];

// Computed unit/price data for DetailTable
const unitPriceData = computed(() => {
    if (!detailData.value) return [];
    return [1, 2, 3, 4].map((n) => ({
        unit_number: n,
        satuan: detailData.value[`unit_${n}`] || '-',
        konversi: detailData.value[`konversi_${n}`],
        harga: detailData.value[`harga_${n}`]
    }));
});

// Computed stock per warehouse data for DetailTable
const stockPerWarehouseData = computed(() => {
    if (!detailData.value?.warehouse_stocks) return [];
    return detailData.value.warehouse_stocks
        .filter((stock) => stock.warehouse) // Only include stocks with warehouse
        .map((stock) => ({
            warehouse_id: stock.warehouse.id,
            warehouse_name: stock.warehouse.nama_warehouse || '-',
            warehouse_code: stock.warehouse.kode_warehouse || '-',
            warehouse_status: stock.warehouse.status || 'active',
            qty: stock.qty ?? 0
        }));
});

// Data
const produks = ref([]);
const totalRecords = ref(0);
const loading = ref(false);
const exportingExcel = ref(false);
const exportingDetail = ref(false);

// Price input mode from settings
const priceInputMode = ref('auto');

// Options for dropdowns
const brandOptions = ref([]);
const tipeOptions = ref([]);
const kategoriOptions = ref([]); // For filter
const kategoriFormOptions = ref([]); // For form (filtered by tipe)
const grupFormOptions = ref([]); // For form (filtered by kategori)

// Dialog states
const produkDialog = ref(false);
const detailDialog = ref(false);
const submitted = ref(false);
const saving = ref(false);
const loadingDetail = ref(false);

// Flag to skip watchers during edit load
const isLoadingEdit = ref(false);

// Form data
const produk = ref({});
const detailData = ref({});

// Image handling
const imageInput = ref(null);
const imagePreview = ref(null);

// Computed
const isEdit = computed(() => !!produk.value.ulid);
const dialogTitle = computed(() => (isEdit.value ? 'Edit Produk' : 'Tambah Produk'));

// Lazy loading params
const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'created_at',
    sortOrder: -1
});

// Filters
const searchQuery = ref('');
const selectedBrand = ref(null);
const selectedTipe = ref(null);
const selectedKategori = ref(null);
const selectedStatus = ref(null);

const statusOptions = [
    { label: 'Aktif', value: 'active' },
    { label: 'Nonaktif', value: 'inactive' }
];

// Status helpers
function getStatusLabel(status) {
    return status === 'active' ? 'Aktif' : 'Nonaktif';
}

function getStatusSeverity(status) {
    return status === 'active' ? 'success' : 'danger';
}

function getToggleLabel(status) {
    return status === 'active' ? 'Nonaktifkan' : 'Aktifkan';
}

function getToggleSeverity(status) {
    return status === 'active' ? 'danger' : 'success';
}

// Format currency - now uses global settings via useFormatters composable

// ============================================
// REAL-TIME VALIDATION - COMPREHENSIVE & STRICT
// ============================================

// Validation errors computed
const validationErrors = computed(() => {
    const errors = {
        kode_produk: null,
        nama_produk: null,
        unit_1: null,
        unit_2: null,
        unit_3: null,
        unit_4: null,
        konversi_1: null,
        konversi_2: null,
        konversi_3: null,
        harga_1: null,
        harga_2: null,
        harga_3: null,
        harga_4: null,
        general: null // For general/combined errors
    };

    // Produk serial (modul A+): satuan/harga/min-stok diisi otomatis → validasi minimal (kode + nama).
    if (produk.value.is_serial) {
        if (submitted.value) {
            if (!produk.value.kode_produk?.trim()) errors.kode_produk = 'Kode produk wajib diisi';
            if (!produk.value.nama_produk?.trim()) errors.nama_produk = 'Nama produk wajib diisi';
        }
        return errors;
    }

    // Get values (use Number() to ensure numeric comparison)
    const k1 = Number(produk.value.konversi_1) || 0;
    const k2 = Number(produk.value.konversi_2) || 0;
    const k3 = Number(produk.value.konversi_3) || 0;

    const h1 = Number(produk.value.harga_1) || 0;
    const h2 = Number(produk.value.harga_2) || 0;
    const h3 = Number(produk.value.harga_3) || 0;
    const h4 = Number(produk.value.harga_4) || 0;

    const u1 = (produk.value.unit_1 || '').toUpperCase().trim();
    const u2 = (produk.value.unit_2 || '').toUpperCase().trim();
    const u3 = (produk.value.unit_3 || '').toUpperCase().trim();
    const u4 = (produk.value.unit_4 || '').toUpperCase().trim();

    // Determine lock point (where konversi = 1)
    let lockFrom = null;
    if (k1 === 1) lockFrom = 1;
    else if (k2 === 1) lockFrom = 2;
    else if (k3 === 1) lockFrom = 3;

    const generalErrors = [];

    // ========================================
    // A. REQUIRED FIELD VALIDATION (after submit)
    // ========================================
    if (submitted.value) {
        if (!produk.value.kode_produk?.trim()) {
            errors.kode_produk = 'Kode produk wajib diisi';
        }
        if (!produk.value.nama_produk?.trim()) {
            errors.nama_produk = 'Nama produk wajib diisi';
        }
        if (!u1) errors.unit_1 = 'Satuan wajib diisi';
        if (!u2) errors.unit_2 = 'Satuan wajib diisi';
        if (!u3) errors.unit_3 = 'Satuan wajib diisi';
        if (!u4) errors.unit_4 = 'Satuan wajib diisi';
        if (k1 < 1) errors.konversi_1 = 'Konversi minimal 1';
        if (k2 < 1) errors.konversi_2 = 'Konversi minimal 1';
        if (k3 < 1) errors.konversi_3 = 'Konversi minimal 1';
        // Harga wajib > 0
        if (h1 <= 0) errors.harga_1 = 'Harga harus lebih dari 0';
        if (h2 <= 0) errors.harga_2 = 'Harga harus lebih dari 0';
        if (h3 <= 0) errors.harga_3 = 'Harga harus lebih dari 0';
        if (h4 <= 0) errors.harga_4 = 'Harga harus lebih dari 0';
    }

    // ========================================
    // B. KONVERSI ORDER VALIDATION (real-time)
    // Rule: k1 > k2 > k3 >= 1, strictly decreasing UNLESS = 1
    // ========================================
    if (k1 > 0 && k2 > 0) {
        if (k1 < k2) {
            errors.konversi_1 = errors.konversi_1 || 'Harus > Konversi 2';
        } else if (k1 === k2 && k1 > 1) {
            errors.konversi_2 = errors.konversi_2 || 'Tidak boleh sama dengan Konversi 1 (kecuali = 1)';
        }
    }

    if (k2 > 0 && k3 > 0) {
        if (k2 < k3) {
            errors.konversi_2 = errors.konversi_2 || 'Harus > Konversi 3';
        } else if (k2 === k3 && k2 > 1) {
            errors.konversi_3 = errors.konversi_3 || 'Tidak boleh sama dengan Konversi 2 (kecuali = 1)';
        }
    }

    // ========================================
    // C. AUTO-LOCK STRICT VALIDATION (real-time)
    // If konversi_n = 1, all below must be = 1
    // ========================================
    if (lockFrom === 1) {
        // k1 = 1, so k2, k3, k4 MUST be 1
        if (k2 !== 1) errors.konversi_2 = 'Harus = 1 karena Konversi 1 = 1';
        if (k3 !== 1) errors.konversi_3 = 'Harus = 1 karena Konversi 1 = 1';
    } else if (lockFrom === 2) {
        // k2 = 1, so k3, k4 MUST be 1
        if (k3 !== 1) errors.konversi_3 = 'Harus = 1 karena Konversi 2 = 1';
    }
    // k3 = 1, k4 is always 1, no need to check

    // ========================================
    // D. UNIT NAME VALIDATION (real-time)
    // ========================================

    // D0. Unit name format validation (alphanumeric only, no spaces/special chars)
    const unitRegex = /^[A-Za-z0-9]+$/;
    if (u1 && !unitRegex.test(u1)) {
        errors.unit_1 = errors.unit_1 || 'Hanya boleh huruf dan angka (tanpa spasi)';
    }
    if (u2 && !unitRegex.test(u2)) {
        errors.unit_2 = errors.unit_2 || 'Hanya boleh huruf dan angka (tanpa spasi)';
    }
    if (u3 && !unitRegex.test(u3)) {
        errors.unit_3 = errors.unit_3 || 'Hanya boleh huruf dan angka (tanpa spasi)';
    }
    if (u4 && !unitRegex.test(u4)) {
        errors.unit_4 = errors.unit_4 || 'Hanya boleh huruf dan angka (tanpa spasi)';
    }

    // D1. Locked units MUST have same name as lock source
    if (lockFrom === 1 && u1) {
        if (u2 && u2 !== u1) errors.unit_2 = `Harus = "${u1}" karena konversi = 1`;
        if (u3 && u3 !== u1) errors.unit_3 = `Harus = "${u1}" karena konversi = 1`;
        if (u4 && u4 !== u1) errors.unit_4 = `Harus = "${u1}" karena konversi = 1`;
    } else if (lockFrom === 2 && u2) {
        if (u3 && u3 !== u2) errors.unit_3 = `Harus = "${u2}" karena konversi = 1`;
        if (u4 && u4 !== u2) errors.unit_4 = `Harus = "${u2}" karena konversi = 1`;
    } else if (lockFrom === 3 && u3) {
        if (u4 && u4 !== u3) errors.unit_4 = `Harus = "${u3}" karena konversi = 1`;
    }

    // D2. Non-locked units MUST be unique
    const unlockLimit = lockFrom ? lockFrom : 4;
    const unitNames = [u1, u2, u3, u4].slice(0, unlockLimit);

    for (let i = 0; i < unlockLimit; i++) {
        if (!unitNames[i]) continue;
        for (let j = 0; j < i; j++) {
            if (unitNames[j] && unitNames[i] === unitNames[j] && !errors[`unit_${i + 1}`]) {
                errors[`unit_${i + 1}`] = `Tidak boleh sama dengan Unit ${j + 1}`;
            }
        }
    }

    // ========================================
    // E. PRICE VALIDATION (real-time)
    // ========================================

    // E1. AUTO mode: harga_1 required, others calculated
    if (priceInputMode.value === 'auto') {
        // Real-time check: if harga_1 is entered but = 0
        if (produk.value.harga_1 !== null && produk.value.harga_1 !== undefined && h1 <= 0 && submitted.value) {
            errors.harga_1 = 'Harga harus lebih dari 0';
        }
    }

    // E2. MANUAL mode: All prices validated
    // Rule 1: Harga tidak boleh sama/lebih besar dari atasnya KECUALI locked (harus sama)
    // Rule 2: PPU (Price Per Unit) harus naik (beli eceran lebih mahal per unit)
    if (priceInputMode.value === 'manual') {
        // Calculate PPU (Price Per Unit) = harga / konversi
        const ppu1 = k1 > 0 ? h1 / k1 : 0;
        const ppu2 = k2 > 0 ? h2 / k2 : 0;
        const ppu3 = k3 > 0 ? h3 / k3 : 0;
        const ppu4 = h4; // k4 = 1, so ppu4 = h4

        // Check harga_2 vs harga_1
        if (h1 > 0 && h2 > 0) {
            if (lockFrom === 1) {
                // Locked from unit 1: h2 must = h1
                if (h2 !== h1) errors.harga_2 = errors.harga_2 || `Harus = ${formatCurrencyShort(h1)} (locked)`;
            } else {
                // Not locked: h2 must be < h1 (harga turun)
                if (h2 >= h1) errors.harga_2 = errors.harga_2 || `Harus < ${formatCurrencyShort(h1)}`;
                // Also check PPU ascending (ppu2 >= ppu1)
                else if (ppu2 < ppu1) {
                    errors.harga_2 = errors.harga_2 || `PPU terlalu murah (${formatCurrencyShort(Math.round(ppu2))}/unit < ${formatCurrencyShort(Math.round(ppu1))}/unit)`;
                }
            }
        }

        // Check harga_3 vs harga_2
        if (h2 > 0 && h3 > 0) {
            if (lockFrom && lockFrom <= 2) {
                // Locked from unit 1 or 2: h3 must = lock source
                const lockSource = lockFrom === 1 ? h1 : h2;
                if (h3 !== lockSource) errors.harga_3 = errors.harga_3 || `Harus = ${formatCurrencyShort(lockSource)} (locked)`;
            } else {
                // Not locked: h3 must be < h2 (harga turun)
                if (h3 >= h2) errors.harga_3 = errors.harga_3 || `Harus < ${formatCurrencyShort(h2)}`;
                // Also check PPU ascending (ppu3 >= ppu2)
                else if (ppu3 < ppu2) {
                    errors.harga_3 = errors.harga_3 || `PPU terlalu murah (${formatCurrencyShort(Math.round(ppu3))}/unit < ${formatCurrencyShort(Math.round(ppu2))}/unit)`;
                }
            }
        }

        // Check harga_4 vs harga_3
        if (h3 > 0 && h4 > 0) {
            if (lockFrom && lockFrom <= 3) {
                // Locked: h4 must = lock source
                const lockSource = lockFrom === 1 ? h1 : lockFrom === 2 ? h2 : h3;
                if (h4 !== lockSource) errors.harga_4 = errors.harga_4 || `Harus = ${formatCurrencyShort(lockSource)} (locked)`;
            } else {
                // Not locked: h4 must be < h3 (harga turun)
                if (h4 >= h3) errors.harga_4 = errors.harga_4 || `Harus < ${formatCurrencyShort(h3)}`;
                // Also check PPU ascending (ppu4 >= ppu3)
                else if (ppu4 < ppu3) {
                    errors.harga_4 = errors.harga_4 || `PPU terlalu murah (${formatCurrencyShort(Math.round(ppu4))}/unit < ${formatCurrencyShort(Math.round(ppu3))}/unit)`;
                }
            }
        }
    }

    // Combine general errors
    if (generalErrors.length > 0) {
        errors.general = generalErrors.join('. ');
    }

    return errors;
});

// Helper for short currency format - now uses global settings via useFormatters composable

// Check if form has validation errors
const hasValidationErrors = computed(() => {
    return Object.values(validationErrors.value).some((v) => v !== null);
});

// ============================================
// UNIT LOCKING LOGIC
// ============================================

// Determine locked units based on konversi values
// Unit is locked when a PREVIOUS unit has konversi = 1
const lockedUnits = computed(() => {
    const locked = [];
    if (Number(produk.value.konversi_1) === 1) {
        locked.push(2, 3, 4); // Lock units 2, 3, 4
    } else if (Number(produk.value.konversi_2) === 1) {
        locked.push(3, 4); // Lock units 3, 4
    } else if (Number(produk.value.konversi_3) === 1) {
        locked.push(4); // Lock unit 4
    }
    // Note: konversi_4 VALUE is always 1, but unit_4 NAME is only locked
    // if a previous konversi is 1. Otherwise unit_4 name is editable.
    return locked;
});

// Check if a unit row is locked
function isUnitLocked(unitNumber) {
    return lockedUnits.value.includes(unitNumber);
}

// Check if price field should be readonly
function isPriceReadonly(unitNumber) {
    if (priceInputMode.value === 'auto') {
        return unitNumber > 1;
    }
    return isUnitLocked(unitNumber) && unitNumber > 1;
}

// Get unit field error
function getUnitError(unitNumber) {
    return validationErrors.value[`unit_${unitNumber}`];
}

// Get konversi field error
function getKonversiError(unitNumber) {
    return validationErrors.value[`konversi_${unitNumber}`];
}

// Get harga field error
function getHargaError(unitNumber) {
    return validationErrors.value[`harga_${unitNumber}`];
}

// ============================================
// WATCHERS FOR AUTO-LOCK
// ============================================

// Watch for konversi changes to handle auto-lock
watch(
    () => produk.value.konversi_1,
    (newVal) => {
        if (isLoadingEdit.value) return;
        if (Number(newVal) === 1) {
            produk.value.unit_2 = produk.value.unit_1;
            produk.value.unit_3 = produk.value.unit_1;
            produk.value.unit_4 = produk.value.unit_1;
            produk.value.konversi_2 = 1;
            produk.value.konversi_3 = 1;
            produk.value.konversi_4 = 1;
            if (priceInputMode.value === 'manual') {
                produk.value.harga_2 = produk.value.harga_1;
                produk.value.harga_3 = produk.value.harga_1;
                produk.value.harga_4 = produk.value.harga_1;
            }
        }
    },
    { immediate: true }
);

watch(
    () => produk.value.konversi_2,
    (newVal) => {
        if (isLoadingEdit.value) return;
        if (Number(newVal) === 1 && Number(produk.value.konversi_1) !== 1) {
            produk.value.unit_3 = produk.value.unit_2;
            produk.value.unit_4 = produk.value.unit_2;
            produk.value.konversi_3 = 1;
            produk.value.konversi_4 = 1;
            if (priceInputMode.value === 'manual') {
                produk.value.harga_3 = produk.value.harga_2;
                produk.value.harga_4 = produk.value.harga_2;
            }
        }
    },
    { immediate: true }
);

watch(
    () => produk.value.konversi_3,
    (newVal) => {
        if (isLoadingEdit.value) return;
        if (Number(newVal) === 1 && Number(produk.value.konversi_2) !== 1 && Number(produk.value.konversi_1) !== 1) {
            produk.value.unit_4 = produk.value.unit_3;
            produk.value.konversi_4 = 1;
            if (priceInputMode.value === 'manual') {
                produk.value.harga_4 = produk.value.harga_3;
            }
        }
    },
    { immediate: true }
);

// Watch for unit changes when locked (auto-copy)
watch(
    () => produk.value.unit_1,
    (newVal) => {
        if (isLoadingEdit.value) return;
        if (Number(produk.value.konversi_1) === 1) {
            produk.value.unit_2 = newVal;
            produk.value.unit_3 = newVal;
            produk.value.unit_4 = newVal;
        }
    }
);

watch(
    () => produk.value.unit_2,
    (newVal) => {
        if (isLoadingEdit.value) return;
        if (Number(produk.value.konversi_2) === 1 && Number(produk.value.konversi_1) !== 1) {
            produk.value.unit_3 = newVal;
            produk.value.unit_4 = newVal;
        }
    }
);

watch(
    () => produk.value.unit_3,
    (newVal) => {
        if (isLoadingEdit.value) return;
        if (Number(produk.value.konversi_3) === 1 && Number(produk.value.konversi_2) !== 1 && Number(produk.value.konversi_1) !== 1) {
            produk.value.unit_4 = newVal;
        }
    }
);

// Watch for harga changes when locked (MANUAL mode - auto-copy price)
watch(
    () => produk.value.harga_1,
    (newVal) => {
        if (isLoadingEdit.value) return;
        if (priceInputMode.value === 'manual' && Number(produk.value.konversi_1) === 1) {
            produk.value.harga_2 = newVal;
            produk.value.harga_3 = newVal;
            produk.value.harga_4 = newVal;
        }
    }
);

watch(
    () => produk.value.harga_2,
    (newVal) => {
        if (isLoadingEdit.value) return;
        if (priceInputMode.value === 'manual' && Number(produk.value.konversi_2) === 1 && Number(produk.value.konversi_1) !== 1) {
            produk.value.harga_3 = newVal;
            produk.value.harga_4 = newVal;
        }
    }
);

watch(
    () => produk.value.harga_3,
    (newVal) => {
        if (isLoadingEdit.value) return;
        if (priceInputMode.value === 'manual' && Number(produk.value.konversi_3) === 1 && Number(produk.value.konversi_2) !== 1 && Number(produk.value.konversi_1) !== 1) {
            produk.value.harga_4 = newVal;
        }
    }
);

// Calculate prices in AUTO mode
function calculatePrices() {
    if (priceInputMode.value !== 'auto') return;
    if (!produk.value.harga_1 || !produk.value.konversi_1) return;

    const basePrice = produk.value.harga_1 / produk.value.konversi_1;
    produk.value.harga_2 = Math.round(basePrice * (produk.value.konversi_2 || 1) * 100) / 100;
    produk.value.harga_3 = Math.round(basePrice * (produk.value.konversi_3 || 1) * 100) / 100;
    produk.value.harga_4 = Math.round(basePrice * 100) / 100;
}

// Watch harga_1 and konversi changes for AUTO price calculation
watch([() => produk.value.harga_1, () => produk.value.konversi_1, () => produk.value.konversi_2, () => produk.value.konversi_3], () => {
    if (!isLoadingEdit.value) {
        calculatePrices();
    }
});

// ============================================
// CASCADING DROPDOWNS
// ============================================

watch(
    () => produk.value.tipe_id,
    async (newTipeId) => {
        if (isLoadingEdit.value) return;

        produk.value.kategori_id = null;
        produk.value.grup_id = null;
        grupFormOptions.value = [];

        if (newTipeId) {
            const tipe = tipeOptions.value.find((t) => t.value === newTipeId);
            if (tipe) {
                await loadKategoriByTipe(tipe.ulid);
            }
        } else {
            kategoriFormOptions.value = [];
        }
    }
);

watch(
    () => produk.value.kategori_id,
    async (newKategoriId) => {
        if (isLoadingEdit.value) return;

        produk.value.grup_id = null;

        if (newKategoriId) {
            const kategori = kategoriFormOptions.value.find((k) => k.value === newKategoriId);
            if (kategori) {
                await loadGrupByKategori(kategori.ulid);
            }
        } else {
            grupFormOptions.value = [];
        }
    }
);

// Load kategoris filtered by tipe for form
async function loadKategoriByTipe(tipeUlid) {
    try {
        const response = await kategorisApi.getList({ tipe_ulid: tipeUlid });
        if (response.data.success) {
            kategoriFormOptions.value = response.data.data.kategoris.map((k) => ({
                label: k.nama_kategori,
                value: k.id,
                ulid: k.ulid
            }));
        }
    } catch (error) {
        console.error('Failed to load kategoris:', error);
        notify.apiError(error, 'Gagal load kategoris');
    }
}

// Load grups filtered by kategori for form
async function loadGrupByKategori(kategoriUlid) {
    try {
        const response = await grupsApi.getList({ kategori_ulid: kategoriUlid });
        if (response.data.success) {
            grupFormOptions.value = response.data.data.grups.map((g) => ({
                label: g.nama_grup,
                value: g.id,
                ulid: g.ulid
            }));
        }
    } catch (error) {
        console.error('Failed to load grups:', error);
        notify.apiError(error, 'Gagal load grups');
    }
}

// Load price input mode from settings
async function loadPriceMode() {
    try {
        const response = await produksApi.getPriceMode();
        if (response.data.success) {
            priceInputMode.value = response.data.data.price_input_mode;
        }
    } catch (error) {
        console.error('Failed to load price mode:', error);
        notify.apiError(error, 'Gagal load price mode');
    }
}

// Load dropdown options
async function loadDropdownOptions() {
    try {
        const [brandsRes, tipesRes, kategorisRes] = await Promise.all([brandsApi.getList(), tipesApi.getList(), kategorisApi.getList()]);

        if (brandsRes.data.success) {
            brandOptions.value = brandsRes.data.data.brands.map((b) => ({
                label: b.nama_brand,
                value: b.id,
                ulid: b.ulid
            }));
        }

        if (tipesRes.data.success) {
            tipeOptions.value = tipesRes.data.data.tipes.map((t) => ({
                label: t.nama_tipe,
                value: t.id,
                ulid: t.ulid
            }));
        }

        if (kategorisRes.data.success) {
            kategoriOptions.value = kategorisRes.data.data.kategoris.map((k) => ({
                label: k.nama_kategori,
                value: k.id,
                ulid: k.ulid
            }));
        }
    } catch (error) {
        console.error('Failed to load dropdown options:', error);
        notify.apiError(error, 'Gagal load dropdown options');
    }
}

// Load produks
async function loadProduks() {
    loading.value = true;
    try {
        const params = {
            page: lazyParams.value.first / lazyParams.value.rows + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField,
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };

        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedBrand.value) params.brand_id = selectedBrand.value;
        if (selectedTipe.value) params.tipe_id = selectedTipe.value;
        if (selectedKategori.value) params.kategori_id = selectedKategori.value;
        if (selectedStatus.value) params.status = selectedStatus.value;

        const response = await produksApi.getAll(params);
        if (response.data.success) {
            produks.value = response.data.data.produks;
            totalRecords.value = response.data.data.pagination.total;
        }
    } catch (error) {
        console.error('Failed to load produks:', error);
        notify.loadListError('produk');
    } finally {
        loading.value = false;
    }
}

// Pagination handler
function onPage(event) {
    lazyParams.value = { ...lazyParams.value, ...event };
    loadProduks();
}

// Sort handler
function onSort(event) {
    lazyParams.value = { ...lazyParams.value, ...event };
    loadProduks();
}

// Search handler
function onSearch() {
    lazyParams.value.first = 0;
    loadProduks();
}

// Reset filters
function clearSearch() {
    searchQuery.value = '';
    lazyParams.value.first = 0;
    loadProduks();
}

function resetFilters() {
    searchQuery.value = '';
    selectedBrand.value = null;
    selectedTipe.value = null;
    selectedKategori.value = null;
    selectedStatus.value = null;
    lazyParams.value.first = 0;
    loadProduks();
}

// Export PDF — fetch all data matching current filters (ignoring pagination)
async function exportPdf() {
    const filterParts = [];
    const params = {
        page: 1,
        per_page: 999999,
        sort_field: lazyParams.value.sortField,
        sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
    };

    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedBrand.value) {
        params.brand_id = selectedBrand.value;
        const brand = brandOptions.value.find((b) => b.value === selectedBrand.value);
        if (brand) filterParts.push(`Brand: ${brand.label}`);
    }
    if (selectedTipe.value) {
        params.tipe_id = selectedTipe.value;
        const tipe = tipeOptions.value.find((t) => t.value === selectedTipe.value);
        if (tipe) filterParts.push(`Tipe: ${tipe.label}`);
    }
    if (selectedKategori.value) {
        params.kategori_id = selectedKategori.value;
        const kat = kategoriOptions.value.find((k) => k.value === selectedKategori.value);
        if (kat) filterParts.push(`Kategori: ${kat.label}`);
    }
    if (selectedStatus.value) {
        params.status = selectedStatus.value;
        const st = statusOptions.find((s) => s.value === selectedStatus.value);
        if (st) filterParts.push(`Status: ${st.label}`);
    }
    if (searchQuery.value) filterParts.push(`Search: "${searchQuery.value}"`);

    let allData;
    try {
        const response = await produksApi.getAll(params);
        if (!response.data.success) return;
        allData = response.data.data.produks;
    } catch {
        notify.exportError();
        return;
    }

    const fmtRel = (obj, kode, nama) => {
        if (!obj) return '-';
        return obj[kode] ? `[${obj[kode]}] ${obj[nama]}` : obj[nama] || '-';
    };

    const fmtKlasifikasi = (row) => {
        return [
            `${'Brand'.padEnd(9)}: ${fmtRel(row.brand, 'kode_brand', 'nama_brand')}`,
            `${'Tipe'.padEnd(9)}: ${fmtRel(row.tipe, 'kode_tipe', 'nama_tipe')}`,
            `${'Kategori'.padEnd(9)}: ${fmtRel(row.kategori, 'kode_kategori', 'nama_kategori')}`,
            `${'Grup'.padEnd(9)}: ${fmtRel(row.grup, 'kode_grup', 'nama_grup')}`
        ].join('\n');
    };

    const fmtUnitHarga = (row) => {
        if (row.is_serial) return 'Serial (per unit)';
        const lines = [];
        for (let i = 1; i <= 4; i++) {
            const unit = row[`unit_${i}`] || '-';
            const harga = formatCurrency(row[`harga_${i}`]);
            const konv = i < 4 ? row[`konversi_${i}`] : null;
            const left = konv ? `${unit} (x${konv})` : unit;
            lines.push(`${left.padEnd(14)}= ${harga}`);
        }
        return lines.join('\n');
    };

    const monoStyle = { font: 'courier', fontSize: 6.5 };

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode Produk', field: 'kode_produk', width: 24 },
        { header: 'Barcode', width: 24, accessor: (row) => (row.is_serial ? '—' : row.barcode || '-') },
        { header: 'Nama Produk', field: 'nama_produk' },
        { header: 'Jenis', width: 16, align: 'center', accessor: (row) => (row.is_serial ? 'Serial' : 'Retail') },
        { header: 'Klasifikasi', accessor: fmtKlasifikasi, cellStyle: monoStyle },
        { header: 'Satuan & Harga', accessor: fmtUnitHarga, cellStyle: monoStyle },
        { header: 'Status', width: 16, align: 'center', accessor: (row) => getStatusLabel(row.status) }
    ];

    exportListPdf({
        title: 'Daftar Produk',
        filename: 'daftar_produk',
        columns,
        data: allData,
        filters: filterParts.length > 0 ? filterParts.join(', ') : null,
        totalLabel: `Total: ${allData.length} produk`
    });
}

// Export Excel — download XLSX via backend
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (searchQuery.value) params.search = searchQuery.value;
        if (selectedBrand.value) params.brand_id = selectedBrand.value;
        if (selectedTipe.value) params.tipe_id = selectedTipe.value;
        if (selectedKategori.value) params.kategori_id = selectedKategori.value;
        if (selectedStatus.value) params.status = selectedStatus.value;

        const response = await produksApi.export(params);

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `master_produk_${todayString()}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);

        notify.exportSuccess();
    } catch (error) {
        notify.exportError();
    } finally {
        exportingExcel.value = false;
    }
}

// Export detail PDF — dari detailData atau fetch by ulid
async function downloadDetailPdf(data) {
    exportingDetail.value = true;
    try {
        let d = data;
        // Jika dipanggil dari list (belum ada detail lengkap), fetch dulu
        if (!d.warehouse_stocks) {
            const response = await produksApi.get(d.ulid);
            if (!response.data.success) return;
            d = response.data.data.produk;
        }

        const fmtRel = (obj, nama) => obj?.[nama] ?? '-';
        const isSerial = !!d.is_serial;

        const info = [
            { label: 'Kode Produk', value: d.kode_produk || '-' },
            { label: 'Barcode', value: isSerial ? '—' : d.barcode || '-' },
            { label: 'Nama Produk', value: d.nama_produk || '-' },
            { label: 'Status', value: d.status === 'active' ? 'Aktif' : 'Nonaktif' },
            { label: 'Jenis', value: isSerial ? 'Produk Serial (per unit)' : 'Retail' },
            { label: 'Brand', value: fmtRel(d.brand, 'nama_brand') },
            { label: 'Tipe', value: fmtRel(d.tipe, 'nama_tipe') },
            { label: 'Kategori', value: fmtRel(d.kategori, 'nama_kategori') },
            { label: 'Grup', value: fmtRel(d.grup, 'nama_grup') }
        ];

        const tables = [];
        const summary = [];
        // Catatan serial → pakai blok `notes` (full-width, auto-wrap) supaya tidak terpotong margin.
        const notes = isSerial ? 'Produk Serial — harga jual, HPP, dan stok dilacak per unit (nomor seri), bukan di level produk.' : null;

        if (isSerial) {
            // Serial: tabel unit & stok per gudang tidak relevan (data asli per-unit).
        } else {
            info.push({ label: 'Minimum Stok', value: `${formatQty(d.minimum_stok || 0)} ${d.unit_4 || ''}` });
            if (canViewHpp.value) {
                info.push({ label: 'HPP', value: `${formatCurrency(d.avg_cost || 0)} / ${d.unit_4 || 'unit'}` });
            }

            // Unit & Harga table
            const unitColumns = [
                { header: 'Unit', field: 'label' },
                { header: 'Satuan', field: 'satuan' },
                { header: 'Konversi', field: 'konversi', align: 'right' },
                { header: 'Harga', field: 'harga', align: 'right' }
            ];
            const unitData = [1, 2, 3, 4].map((n) => ({
                label: `Unit ${n}`,
                satuan: d[`unit_${n}`] || '-',
                konversi: n < 4 ? String(d[`konversi_${n}`] ?? '') : '1',
                harga: formatCurrency(d[`harga_${n}`] || 0)
            }));

            // Stok per Gudang table
            const stockColumns = [
                { header: '#', field: '#', align: 'center' },
                { header: 'Kode Gudang', field: 'kode_warehouse' },
                { header: 'Gudang', field: 'nama_warehouse' },
                { header: 'Stok', field: 'qty', align: 'right' },
                { header: 'Status', field: 'status', align: 'center' }
            ];
            const stockData = (d.warehouse_stocks || [])
                .filter((s) => s.warehouse)
                .map((s) => ({
                    kode_warehouse: s.warehouse.kode_warehouse || '-',
                    nama_warehouse: s.warehouse.nama_warehouse || '-',
                    qty: `${formatQty(s.qty ?? 0)} ${d.unit_4 || ''}`,
                    status: s.warehouse.status === 'active' ? 'Aktif' : 'Nonaktif'
                }));

            const totalStok = (d.warehouse_stocks || []).reduce((sum, s) => sum + (s.qty ?? 0), 0);

            summary.push({ label: `Total Stok (${d.unit_4 || 'unit'})`, value: formatQty(totalStok) });
            if (canViewHpp.value) {
                summary.push({ label: 'Total Nilai', value: formatCurrency(totalStok * (d.avg_cost || 0)), bold: true });
            }

            tables.push({ title: 'Satuan & Harga', columns: unitColumns, data: unitData }, { title: 'Stok per Gudang', columns: stockColumns, data: stockData });
        }

        const audit = [];
        if (d.created_at) audit.push({ label: 'Dibuat oleh', value: d.created_by?.name || '-', date: d.created_at });
        if (d.updated_at) audit.push({ label: 'Diubah oleh', value: d.updated_by?.name || '-', date: d.updated_at });

        await exportDocumentPdf({
            title: 'Detail Produk',
            filename: `produk_${d.kode_produk || 'detail'}`,
            info,
            tables,
            summary,
            notes,
            audit
        });
    } catch (error) {
        notify.exportError();
    } finally {
        exportingDetail.value = false;
    }
}

// Initialize form
function initForm() {
    return {
        ulid: null,
        kode_produk: '',
        barcode: '',
        // Modul Serial (A+): default centang (Syilex toko elektronik). Hilangkan untuk aksesoris.
        is_serial: true,
        nama_produk: '',
        brand_id: null,
        tipe_id: null,
        kategori_id: null,
        grup_id: null,
        gambar: null,
        gambar_url: null,
        minimum_stok: 0,
        unit_1: '',
        konversi_1: null,
        harga_1: 0,
        unit_2: '',
        konversi_2: null,
        harga_2: 0,
        unit_3: '',
        konversi_3: null,
        harga_3: 0,
        unit_4: '',
        konversi_4: 1,
        harga_4: 0,
        status: 'active'
    };
}

// Open dialog for create
async function openNew() {
    produk.value = initForm();
    kategoriFormOptions.value = [];
    grupFormOptions.value = [];
    imagePreview.value = null;
    submitted.value = false;
    produkDialog.value = true;

    // Load dropdown jika belum ada (mungkin sudah di-load dari onMounted)
    if (brandOptions.value.length === 0) {
        await Promise.all([loadPriceMode(), loadDropdownOptions()]);
    }
}

// Open dialog for edit
async function editProduk(data) {
    isLoadingEdit.value = true;

    // Fetch full data to get IDs
    try {
        // Load dropdown options jika belum ada (lazy load)
        const loadPromises = [produksApi.get(data.ulid)];
        if (brandOptions.value.length === 0) {
            loadPromises.push(loadPriceMode());
            loadPromises.push(loadDropdownOptions());
        }

        const [response] = await Promise.all(loadPromises);
        if (!response.data.success) {
            throw new Error('Failed to load produk');
        }

        const fullData = response.data.data.produk;

        // Reset form first
        produk.value = {
            ...fullData,
            brand_id: fullData.brand?.id || null,
            tipe_id: fullData.tipe?.id || null,
            kategori_id: null,
            grup_id: null,
            gambar: null // Reset file, keep gambar_url
        };

        // Set image preview
        imagePreview.value = fullData.gambar_url;

        // Load cascading dropdowns
        if (fullData.tipe?.ulid) {
            await loadKategoriByTipe(fullData.tipe.ulid);
            produk.value.kategori_id = fullData.kategori?.id || null;
        }

        if (fullData.kategori?.ulid) {
            await loadGrupByKategori(fullData.kategori.ulid);
            produk.value.grup_id = fullData.grup?.id || null;
        }

        submitted.value = false;
        produkDialog.value = true;
    } catch (error) {
        console.error('Failed to load produk for edit:', error);
        notify.loadDetailError('produk');
    } finally {
        isLoadingEdit.value = false;
    }
}

// Hide dialog
function hideDialog() {
    produkDialog.value = false;
    submitted.value = false;
    imagePreview.value = null;
}

// Save produk
async function saveProduk() {
    submitted.value = true;

    // Check validation errors
    if (hasValidationErrors.value) {
        notify.formInvalid();
        return;
    }

    saving.value = true;
    try {
        const formData = new FormData();
        const isSerial = !!produk.value.is_serial;
        formData.append('kode_produk', produk.value.kode_produk);
        formData.append('is_serial', isSerial ? '1' : '0');
        // Barcode: produk serial discan via SN per-unit (bukan barcode produk) → tak dikirim utk serial
        if (!isSerial) {
            formData.append('barcode', produk.value.barcode || '');
        }
        formData.append('nama_produk', produk.value.nama_produk);
        formData.append('brand_id', produk.value.brand_id || '');
        formData.append('tipe_id', produk.value.tipe_id || '');
        formData.append('kategori_id', produk.value.kategori_id || '');
        formData.append('grup_id', produk.value.grup_id || '');
        // Satuan/harga/min-stok: serial → scaffold UNIT/1/0 (tak dipakai; harga riil per-unit di register)
        const unitVal = (n) => (isSerial ? 'UNIT' : (produk.value[`unit_${n}`] || '').toUpperCase().trim());
        const konvVal = (n) => (isSerial ? 1 : produk.value[`konversi_${n}`]);
        const hargaVal = (n) => (isSerial ? 0 : produk.value[`harga_${n}`] || 0);
        formData.append('minimum_stok', isSerial ? 0 : produk.value.minimum_stok || 0);
        formData.append('unit_1', unitVal(1));
        formData.append('konversi_1', konvVal(1));
        formData.append('harga_1', hargaVal(1));
        formData.append('unit_2', unitVal(2));
        formData.append('konversi_2', konvVal(2));
        formData.append('harga_2', hargaVal(2));
        formData.append('unit_3', unitVal(3));
        formData.append('konversi_3', konvVal(3));
        formData.append('harga_3', hargaVal(3));
        formData.append('unit_4', unitVal(4));
        formData.append('konversi_4', 1);
        formData.append('harga_4', hargaVal(4));
        formData.append('status', produk.value.status);

        if (produk.value.gambar instanceof File) {
            formData.append('gambar', produk.value.gambar);
        }

        let response;
        if (isEdit.value) {
            response = await produksApi.update(produk.value.ulid, formData);
        } else {
            response = await produksApi.create(formData);
        }

        if (response.data.success) {
            notify.success(response.data.message);
            hideDialog();
            loadProduks();
        }
    } catch (error) {
        notify.saveError(error);
    } finally {
        saving.value = false;
    }
}

// Confirm toggle status
function confirmToggleStatus(data) {
    const isActive = data.status === 'active';
    const action = isActive ? 'menonaktifkan' : 'mengaktifkan';

    confirm.require({
        message: `Apakah Anda yakin ingin ${action} produk "${data.nama_produk}"?`,
        header: isActive ? 'Konfirmasi Nonaktifkan' : 'Konfirmasi Aktifkan',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Batal',
            severity: 'secondary',
            outlined: true
        },
        acceptProps: {
            label: isActive ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan',
            severity: isActive ? 'warn' : 'success'
        },
        accept: () => toggleStatus(data)
    });
}

// Toggle status
async function toggleStatus(data) {
    try {
        const response = await produksApi.toggleStatus(data.ulid);
        if (response.data.success) {
            notify.success(response.data.message);
            loadProduks();
        }
    } catch (error) {
        notify.statusChangeError('produk');
    }
}

// Confirm delete
function confirmDelete(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin menghapus produk "${data.nama_produk}"? Data yang dihapus tidak dapat dikembalikan.`,
        header: 'Konfirmasi Hapus',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Batal',
            severity: 'secondary',
            outlined: true
        },
        acceptProps: {
            label: 'Ya, Hapus',
            severity: 'danger'
        },
        accept: () => deleteProduk(data)
    });
}

// Delete produk
async function deleteProduk(data) {
    try {
        const response = await produksApi.delete(data.ulid);
        if (response.data.success) {
            notify.deleted('produk');
            loadProduks();
        }
    } catch (error) {
        notify.deleteError(error);
    }
}

// View detail
async function viewDetail(data) {
    detailDialog.value = true;
    loadingDetail.value = true;
    detailData.value = {};
    try {
        const response = await produksApi.get(data.ulid);
        if (response.data.success) {
            detailData.value = response.data.data.produk;
        }
    } catch (error) {
        notify.loadDetailError('produk');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

// Navigate to HPP Movement page
function viewHppMovement(productId) {
    detailDialog.value = false;
    router.push({
        name: 'inventory-pergerakan-hpp',
        query: { product_id: productId }
    });
}

// Navigate to Stock Card page filtered by product + warehouse
function viewStockCard(warehouseId) {
    detailDialog.value = false;
    router.push({
        name: 'inventory-kartu-stok',
        query: {
            product_id: detailData.value.ulid,
            warehouse_id: warehouseId
        }
    });
}

// ============================================
// IMAGE HANDLING
// ============================================

function triggerImageInput() {
    imageInput.value?.click();
}

function handleImageSelect(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    // Validate file type
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        notify.error('Format file harus JPG, PNG, atau WebP');
        return;
    }

    // Validate file size (max 2MB)
    const maxSizeBytes = 2 * 1024 * 1024;
    if (file.size > maxSizeBytes) {
        notify.fileTooLarge('2MB');
        return;
    }

    // Set file and create preview
    produk.value.gambar = file;
    imagePreview.value = URL.createObjectURL(file);

    // Reset input
    event.target.value = '';
}

async function removeImage() {
    if (isEdit.value && produk.value.gambar_url) {
        try {
            await produksApi.deleteImage(produk.value.ulid);
            notify.success('Gambar berhasil dihapus');
        } catch (error) {
            notify.error('Gagal menghapus gambar dari server');
            return;
        }
    }

    produk.value.gambar = null;
    produk.value.gambar_url = null;
    imagePreview.value = null;
}

// On mounted - load table dulu, dropdown filter setelahnya (non-blocking)
onMounted(async () => {
    // Load table data terlebih dahulu (prioritas)
    await loadProduks();

    // Load dropdown untuk filter setelah table muncul (non-blocking)
    loadPriceMode();
    loadDropdownOptions();
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button v-if="canCreate" label="Tambah Produk" icon="pi pi-plus" severity="primary" @click="openNew" />
            </template>

            <template #end>
                <div class="flex flex-wrap gap-2">
                    <Select v-model="selectedBrand" :options="brandOptions" optionLabel="label" optionValue="value" placeholder="Brand" class="w-32" filter showClear @change="onSearch" />
                    <Select v-model="selectedTipe" :options="tipeOptions" optionLabel="label" optionValue="value" placeholder="Tipe" class="w-32" filter showClear @change="onSearch" />
                    <Select v-model="selectedKategori" :options="kategoriOptions" optionLabel="label" optionValue="value" placeholder="Kategori" class="w-36" filter showClear @change="onSearch" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-32" filter showClear @change="onSearch" />
                    <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                </div>
            </template>
        </Toolbar>

        <!-- DataTable -->
        <DataTable
            :value="produks"
            :loading="loading"
            :lazy="true"
            :paginator="true"
            :rows="lazyParams.rows"
            :totalRecords="totalRecords"
            :rowsPerPageOptions="[10, 25, 50]"
            :first="lazyParams.first"
            @page="onPage"
            @sort="onSort"
            :sortField="lazyParams.sortField"
            :sortOrder="lazyParams.sortOrder"
            dataKey="ulid"
            responsiveLayout="scroll"
            stripedRows
            showGridlines
            scrollable
        >
            <template #header>
                <DataTableHeader v-model="searchQuery" title="Daftar Produk" placeholder="Cari kode, barcode, nama..." @search="onSearch" @clear="clearSearch">
                    <template #extra>
                        <div class="flex gap-2">
                            <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                            <Button v-if="canExport" icon="pi pi-file-pdf" severity="secondary" outlined :loading="exporting" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                        </div>
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-4">Tidak ada data produk</div>
            </template>

            <Column field="kode_produk" header="Kode" sortable style="min-width: 120px" />
            <Column field="barcode" header="Barcode" sortable style="min-width: 120px">
                <template #body="slotProps">
                    {{ slotProps.data.is_serial ? '—' : slotProps.data.barcode || '-' }}
                </template>
            </Column>
            <Column field="nama_produk" header="Nama Produk" sortable style="min-width: 200px">
                <template #body="slotProps">
                    <div class="flex items-center gap-2">
                        <span>{{ slotProps.data.nama_produk }}</span>
                        <Tag v-if="slotProps.data.is_serial" value="SERIAL" severity="help" class="text-xs" />
                        <Tag v-else value="RETAIL" severity="secondary" class="text-xs" />
                    </div>
                </template>
            </Column>
            <Column field="brand.nama_brand" header="Brand" style="min-width: 120px">
                <template #body="slotProps">
                    {{ slotProps.data.brand?.nama_brand || '-' }}
                </template>
            </Column>
            <Column field="tipe.nama_tipe" header="Tipe" style="min-width: 120px">
                <template #body="slotProps">
                    {{ slotProps.data.tipe?.nama_tipe || '-' }}
                </template>
            </Column>
            <Column field="harga_4" header="Harga (Base)" sortable style="min-width: 130px">
                <template #body="slotProps">
                    {{ slotProps.data.is_serial ? '—' : formatCurrency(slotProps.data.harga_4) }}
                </template>
            </Column>
            <Column field="unit_4" header="Satuan" style="min-width: 80px">
                <template #body="slotProps">
                    {{ slotProps.data.is_serial ? '—' : slotProps.data.unit_4 }}
                </template>
            </Column>
            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="slotProps">
                    <Tag :value="getStatusLabel(slotProps.data.status)" :severity="getStatusSeverity(slotProps.data.status)" />
                </template>
            </Column>
            <Column :exportable="false" style="min-width: 260px" alignFrozen="right" frozen>
                <template #body="slotProps">
                    <Button icon="pi pi-eye" outlined rounded class="mr-2" severity="info" @click="viewDetail(slotProps.data)" v-tooltip.top="'Lihat Detail'" aria-label="Lihat Detail" />
                    <Button icon="pi pi-file-pdf" outlined rounded class="mr-2" severity="help" @click="downloadDetailPdf(slotProps.data)" v-tooltip.top="'Download PDF'" aria-label="Download PDF" />
                    <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editProduk(slotProps.data)" v-tooltip.top="'Edit'" aria-label="Edit" />
                    <Button
                        v-if="canUpdate"
                        icon="pi pi-power-off"
                        outlined
                        rounded
                        class="mr-2"
                        :severity="getToggleSeverity(slotProps.data.status)"
                        @click="confirmToggleStatus(slotProps.data)"
                        v-tooltip.top="getToggleLabel(slotProps.data.status)"
                        :aria-label="getToggleLabel(slotProps.data.status)"
                    />
                    <Button v-if="canDelete" icon="pi pi-trash" outlined rounded severity="danger" @click="confirmDelete(slotProps.data)" v-tooltip.top="'Hapus'" aria-label="Hapus" />
                </template>
            </Column>
        </DataTable>

        <!-- Create/Edit Dialog -->
        <Dialog v-model:visible="produkDialog" :header="dialogTitle" :modal="true" :style="{ width: '800px' }" :closable="!saving" :closeOnEscape="!saving">
            <div class="flex flex-col gap-4">
                <!-- Informasi Dasar -->
                <Fieldset legend="Informasi Dasar">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="flex flex-col gap-2">
                            <label for="kode_produk" class="font-medium">Kode Produk <span class="text-red-500">*</span></label>
                            <InputText id="kode_produk" v-model="produk.kode_produk" :disabled="isEdit" :invalid="!!validationErrors.kode_produk" :style="{ textTransform: 'uppercase' }" placeholder="Contoh: PRD001" />
                            <small v-if="validationErrors.kode_produk" class="text-red-500">{{ validationErrors.kode_produk }}</small>
                            <small v-else-if="isEdit" class="text-surface-500">Kode tidak dapat diubah</small>
                        </div>
                        <div v-if="!produk.is_serial" class="flex flex-col gap-2">
                            <label for="barcode" class="font-medium">Barcode</label>
                            <InputText id="barcode" v-model="produk.barcode" placeholder="Scan atau input barcode" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <label for="status" class="font-medium">Status <span class="text-red-500">*</span></label>
                            <Select id="status" v-model="produk.status" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Pilih Status" filter />
                        </div>
                    </div>
                    <!-- Modul Serial (A+): toggle is_serial — immutable saat edit. Sembunyi bila Modul Elektronik nonaktif -->
                    <div v-if="serialEnabled" class="mt-4 p-3 rounded-lg bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
                        <label class="flex items-center gap-2 font-medium cursor-pointer" :class="{ 'opacity-60': isEdit }">
                            <Checkbox v-model="produk.is_serial" :binary="true" :disabled="isEdit" />
                            <span>Produk Serial <span class="text-surface-500 font-normal">(dilacak per unit / nomor seri)</span></span>
                        </label>
                        <small class="text-surface-500 block ml-7 mt-1">
                            <template v-if="produk.is_serial">Satuan, harga &amp; stok diatur <b>per-unit</b> di modul Serial. Hilangkan centang untuk aksesoris/barang qty biasa.</template>
                            <template v-else>Produk qty biasa (retail) — isi satuan, harga, dan stok di bawah.</template>
                            <span v-if="isEdit"> · Tidak bisa diubah setelah produk dibuat.</span>
                        </small>
                    </div>
                    <div class="flex flex-col gap-2 mt-4">
                        <label for="nama_produk" class="font-medium">Nama Produk <span class="text-red-500">*</span></label>
                        <InputText id="nama_produk" v-model="produk.nama_produk" :invalid="!!validationErrors.nama_produk" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" placeholder="Nama lengkap produk" />
                        <small v-if="validationErrors.nama_produk" class="text-red-500">{{ validationErrors.nama_produk }}</small>
                    </div>
                    <div class="flex flex-col gap-2 mt-4">
                        <label class="font-medium">Gambar Produk</label>
                        <input ref="imageInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="handleImageSelect" />
                        <div class="flex items-start gap-4">
                            <!-- Image Preview -->
                            <div
                                class="w-32 h-32 border-2 border-dashed rounded-lg flex items-center justify-center cursor-pointer hover:border-primary transition-colors overflow-hidden"
                                :class="imagePreview ? 'border-solid border-surface-200' : 'border-surface-300'"
                                @click="triggerImageInput"
                            >
                                <img v-if="imagePreview" :src="imagePreview" alt="Preview" class="w-full h-full object-contain" />
                                <div v-else class="flex flex-col items-center text-surface-400">
                                    <i class="pi pi-image text-3xl mb-2"></i>
                                    <span class="text-xs">Klik untuk upload</span>
                                </div>
                            </div>
                            <!-- Actions -->
                            <div class="flex flex-col gap-2">
                                <Button label="Pilih Gambar" icon="pi pi-upload" size="small" outlined @click="triggerImageInput" />
                                <Button v-if="imagePreview" label="Hapus" icon="pi pi-trash" size="small" severity="danger" text @click="removeImage" />
                                <small class="text-surface-500">JPG, PNG, WebP. Maks 2MB</small>
                            </div>
                        </div>
                    </div>
                </Fieldset>

                <!-- Klasifikasi -->
                <Fieldset legend="Klasifikasi">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex flex-col gap-2">
                            <label for="brand_id" class="font-medium">Brand</label>
                            <Select id="brand_id" v-model="produk.brand_id" :options="brandOptions" optionLabel="label" optionValue="value" placeholder="Pilih Brand" showClear filter />
                        </div>
                        <div class="flex flex-col gap-2">
                            <label for="tipe_id" class="font-medium">Tipe</label>
                            <Select id="tipe_id" v-model="produk.tipe_id" :options="tipeOptions" optionLabel="label" optionValue="value" placeholder="Pilih Tipe" showClear filter />
                        </div>
                        <div class="flex flex-col gap-2">
                            <label for="kategori_id" class="font-medium">Kategori</label>
                            <Select id="kategori_id" v-model="produk.kategori_id" :options="kategoriFormOptions" optionLabel="label" optionValue="value" placeholder="Pilih Kategori" :disabled="!produk.tipe_id" showClear filter />
                            <small v-if="!produk.tipe_id" class="text-surface-500">Pilih Tipe terlebih dahulu</small>
                        </div>
                        <div class="flex flex-col gap-2">
                            <label for="grup_id" class="font-medium">Grup</label>
                            <Select id="grup_id" v-model="produk.grup_id" :options="grupFormOptions" optionLabel="label" optionValue="value" placeholder="Pilih Grup" :disabled="!produk.kategori_id" showClear filter />
                            <small v-if="!produk.kategori_id" class="text-surface-500">Pilih Kategori terlebih dahulu</small>
                        </div>
                    </div>
                </Fieldset>

                <!-- Satuan & Harga (qty/retail) — serial: harga per-unit di modul Serial -->
                <Fieldset v-if="!produk.is_serial" legend="Satuan & Harga">
                    <div class="mb-3">
                        <Tag :value="priceInputMode === 'auto' ? 'Mode AUTO' : 'Mode MANUAL'" :severity="priceInputMode === 'auto' ? 'info' : 'warn'" />
                        <span class="ml-2 text-surface-500 text-sm">
                            {{ priceInputMode === 'auto' ? 'Harga dihitung otomatis dari Harga Unit 1' : 'Semua harga diinput manual' }}
                        </span>
                    </div>

                    <!-- Konversi Order Error -->
                    <div v-if="validationErrors.konversi_order" class="mb-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                        <i class="pi pi-exclamation-triangle text-red-500 mr-2"></i>
                        <span class="text-red-700">{{ validationErrors.konversi_order }}</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="bg-surface-100">
                                    <th class="p-2 text-left w-12">#</th>
                                    <th class="p-2 text-left">Satuan <span class="text-red-500">*</span></th>
                                    <th class="p-2 text-left">Konversi <span class="text-red-500">*</span></th>
                                    <th class="p-2 text-left">Harga <span class="text-red-500">*</span></th>
                                    <th class="p-2 text-left w-24">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="n in 4" :key="n" :class="{ 'bg-surface-50': isUnitLocked(n) }">
                                    <td class="p-2 font-medium">{{ n }}</td>
                                    <td class="p-2">
                                        <div class="flex flex-col gap-1">
                                            <InputText
                                                v-model="produk[`unit_${n}`]"
                                                :disabled="isUnitLocked(n) && n > 1"
                                                :invalid="!!getUnitError(n)"
                                                placeholder="Contoh: PCS"
                                                class="w-full"
                                                :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                                            />
                                            <small v-if="getUnitError(n)" class="text-red-500 text-xs">{{ getUnitError(n) }}</small>
                                        </div>
                                    </td>
                                    <td class="p-2">
                                        <div class="flex flex-col gap-1">
                                            <InputNumber
                                                v-select-on-focus
                                                v-model="produk[`konversi_${n}`]"
                                                :disabled="n === 4 || (isUnitLocked(n) && n > 1)"
                                                :invalid="!!getKonversiError(n)"
                                                :min="1"
                                                :locale="getLocale"
                                                :minFractionDigits="getQtyMinFractionDigits"
                                                :maxFractionDigits="getQtyMaxFractionDigits"
                                                placeholder="1"
                                                class="w-full"
                                            />
                                            <small v-if="getKonversiError(n)" class="text-red-500 text-xs">{{ getKonversiError(n) }}</small>
                                        </div>
                                    </td>
                                    <td class="p-2">
                                        <div class="flex flex-col gap-1">
                                            <InputNumber
                                                v-select-on-focus
                                                v-model="produk[`harga_${n}`]"
                                                :disabled="isPriceReadonly(n)"
                                                :invalid="!!getHargaError(n)"
                                                :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                                :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                                :locale="getLocale"
                                                :minFractionDigits="currencySettings.decimalPlaces"
                                                :maxFractionDigits="currencySettings.decimalPlaces"
                                                class="w-full"
                                            />
                                            <small v-if="getHargaError(n)" class="text-red-500 text-xs">{{ getHargaError(n) }}</small>
                                        </div>
                                    </td>
                                    <td class="p-2">
                                        <Tag v-if="n === 4" value="BASE" severity="success" />
                                        <Tag v-else-if="isUnitLocked(n) && n > 1" value="LOCKED" severity="secondary" />
                                        <Tag v-else-if="n === 1" value="TERBESAR" severity="info" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-2 text-sm text-surface-500">
                        <i class="pi pi-info-circle mr-1"></i>
                        Konversi Unit 4 selalu = 1 (unit terkecil/base). Jika Konversi = 1, unit di bawahnya akan terkunci otomatis.
                    </div>
                </Fieldset>

                <!-- Stok (qty/retail) — serial: stok per-unit di modul Serial -->
                <Fieldset v-if="!produk.is_serial" legend="Stok">
                    <div class="flex flex-col gap-2 max-w-xs">
                        <label for="minimum_stok" class="font-medium">Minimum Stok</label>
                        <InputNumber v-select-on-focus id="minimum_stok" v-model="produk.minimum_stok" :min="0" :locale="getLocale" :minFractionDigits="getQtyMinFractionDigits" :maxFractionDigits="getQtyMaxFractionDigits" placeholder="0" />
                        <small class="text-surface-500">Dalam satuan terkecil ({{ produk.unit_4 || 'unit base' }})</small>
                    </div>
                </Fieldset>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveProduk" :loading="saving" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Produk"
            :loading="loadingDetail"
            width="700px"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <!-- Gambar & Info Dasar -->
                <div class="flex gap-4 mb-4">
                    <div v-if="detailData.gambar_url" class="shrink-0">
                        <img :src="detailData.gambar_url" alt="Gambar Produk" class="w-24 h-24 object-cover rounded-lg border" />
                    </div>
                    <div class="flex-1 grid grid-cols-2 gap-4">
                        <DetailItem label="Kode Produk" :value="detailData.kode_produk" />
                        <DetailItem label="Jenis" :value="detailData.is_serial ? 'Produk Serial' : 'Retail'" type="badge" :badge-severity="detailData.is_serial ? 'help' : 'secondary'" />
                        <DetailItem v-if="!detailData.is_serial" label="Barcode" :value="detailData.barcode" />
                        <div class="col-span-2">
                            <DetailItem label="Nama Produk" :value="detailData.nama_produk" />
                        </div>
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                    </div>
                </div>

                <Divider />

                <!-- Klasifikasi -->
                <h6 class="text-surface-600 font-medium mb-3">Klasifikasi</h6>
                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Brand" :value="detailData.brand?.nama_brand" />
                    <DetailItem label="Tipe" :value="detailData.tipe?.nama_tipe" />
                    <DetailItem label="Kategori" :value="detailData.kategori?.nama_kategori" />
                    <DetailItem label="Grup" :value="detailData.grup?.nama_grup" />
                </div>

                <!-- Produk Serial: harga/HPP/stok dilacak per unit -->
                <template v-if="detailData.is_serial">
                    <Divider />
                    <div class="p-4 rounded-lg bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 flex items-start gap-3">
                        <i class="pi pi-qrcode text-purple-500 text-xl mt-0.5"></i>
                        <div class="text-sm">
                            <div class="font-medium text-purple-700 dark:text-purple-300">Produk Serial</div>
                            <div class="text-surface-600 dark:text-surface-400">Harga jual, HPP, dan stok dilacak <strong>per unit (nomor seri)</strong> — bukan di level produk.</div>
                        </div>
                    </div>
                </template>

                <template v-else>
                    <Divider />

                    <!-- Satuan & Harga -->
                    <h6 class="text-surface-600 font-medium mb-3">Satuan & Harga</h6>
                    <DetailTable :data="unitPriceData" :columns="unitPriceColumns">
                        <template #unit_number="{ item }">
                            <span class="font-medium">Unit {{ item.unit_number }}</span>
                        </template>
                        <template #konversi="{ item }">{{ formatQty(item.konversi) }}</template>
                        <template #harga="{ item }">{{ formatCurrency(item.harga) }}</template>
                    </DetailTable>

                    <Divider />

                    <!-- Stok & HPP -->
                    <h6 class="text-surface-600 font-medium mb-3">Stok & HPP</h6>
                    <div class="grid grid-cols-2 gap-4">
                        <DetailItem label="Minimum Stok" :value="`${formatQty(detailData.minimum_stok || 0)} ${detailData.unit_4 || ''}`" />
                        <div v-if="canViewHpp" class="flex flex-col gap-1">
                            <span class="text-surface-500 text-sm">HPP (Harga Pokok)</span>
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ formatCurrency(detailData.avg_cost || 0) }}</span>
                                <span class="text-surface-500 text-sm">/ {{ detailData.unit_4 || 'unit' }}</span>
                                <Button icon="pi pi-chart-line" size="small" rounded outlined severity="info" @click="viewHppMovement(detailData.ulid)" v-tooltip.top="'Lihat Pergerakan HPP'" />
                            </div>
                        </div>
                    </div>

                    <!-- Stok per Gudang -->
                    <Divider />
                    <h6 class="text-surface-600 font-medium mb-3">Stok per Gudang</h6>
                    <DetailTable :data="stockPerWarehouseData" :columns="stockPerWarehouseColumns">
                        <template #warehouse="{ item }">
                            <div>
                                <span class="font-medium">{{ item.warehouse_name }}</span>
                                <div class="text-xs text-surface-500">{{ item.warehouse_code }}</div>
                            </div>
                        </template>
                        <template #qty="{ item }">
                            <span :class="{ 'text-red-500 font-semibold': item.qty === 0 }">
                                {{ formatQty(item.qty) }}
                            </span>
                            <span class="text-surface-500 ml-1">{{ detailData.unit_4 }}</span>
                        </template>
                        <template #status="{ item }">
                            <Tag :value="item.warehouse_status === 'active' ? 'Active' : 'Inactive'" :severity="item.warehouse_status === 'active' ? 'success' : 'secondary'" />
                        </template>
                        <template #action="{ item }">
                            <Button icon="pi pi-history" size="small" rounded text severity="info" @click="viewStockCard(item.warehouse_id)" v-tooltip.top="'Lihat Stock Card'" />
                        </template>
                    </DetailTable>
                </template>
            </template>
            <template #footer-extra>
                <Button label="Download PDF" icon="pi pi-file-pdf" severity="help" outlined :loading="exportingDetail" @click="downloadDetailPdf(detailData)" />
            </template>
        </DetailDialog>
    </div>
</template>

<script setup>
import { priceChangesApi } from '@/api';
import { useConfirm } from 'primevue/useconfirm';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { useNotification } from '@/composables/useNotification';

const notify = useNotification();
const confirm = useConfirm();
const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const { formatCurrency, formatQty, shouldUppercase, getPrimeDateFormatShort, toDateTimeString, now, parseDateTime, getLocale, currencySettings, getCurrencyMinFractionDigits, getCurrencyMaxFractionDigits, isPriceInputAuto } = useFormatters();

// Permissions
const canApprovePerm = computed(() => authStore.can('price-change.approve'));

// Price mode - use from useFormatters
const isAutoMode = isPriceInputAuto;

// Mode
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Perubahan Harga' : 'Buat Perubahan Harga'));

// Loading states
const loading = ref(false);
const saving = ref(false);
const approving = ref(false);
const isLoadingFormData = ref(false);

// Alasan options
const alasanOptions = [
    { value: 'PENYESUAIAN_PASAR', label: 'Penyesuaian Harga Pasar' },
    { value: 'KENAIKAN_BIAYA', label: 'Kenaikan Biaya Operasional' },
    { value: 'PROMO', label: 'Program Promo' },
    { value: 'KOREKSI_DATA', label: 'Koreksi Data' },
    { value: 'LAINNYA', label: 'Lainnya' }
];

// Form data - alasan moved to header level
const form = ref({
    tanggal_pengajuan: now(),
    tanggal_berlaku: now(),
    alasan: 'PENYESUAIAN_PASAR',
    notes: '',
    details: []
});

// Original data for edit mode
const originalData = ref(null);

// Errors
const errors = ref({});

// Product search
const productSuggestions = ref([]);
const loadingProducts = ref(false);

// Locked products with info
const lockedProductIds = ref([]);
const lockedProductsInfo = ref({});

// Other drafts info
const hasOtherDrafts = ref(false);
const otherDraftsCount = ref(0);
const otherDraftsList = ref([]);

onMounted(async () => {
    await Promise.all([loadLockedProducts(), loadOtherDrafts()]);
    if (isEdit.value) {
        await loadPriceChange();
    }
});

// Watch for route param changes (when navigating from create to edit)
watch(
    () => route.params.ulid,
    async (newUlid) => {
        if (newUlid) {
            await loadPriceChange();
        }
    }
);

// Handle product selection - same pattern as HppCorrectionFormPage
function onProductSelect(event, index) {
    const product = event.value || event;
    if (product && typeof product === 'object' && product.id) {
        // Set initial values from product
        const harga1 = parseFloat(product.harga_1) || 0;
        const harga2 = parseFloat(product.harga_2) || 0;
        const harga3 = parseFloat(product.harga_3) || 0;
        const harga4 = parseFloat(product.harga_4) || 0;

        form.value.details[index] = {
            ...form.value.details[index],
            product_id: product.id,
            product: product,
            harga_1_lama: harga1,
            harga_2_lama: harga2,
            harga_3_lama: harga3,
            harga_4_lama: harga4,
            harga_1_baru: harga1,
            harga_2_baru: harga2,
            harga_3_baru: harga3,
            harga_4_baru: harga4
        };

        // In manual mode, ensure locked prices are consistent
        if (!isAutoMode.value) {
            autoFillLockedPrices(index, 1);
        }
    }
}

// Recalculate prices for a detail row (AUTO mode)
function recalculatePrices(index) {
    const detail = form.value.details[index];
    if (!detail?.product) return;

    const product = detail.product;
    const konversi1 = parseFloat(product.konversi_1) || 1;
    const konversi2 = parseFloat(product.konversi_2) || 1;
    const konversi3 = parseFloat(product.konversi_3) || 1;
    const konversi4 = parseFloat(product.konversi_4) || 1;

    const pricePerBase = (detail.harga_1_baru || 0) / konversi1;

    form.value.details[index].harga_2_baru = Math.round(pricePerBase * konversi2 * 100) / 100;
    form.value.details[index].harga_3_baru = Math.round(pricePerBase * konversi3 * 100) / 100;
    form.value.details[index].harga_4_baru = Math.round(pricePerBase * konversi4 * 100) / 100;
}

// Handle harga_1_baru change for auto calculation
function onHarga1Change(index) {
    if (isAutoMode.value) {
        recalculatePrices(index);
    } else {
        // Manual mode: auto-copy to locked units
        autoFillLockedPrices(index, 1);
    }
}

// Handle harga_2_baru change for manual mode auto-copy
function onHarga2Change(index) {
    if (isAutoMode.value) return;
    autoFillLockedPrices(index, 2);
}

// Handle harga_3_baru change for manual mode auto-copy
function onHarga3Change(index) {
    if (isAutoMode.value) return;
    autoFillLockedPrices(index, 3);
}

// Determine lock point for a product (first konversi that = 1)
function getLockFrom(product) {
    if (!product) return null;
    const k1 = parseInt(product.konversi_1) || 1;
    const k2 = parseInt(product.konversi_2) || 1;
    const k3 = parseInt(product.konversi_3) || 1;

    if (k1 === 1) return 1;
    if (k2 === 1) return 2;
    if (k3 === 1) return 3;
    return null;
}

// Check if a specific price unit is locked (readonly)
function isPriceLocked(detail, unitNumber) {
    if (!detail?.product) return false;
    const lockFrom = getLockFrom(detail.product);
    if (lockFrom === null) return false;
    return unitNumber > lockFrom;
}

// Auto-fill locked prices when source price changes (MANUAL mode only)
function autoFillLockedPrices(index, sourceUnit) {
    const detail = form.value.details[index];
    if (!detail?.product) return;

    const lockFrom = getLockFrom(detail.product);
    if (lockFrom === null) return;

    // Only auto-fill if the sourceUnit is the lock source or before it
    if (sourceUnit > lockFrom) return;

    // Auto-fill locked units based on lock point
    if (lockFrom === 1) {
        // All units locked to harga_1
        form.value.details[index].harga_2_baru = detail.harga_1_baru;
        form.value.details[index].harga_3_baru = detail.harga_1_baru;
        form.value.details[index].harga_4_baru = detail.harga_1_baru;
    } else if (lockFrom === 2) {
        // Units 3, 4 locked to harga_2
        if (sourceUnit <= 2) {
            form.value.details[index].harga_3_baru = detail.harga_2_baru;
            form.value.details[index].harga_4_baru = detail.harga_2_baru;
        }
    } else if (lockFrom === 3) {
        // Unit 4 locked to harga_3
        if (sourceUnit <= 3) {
            form.value.details[index].harga_4_baru = detail.harga_3_baru;
        }
    }
}

async function loadLockedProducts() {
    try {
        const params = {};
        // In edit mode, exclude current document from lock check
        if (isEdit.value && route.params.ulid) {
            params.exclude_document_ulid = route.params.ulid;
        }

        const response = await priceChangesApi.getLockedProducts(params);
        if (response.data.success) {
            lockedProductIds.value = response.data.data.product_ids || [];
            lockedProductsInfo.value = response.data.data.locked_products || {};
        }
    } catch (error) {
        console.error('Failed to load locked products:', error);
        notify.apiError(error, 'Gagal load locked products');
    }
}

async function loadOtherDrafts() {
    try {
        const params = {};
        // In edit mode, exclude current document
        if (isEdit.value && route.params.ulid) {
            params.exclude_document_ulid = route.params.ulid;
        }

        const response = await priceChangesApi.hasOtherDrafts(params);
        if (response.data.success) {
            hasOtherDrafts.value = response.data.data.has_other_drafts;
            otherDraftsCount.value = response.data.data.count;
            otherDraftsList.value = response.data.data.drafts || [];
        }
    } catch (error) {
        console.error('Failed to load other drafts:', error);
        notify.apiError(error, 'Gagal load other drafts');
    }
}

async function loadPriceChange() {
    if (!route.params.ulid) return;

    loading.value = true;
    isLoadingFormData.value = true;

    try {
        const response = await priceChangesApi.get(route.params.ulid);
        if (response.data.success) {
            const priceChange = response.data.data.price_change;

            // Store original data
            originalData.value = priceChange;

            // Check if not draft, redirect back
            if (priceChange.status !== 'draft') {
                notify.cannotEdit('Perubahan harga');
                router.push({ name: 'master-price-change' });
                return;
            }

            // Get alasan from first detail (since it's now at header level)
            const firstAlasan = priceChange.details[0]?.alasan || 'PENYESUAIAN_PASAR';

            form.value = {
                tanggal_pengajuan: parseDateTime(priceChange.tanggal_pengajuan),
                tanggal_berlaku: parseDateTime(priceChange.tanggal_berlaku),
                alasan: firstAlasan,
                notes: priceChange.notes || '',
                details: priceChange.details.map((d) => ({
                    product_id: d.product_id,
                    product: d.product,
                    harga_1_lama: parseFloat(d.harga_1_lama),
                    harga_2_lama: parseFloat(d.harga_2_lama),
                    harga_3_lama: parseFloat(d.harga_3_lama),
                    harga_4_lama: parseFloat(d.harga_4_lama),
                    harga_1_baru: parseFloat(d.harga_1_baru),
                    harga_2_baru: parseFloat(d.harga_2_baru),
                    harga_3_baru: parseFloat(d.harga_3_baru),
                    harga_4_baru: parseFloat(d.harga_4_baru),
                    notes: d.notes || ''
                }))
            };

            // Reload locked products and other drafts excluding current document
            await Promise.all([loadLockedProducts(), loadOtherDrafts()]);
        }
    } catch (error) {
        console.error('Failed to load price change:', error);
        notify.loadListError('perubahan harga');
        router.push({ name: 'master-price-change' });
    } finally {
        loading.value = false;
        isLoadingFormData.value = false;
    }
}

async function searchProducts(event) {
    loadingProducts.value = true;
    try {
        const params = { search: event.query };

        // In edit mode, exclude current document from lock check
        if (isEdit.value && route.params.ulid) {
            params.exclude_document_ulid = route.params.ulid;
        }

        const response = await priceChangesApi.getProducts(params);
        if (response.data.success) {
            // Filter out products already in the form (but keep locked products - they'll be disabled)
            const existingProductIds = form.value.details.filter((d) => d.product_id).map((d) => d.product_id);

            productSuggestions.value = response.data.data.items.filter((p) => !existingProductIds.includes(p.id));
        }
    } catch (error) {
        console.error('Failed to search products:', error);
        productSuggestions.value = [];
    } finally {
        loadingProducts.value = false;
    }
}

function addDetail() {
    form.value.details.push({
        product_id: null,
        product: null,
        harga_1_lama: 0,
        harga_2_lama: 0,
        harga_3_lama: 0,
        harga_4_lama: 0,
        harga_1_baru: 0,
        harga_2_baru: 0,
        harga_3_baru: 0,
        harga_4_baru: 0,
        notes: ''
    });
}

function removeDetail(index) {
    form.value.details.splice(index, 1);
}

function calculateDifference(detail) {
    return (detail.harga_1_baru ?? 0) - (detail.harga_1_lama ?? 0);
}

// Untuk Harga Jual: naik = baik (hijau), turun = kurang baik (merah)
// Kebalikan dari HPP dimana naik = buruk
function getDifferenceClass(diff) {
    if (diff > 0) return 'text-green-600 font-medium'; // Harga naik = pendapatan naik = baik
    if (diff < 0) return 'text-red-600 font-medium'; // Harga turun = pendapatan turun = kurang baik
    return 'text-surface-500';
}

function formatDifference(diff) {
    if (diff === 0) return '-';
    if (diff > 0) return `+${formatCurrency(diff)}`;
    return formatCurrency(diff);
}

// Format konversi info
function getKonversiInfo(product) {
    if (!product) return '';
    const parts = [];
    if (product.unit_1) parts.push(`${product.unit_1}=${formatQty(product.konversi_1)}`);
    if (product.unit_2) parts.push(`${product.unit_2}=${formatQty(product.konversi_2)}`);
    if (product.unit_3) parts.push(`${product.unit_3}=${formatQty(product.konversi_3)}`);
    if (product.unit_4) parts.push(`${product.unit_4}=${formatQty(product.konversi_4)}`);
    return parts.join(' | ');
}

// Validate manual mode prices for a single detail
function validateManualPrices(detail) {
    if (!detail.product || isAutoMode.value) return null;

    const product = detail.product;
    const harga1 = detail.harga_1_baru || 0;
    const harga2 = detail.harga_2_baru || 0;
    const harga3 = detail.harga_3_baru || 0;
    const harga4 = detail.harga_4_baru || 0;

    const konversi1 = parseInt(product.konversi_1) || 1;
    const konversi2 = parseInt(product.konversi_2) || 1;
    const konversi3 = parseInt(product.konversi_3) || 1;

    // Determine lock point (first konversi that = 1)
    let lockFrom = null;
    if (konversi1 === 1) lockFrom = 1;
    else if (konversi2 === 1) lockFrom = 2;
    else if (konversi3 === 1) lockFrom = 3;

    // Calculate PPU
    const ppu1 = konversi1 > 0 ? harga1 / konversi1 : 0;
    const ppu2 = konversi2 > 0 ? harga2 / konversi2 : 0;
    const ppu3 = konversi3 > 0 ? harga3 / konversi3 : 0;
    const ppu4 = harga4;

    // Check harga_2 vs harga_1
    if (harga1 > 0 && harga2 > 0) {
        if (lockFrom === 1) {
            if (Math.abs(harga2 - harga1) > 0.01) {
                return { field: 'harga_2', message: `Harga ${product.unit_2} harus sama dengan ${product.unit_1} (locked)` };
            }
        } else {
            if (harga2 >= harga1) {
                return { field: 'harga_2', message: `Harga ${product.unit_2} harus < ${formatCurrency(harga1)}` };
            }
            if (ppu2 < ppu1) {
                return { field: 'harga_2', message: `PPU ${product.unit_2} terlalu murah (${formatCurrency(Math.round(ppu2))}/unit)` };
            }
        }
    }

    // Check harga_3 vs harga_2
    if (harga2 > 0 && harga3 > 0) {
        if (lockFrom !== null && lockFrom <= 2) {
            const lockSourceHarga = lockFrom === 1 ? harga1 : harga2;
            if (Math.abs(harga3 - lockSourceHarga) > 0.01) {
                return { field: 'harga_3', message: `Harga ${product.unit_3} harus sama dengan Unit ${lockFrom} (locked)` };
            }
        } else {
            if (harga3 >= harga2) {
                return { field: 'harga_3', message: `Harga ${product.unit_3} harus < ${formatCurrency(harga2)}` };
            }
            if (ppu3 < ppu2) {
                return { field: 'harga_3', message: `PPU ${product.unit_3} terlalu murah (${formatCurrency(Math.round(ppu3))}/unit)` };
            }
        }
    }

    // Check harga_4 vs harga_3
    if (harga3 > 0 && harga4 > 0) {
        if (lockFrom !== null && lockFrom <= 3) {
            const lockSourceHarga = lockFrom === 1 ? harga1 : lockFrom === 2 ? harga2 : harga3;
            if (Math.abs(harga4 - lockSourceHarga) > 0.01) {
                return { field: 'harga_4', message: `Harga ${product.unit_4} harus sama dengan Unit ${lockFrom} (locked)` };
            }
        } else {
            if (harga4 >= harga3) {
                return { field: 'harga_4', message: `Harga ${product.unit_4} harus < ${formatCurrency(harga3)}` };
            }
            if (ppu4 < ppu3) {
                return { field: 'harga_4', message: `PPU ${product.unit_4} terlalu murah (${formatCurrency(Math.round(ppu4))}/unit)` };
            }
        }
    }

    return null;
}

// Get price validation error for a detail (for inline display)
function getPriceError(detail, field) {
    const error = validateManualPrices(detail);
    if (error && error.field === field) {
        return error.message;
    }
    return null;
}

function validate() {
    errors.value = {};
    let isValid = true;

    if (!form.value.tanggal_pengajuan) {
        errors.value.tanggal_pengajuan = 'Tanggal pengajuan wajib diisi';
        isValid = false;
    }

    if (!form.value.tanggal_berlaku) {
        errors.value.tanggal_berlaku = 'Tanggal berlaku wajib diisi';
        isValid = false;
    }

    if (!form.value.alasan) {
        errors.value.alasan = 'Alasan wajib dipilih';
        isValid = false;
    }

    if (form.value.alasan === 'LAINNYA' && !form.value.notes) {
        errors.value.notes = 'Catatan wajib diisi jika alasan "Lainnya"';
        isValid = false;
    }

    if (form.value.details.length === 0) {
        errors.value.details = 'Minimal harus ada 1 detail produk';
        isValid = false;
    }

    // Validate each detail
    form.value.details.forEach((detail, index) => {
        if (!detail.product_id) {
            errors.value[`details.${index}.product_id`] = 'Produk wajib dipilih';
            isValid = false;
        }
        if (!detail.harga_1_baru || detail.harga_1_baru <= 0) {
            errors.value[`details.${index}.harga_1_baru`] = 'Harga 1 harus lebih dari 0';
            isValid = false;
        }

        // Validate manual mode prices
        if (!isAutoMode.value && detail.product) {
            const priceError = validateManualPrices(detail);
            if (priceError) {
                errors.value[`details.${index}.${priceError.field}`] = priceError.message;
                isValid = false;
            }
        }
    });

    // Check for duplicate products
    const productIds = form.value.details.filter((d) => d.product_id).map((d) => d.product_id);
    if (new Set(productIds).size !== productIds.length) {
        errors.value.details = 'Tidak boleh ada produk yang sama';
        isValid = false;
    }

    return isValid;
}

async function save() {
    if (!validate()) {
        notify.formInvalid();
        return;
    }

    saving.value = true;
    try {
        const payload = {
            tanggal_pengajuan: toDateTimeString(form.value.tanggal_pengajuan),
            tanggal_berlaku: toDateTimeString(form.value.tanggal_berlaku),
            notes: form.value.notes || null,
            details: form.value.details.map((d) => {
                const detail = {
                    product_id: d.product_id,
                    harga_1_baru: d.harga_1_baru,
                    alasan: form.value.alasan, // Use header-level alasan
                    notes: d.notes || null
                };

                // Include other prices only in manual mode
                if (!isAutoMode.value) {
                    detail.harga_2_baru = d.harga_2_baru;
                    detail.harga_3_baru = d.harga_3_baru;
                    detail.harga_4_baru = d.harga_4_baru;
                }

                return detail;
            })
        };

        let response;
        if (isEdit.value) {
            response = await priceChangesApi.update(route.params.ulid, payload);
        } else {
            response = await priceChangesApi.create(payload);
        }

        if (response.data.success) {
            notify.success(response.data.message || 'Perubahan harga berhasil disimpan');

            // Navigate to edit mode if was creating
            if (!isEdit.value) {
                router.replace({
                    name: 'master-price-change-edit',
                    params: { ulid: response.data.data.price_change.ulid }
                });
            } else {
                // Reload to get fresh data
                await loadPriceChange();
            }
        }
    } catch (error) {
        console.error('Failed to save:', error);
        console.error('Response data:', error.response?.data);
        console.error('Validation errors:', error.response?.data?.errors);

        if (error.response?.data?.errors) {
            errors.value = error.response.data.errors;
        }

        notify.saveError(error);
    } finally {
        saving.value = false;
    }
}

function confirmApprove() {
    confirm.require({
        message: 'Approve perubahan harga ini? Dokumen akan masuk status "Scheduled" dan harga akan berubah sesuai tanggal berlaku.',
        header: 'Konfirmasi Approve',
        icon: 'pi pi-check-circle',
        acceptClass: 'p-button-success',
        acceptLabel: 'Approve',
        rejectLabel: 'Batal',
        accept: async () => {
            approving.value = true;
            try {
                const response = await priceChangesApi.approve(route.params.ulid);
                if (response.data.success) {
                    notify.approved('Perubahan harga');
                    router.push({ name: 'master-price-change' });
                }
            } catch (error) {
                console.error('Failed to approve:', error);
                notify.approveError(error);
            } finally {
                approving.value = false;
            }
        }
    });
}

function goBack() {
    router.push({ name: 'master-price-change' });
}

function getProductLabel(product) {
    if (!product) return '';
    return `${product.kode_produk} - ${product.nama_produk}`;
}

// Check if product is locked (in other draft/scheduled)
// Now uses locked_by from product object returned by API
function isProductLocked(product) {
    if (!product) return false;
    // API returns locked_by with document info if locked
    return product.locked_by !== null && product.locked_by !== undefined;
}

// Get lock info for a product
function getProductLockInfo(product) {
    if (!product?.locked_by) return null;
    return product.locked_by;
}

// Summary computed
const summary = computed(() => {
    const total = form.value.details.filter((d) => d.product_id).length;
    return { total };
});
</script>

<template>
    <div class="card">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <Button icon="pi pi-arrow-left" text rounded @click="goBack" v-tooltip.top="'Kembali'" aria-label="Kembali" />
                <span class="text-xl font-semibold">{{ pageTitle }}</span>
                <Tag v-if="originalData" :value="originalData.nomor_dokumen" severity="info" />
                <Tag v-if="isAutoMode" value="Auto Mode" severity="secondary" class="ml-2" />
                <Tag v-else value="Manual Mode" severity="warn" class="ml-2" />
            </div>
            <div class="flex gap-2">
                <Button v-if="isEdit && canApprovePerm" label="Approve" icon="pi pi-check" severity="success" :loading="approving" @click="confirmApprove" />
                <Button label="Simpan" icon="pi pi-save" :loading="saving" @click="save" />
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-8">
            <ProgressSpinner style="width: 50px; height: 50px" />
        </div>

        <div v-else>
            <!-- Info Banner: Other Drafts -->
            <Message v-if="hasOtherDrafts" severity="info" :closable="false" class="mb-4">
                <div class="flex items-start gap-2">
                    <i class="pi pi-info-circle mt-0.5"></i>
                    <div>
                        <div class="font-medium">Terdapat {{ otherDraftsCount }} dokumen perubahan harga lain yang belum selesai</div>
                        <div class="text-sm mt-1">Beberapa produk mungkin tidak dapat dipilih karena sudah tercatat di dokumen lain. Produk yang terkunci akan ditampilkan dengan label merah pada dropdown.</div>
                        <div v-if="otherDraftsList.length > 0" class="text-sm mt-2">
                            <span class="text-surface-500">Dokumen aktif: </span>
                            <span v-for="(draft, idx) in otherDraftsList" :key="draft.ulid">
                                <router-link :to="{ name: 'master-price-change-edit', params: { ulid: draft.ulid } }" class="text-primary hover:underline">{{ draft.nomor_dokumen }}</router-link>
                                <span v-if="idx < otherDraftsList.length - 1">, </span>
                            </span>
                        </div>
                    </div>
                </div>
            </Message>

            <!-- Form Header -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="flex flex-col gap-2">
                    <label class="font-medium">Tanggal Pengajuan <span class="text-red-500">*</span></label>
                    <DatePicker v-model="form.tanggal_pengajuan" showTime hourFormat="24" :dateFormat="getPrimeDateFormatShort" showIcon class="w-full" :class="{ 'p-invalid': errors.tanggal_pengajuan }" />
                    <small v-if="errors.tanggal_pengajuan" class="text-red-500">{{ errors.tanggal_pengajuan }}</small>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="font-medium">Tanggal Berlaku <span class="text-red-500">*</span></label>
                    <DatePicker v-model="form.tanggal_berlaku" showTime hourFormat="24" :dateFormat="getPrimeDateFormatShort" showIcon class="w-full" :class="{ 'p-invalid': errors.tanggal_berlaku }" />
                    <small v-if="errors.tanggal_berlaku" class="text-red-500">{{ errors.tanggal_berlaku }}</small>
                    <small class="text-surface-500">Harga akan berlaku mulai tanggal ini</small>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="font-medium">Alasan <span class="text-red-500">*</span></label>
                    <Select v-model="form.alasan" :options="alasanOptions" optionLabel="label" optionValue="value" placeholder="Pilih alasan" class="w-full" filter :class="{ 'p-invalid': errors.alasan }" />
                    <small v-if="errors.alasan" class="text-red-500">{{ errors.alasan }}</small>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="font-medium">Catatan {{ form.alasan === 'LAINNYA' ? '*' : '' }}</label>
                    <Textarea
                        v-model="form.notes"
                        rows="2"
                        class="w-full"
                        :placeholder="form.alasan === 'LAINNYA' ? 'Wajib diisi jika alasan Lainnya...' : 'Catatan umum...'"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        :class="{ 'p-invalid': errors.notes }"
                    />
                    <small v-if="errors.notes" class="text-red-500">{{ errors.notes }}</small>
                </div>
            </div>

            <!-- Details Section -->
            <div class="border border-surface-200 rounded-lg p-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium m-0">Detail Produk</h3>
                    <Button label="Tambah" icon="pi pi-plus" size="small" @click="addDetail" />
                </div>

                <small v-if="errors.details" class="text-red-500 block mb-4">{{ errors.details }}</small>

                <!-- Summary -->
                <div v-if="form.details.length > 0" class="flex flex-wrap gap-4 mb-4 text-sm">
                    <span
                        >Total: <strong>{{ summary.total }}</strong> produk</span
                    >
                </div>

                <!-- Detail Table -->
                <DataTable :value="form.details" class="p-datatable-sm" responsiveLayout="scroll" scrollable>
                    <template #empty>
                        <div class="text-center py-4 text-surface-500">Klik "Tambah" untuk menambahkan produk</div>
                    </template>

                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>

                    <Column header="Produk" style="min-width: 280px">
                        <template #body="{ data, index }">
                            <AutoComplete
                                v-model="data.product"
                                :suggestions="productSuggestions"
                                :optionLabel="getProductLabel"
                                :optionDisabled="isProductLocked"
                                placeholder="Cari produk..."
                                :loading="loadingProducts"
                                class="w-full"
                                :class="{ 'p-invalid': errors[`details.${index}.product_id`] }"
                                @complete="searchProducts"
                                @item-select="(e) => onProductSelect(e, index)"
                                dropdown
                            >
                                <template #option="{ option }">
                                    <div class="flex flex-col py-1" :class="{ 'opacity-60': isProductLocked(option) }">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium">{{ option.kode_produk }}</span>
                                            <Tag v-if="isProductLocked(option)" value="Terkunci" severity="danger" class="text-xs" />
                                        </div>
                                        <span class="text-sm text-surface-500">{{ option.nama_produk }}</span>
                                        <span class="text-xs text-surface-400">Harga: {{ formatCurrency(option.harga_1) }}</span>
                                        <!-- Show which draft locks this product -->
                                        <span v-if="isProductLocked(option)" class="text-xs text-red-500 mt-1">
                                            <i class="pi pi-lock mr-1"></i>
                                            Tercatat di {{ getProductLockInfo(option)?.nomor_dokumen }}
                                        </span>
                                    </div>
                                </template>
                            </AutoComplete>
                            <!-- Show konversi info below product -->
                            <div v-if="data.product" class="text-xs text-surface-400 mt-1">
                                {{ getKonversiInfo(data.product) }}
                            </div>
                            <small v-if="errors[`details.${index}.product_id`]" class="text-red-500">
                                {{ errors[`details.${index}.product_id`] }}
                            </small>
                        </template>
                    </Column>

                    <Column header="Harga 1 Baru" style="width: 160px">
                        <template #body="{ data, index }">
                            <InputNumber
                                v-select-on-focus
                                v-model="data.harga_1_baru"
                                :min="0.01"
                                :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                :locale="getLocale"
                                :minFractionDigits="getCurrencyMinFractionDigits"
                                :maxFractionDigits="getCurrencyMaxFractionDigits"
                                class="w-full"
                                :class="{ 'p-invalid': errors[`details.${index}.harga_1_baru`] }"
                                @update:modelValue="() => onHarga1Change(index)"
                            />
                            <div class="text-xs text-surface-400 mt-1" v-if="data.product">{{ data.product.unit_1 }} ({{ formatQty(data.product.konversi_1) }}) - {{ formatCurrency(data.harga_1_lama) }}</div>
                        </template>
                    </Column>

                    <!-- Show harga 2, 3, 4 in auto mode as readonly with konversi -->
                    <Column v-if="isAutoMode" header="Harga Unit Lain (Auto)" style="width: 220px">
                        <template #body="{ data }">
                            <div class="text-xs space-y-1" v-if="data.product">
                                <div class="flex justify-between">
                                    <span class="text-surface-500">{{ data.product.unit_2 }} ({{ formatQty(data.product.konversi_2) }}):</span>
                                    <span class="font-medium">{{ formatCurrency(data.harga_2_baru) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-surface-500">{{ data.product.unit_3 }} ({{ formatQty(data.product.konversi_3) }}):</span>
                                    <span class="font-medium">{{ formatCurrency(data.harga_3_baru) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-surface-500">{{ data.product.unit_4 }} ({{ formatQty(data.product.konversi_4) }}):</span>
                                    <span class="font-medium">{{ formatCurrency(data.harga_4_baru) }}</span>
                                </div>
                            </div>
                            <div v-else class="text-xs text-surface-400">-</div>
                        </template>
                    </Column>

                    <!-- Show harga 2, 3, 4 as inputs in manual mode -->
                    <Column v-if="!isAutoMode" header="Harga 2 Baru" style="width: 150px">
                        <template #body="{ data, index }">
                            <div class="relative">
                                <InputNumber
                                    v-select-on-focus
                                    v-model="data.harga_2_baru"
                                    :min="0"
                                    :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                    :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                    :locale="getLocale"
                                    :minFractionDigits="getCurrencyMinFractionDigits"
                                    :maxFractionDigits="getCurrencyMaxFractionDigits"
                                    class="w-full"
                                    :class="{ 'p-invalid': getPriceError(data, 'harga_2') || errors[`details.${index}.harga_2`] }"
                                    :disabled="isPriceLocked(data, 2)"
                                    @update:modelValue="() => onHarga2Change(index)"
                                />
                                <i v-if="isPriceLocked(data, 2)" class="pi pi-lock absolute right-2 top-1/2 -translate-y-1/2 text-surface-400 text-xs"></i>
                            </div>
                            <div class="text-xs mt-1" :class="isPriceLocked(data, 2) ? 'text-orange-500' : 'text-surface-400'" v-if="data.product && !getPriceError(data, 'harga_2')">
                                {{ data.product.unit_2 }} ({{ formatQty(data.product.konversi_2) }}) - {{ formatCurrency(data.harga_2_lama) }}
                                <i v-if="isPriceLocked(data, 2)" class="pi pi-lock text-orange-500 ml-1"></i>
                            </div>
                            <small v-if="getPriceError(data, 'harga_2')" class="text-red-500 text-xs">
                                {{ getPriceError(data, 'harga_2') }}
                            </small>
                        </template>
                    </Column>

                    <Column v-if="!isAutoMode" header="Harga 3 Baru" style="width: 150px">
                        <template #body="{ data, index }">
                            <div class="relative">
                                <InputNumber
                                    v-select-on-focus
                                    v-model="data.harga_3_baru"
                                    :min="0"
                                    :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                    :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                    :locale="getLocale"
                                    :minFractionDigits="getCurrencyMinFractionDigits"
                                    :maxFractionDigits="getCurrencyMaxFractionDigits"
                                    class="w-full"
                                    :class="{ 'p-invalid': getPriceError(data, 'harga_3') || errors[`details.${index}.harga_3`] }"
                                    :disabled="isPriceLocked(data, 3)"
                                    @update:modelValue="() => onHarga3Change(index)"
                                />
                                <i v-if="isPriceLocked(data, 3)" class="pi pi-lock absolute right-2 top-1/2 -translate-y-1/2 text-surface-400 text-xs"></i>
                            </div>
                            <div class="text-xs mt-1" :class="isPriceLocked(data, 3) ? 'text-orange-500' : 'text-surface-400'" v-if="data.product && !getPriceError(data, 'harga_3')">
                                {{ data.product.unit_3 }} ({{ formatQty(data.product.konversi_3) }}) - {{ formatCurrency(data.harga_3_lama) }}
                                <i v-if="isPriceLocked(data, 3)" class="pi pi-lock text-orange-500 ml-1"></i>
                            </div>
                            <small v-if="getPriceError(data, 'harga_3')" class="text-red-500 text-xs">
                                {{ getPriceError(data, 'harga_3') }}
                            </small>
                        </template>
                    </Column>

                    <Column v-if="!isAutoMode" header="Harga 4 Baru" style="width: 150px">
                        <template #body="{ data, index }">
                            <div class="relative">
                                <InputNumber
                                    v-select-on-focus
                                    v-model="data.harga_4_baru"
                                    :min="0"
                                    :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                    :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                    :locale="getLocale"
                                    :minFractionDigits="getCurrencyMinFractionDigits"
                                    :maxFractionDigits="getCurrencyMaxFractionDigits"
                                    class="w-full"
                                    :class="{ 'p-invalid': getPriceError(data, 'harga_4') || errors[`details.${index}.harga_4`] }"
                                    :disabled="isPriceLocked(data, 4)"
                                />
                                <i v-if="isPriceLocked(data, 4)" class="pi pi-lock absolute right-2 top-1/2 -translate-y-1/2 text-surface-400 text-xs"></i>
                            </div>
                            <div class="text-xs mt-1" :class="isPriceLocked(data, 4) ? 'text-orange-500' : 'text-surface-400'" v-if="data.product && !getPriceError(data, 'harga_4')">
                                {{ data.product.unit_4 }} ({{ formatQty(data.product.konversi_4) }}) - {{ formatCurrency(data.harga_4_lama) }}
                                <i v-if="isPriceLocked(data, 4)" class="pi pi-lock text-orange-500 ml-1"></i>
                            </div>
                            <small v-if="getPriceError(data, 'harga_4')" class="text-red-500 text-xs">
                                {{ getPriceError(data, 'harga_4') }}
                            </small>
                        </template>
                    </Column>

                    <Column header="Selisih" style="width: 120px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span :class="getDifferenceClass(calculateDifference(data))">
                                {{ formatDifference(calculateDifference(data)) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Notes" style="min-width: 150px">
                        <template #body="{ data }">
                            <InputText v-model="data.notes" class="w-full" placeholder="Catatan item..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                        </template>
                    </Column>

                    <Column header="" style="width: 50px">
                        <template #body="{ index }">
                            <Button icon="pi pi-trash" severity="danger" text rounded @click="removeDetail(index)" />
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>

<style scoped>
:deep(.p-autocomplete-input) {
    width: 100%;
}
</style>

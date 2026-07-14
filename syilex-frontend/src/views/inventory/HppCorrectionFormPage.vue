<script setup>
import { hppCorrectionsApi } from '@/api';
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
const { formatCurrency, shouldUppercase, getPrimeDateFormatShort, toDateTimeString, now, parseDateTime, getLocale, currencySettings, getCurrencyMinFractionDigits, getCurrencyMaxFractionDigits } = useFormatters();

// Permissions
const canApprove = computed(() => authStore.can('hpp.approve'));

// Mode
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Koreksi HPP' : 'Buat Koreksi HPP'));

// Loading states
const loading = ref(false);
const saving = ref(false);
const approving = ref(false);
const isLoadingFormData = ref(false);

// Form data
const form = ref({
    tanggal_koreksi: now(),
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

// Alasan options
const alasanOptions = [
    { value: 'KOREKSI_HARGA_BELI', label: 'Koreksi Harga Beli' },
    { value: 'KOREKSI_DATA', label: 'Koreksi Data' },
    { value: 'MIGRASI_SISTEM', label: 'Migrasi Sistem' },
    { value: 'LAINNYA', label: 'Lainnya' }
];

onMounted(async () => {
    if (isEdit.value) {
        await loadCorrection();
    }
});

// Watch for route param changes (when navigating from create to edit)
watch(
    () => route.params.ulid,
    async (newUlid) => {
        if (newUlid) {
            await loadCorrection();
        }
    }
);

async function loadCorrection() {
    if (!route.params.ulid) return;

    loading.value = true;
    isLoadingFormData.value = true;

    try {
        const response = await hppCorrectionsApi.get(route.params.ulid);
        if (response.data.success) {
            const correction = response.data.data.correction;

            // Check if not draft, redirect back
            if (correction.status !== 'draft') {
                notify.cannotEdit('Koreksi HPP');
                router.push({ name: 'inventory-hpp-correction' });
                return;
            }

            form.value = {
                tanggal_koreksi: parseDateTime(correction.tanggal_koreksi),
                notes: correction.notes || '',
                details: correction.details.map((d) => ({
                    product_id: d.product_id,
                    product: d.product,
                    hpp_lama: parseFloat(d.hpp_lama),
                    hpp_baru: parseFloat(d.hpp_baru),
                    alasan: d.alasan,
                    notes: d.notes || ''
                }))
            };

            originalData.value = correction;
        }
    } catch (error) {
        console.error('Failed to load correction:', error);
        notify.loadListError('koreksi HPP');
        router.push({ name: 'inventory-hpp-correction' });
    } finally {
        loading.value = false;
        isLoadingFormData.value = false;
    }
}

async function searchProducts(event) {
    loadingProducts.value = true;
    try {
        const response = await hppCorrectionsApi.getProducts({ search: event.query });
        if (response.data.success) {
            // Filter out products already in the form
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

function onProductSelect(event, index) {
    const product = event.value || event;
    if (product && typeof product === 'object' && product.id) {
        const hppLama = product.avg_cost ?? 0;

        form.value.details[index] = {
            ...form.value.details[index],
            product_id: product.id,
            product: product,
            hpp_lama: hppLama,
            hpp_baru: hppLama, // Default to current HPP
            alasan: form.value.details[index].alasan || 'KOREKSI_DATA'
        };
    }
}

function addDetail() {
    form.value.details.push({
        product_id: null,
        product: null,
        hpp_lama: 0,
        hpp_baru: 0,
        alasan: 'KOREKSI_DATA',
        notes: ''
    });
}

function removeDetail(index) {
    form.value.details.splice(index, 1);
}

function calculateDifference(detail) {
    return (detail.hpp_baru ?? 0) - (detail.hpp_lama ?? 0);
}

function getDifferenceClass(diff) {
    if (diff > 0) return 'text-red-600 font-medium';
    if (diff < 0) return 'text-green-600 font-medium';
    return 'text-surface-500';
}

function formatDifference(diff) {
    if (diff === 0) return '-';
    if (diff > 0) return `+${formatCurrency(diff)}`;
    return formatCurrency(diff);
}

function validate() {
    errors.value = {};
    let isValid = true;

    if (!form.value.tanggal_koreksi) {
        errors.value.tanggal_koreksi = 'Tanggal koreksi wajib diisi';
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
        if (!detail.hpp_baru || detail.hpp_baru <= 0) {
            errors.value[`details.${index}.hpp_baru`] = 'HPP Baru harus lebih dari 0';
            isValid = false;
        }
        if (!detail.alasan) {
            errors.value[`details.${index}.alasan`] = 'Alasan wajib dipilih';
            isValid = false;
        }
        if (detail.alasan === 'LAINNYA' && !detail.notes) {
            errors.value[`details.${index}.notes`] = 'Notes wajib diisi jika alasan "Lainnya"';
            isValid = false;
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
            tanggal_koreksi: toDateTimeString(form.value.tanggal_koreksi),
            notes: form.value.notes || null,
            details: form.value.details.map((d) => ({
                product_id: d.product_id,
                hpp_baru: d.hpp_baru,
                alasan: d.alasan,
                notes: d.notes || null
            }))
        };

        let response;
        if (isEdit.value) {
            response = await hppCorrectionsApi.update(route.params.ulid, payload);
        } else {
            response = await hppCorrectionsApi.create(payload);
        }

        if (response.data.success) {
            notify.success(response.data.message || 'Koreksi HPP berhasil disimpan');

            // Navigate to edit mode if was creating
            if (!isEdit.value) {
                router.replace({
                    name: 'inventory-hpp-correction-edit',
                    params: { ulid: response.data.data.correction.ulid }
                });
            } else {
                // Reload to get fresh data
                await loadCorrection();
            }
        }
    } catch (error) {
        console.error('Failed to save:', error);

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
        message: 'Approve koreksi HPP ini? HPP produk akan langsung berubah.',
        header: 'Konfirmasi Approve',
        icon: 'pi pi-check-circle',
        acceptClass: 'p-button-success',
        acceptLabel: 'Approve',
        rejectLabel: 'Batal',
        accept: async () => {
            approving.value = true;
            try {
                const response = await hppCorrectionsApi.approve(route.params.ulid);
                if (response.data.success) {
                    notify.approved('Koreksi HPP');
                    router.push({ name: 'inventory-hpp-correction' });
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
    router.push({ name: 'inventory-hpp-correction' });
}

function getProductLabel(product) {
    if (!product) return '';
    return `${product.kode_produk} - ${product.nama_produk}`;
}

// Summary computed
const summary = computed(() => {
    const total = form.value.details.filter((d) => d.product_id).length;
    let totalSelisih = 0;

    form.value.details.forEach((d) => {
        if (d.product_id) {
            totalSelisih += calculateDifference(d);
        }
    });

    return { total, totalSelisih };
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
            </div>
            <div class="flex gap-2">
                <Button v-if="isEdit && canApprove" label="Approve" icon="pi pi-check" severity="success" :loading="approving" @click="confirmApprove" />
                <Button label="Simpan" icon="pi pi-save" :loading="saving" @click="save" />
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-8">
            <ProgressSpinner style="width: 50px; height: 50px" />
        </div>

        <div v-else>
            <!-- Form Header -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div class="flex flex-col gap-2">
                    <label class="font-medium">Tanggal Koreksi <span class="text-red-500">*</span></label>
                    <DatePicker v-model="form.tanggal_koreksi" showTime hourFormat="24" :dateFormat="getPrimeDateFormatShort" :maxDate="new Date()" showIcon class="w-full" :class="{ 'p-invalid': errors.tanggal_koreksi }" />
                    <small v-if="errors.tanggal_koreksi" class="text-red-500">{{ errors.tanggal_koreksi }}</small>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="font-medium">Catatan</label>
                    <Textarea v-model="form.notes" rows="2" class="w-full" placeholder="Catatan umum..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
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
                    <span :class="summary.totalSelisih > 0 ? 'text-red-600' : summary.totalSelisih < 0 ? 'text-green-600' : ''">
                        Total Selisih: <strong>{{ formatDifference(summary.totalSelisih) }}</strong>
                    </span>
                </div>

                <!-- Detail Table -->
                <DataTable :value="form.details" class="p-datatable-sm" responsiveLayout="scroll" scrollable>
                    <template #empty>
                        <div class="text-center py-4 text-surface-500">Klik "Tambah" untuk menambahkan produk</div>
                    </template>

                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>

                    <Column header="Produk" style="min-width: 250px">
                        <template #body="{ data, index }">
                            <AutoComplete
                                v-model="data.product"
                                :suggestions="productSuggestions"
                                :optionLabel="getProductLabel"
                                placeholder="Cari produk..."
                                :loading="loadingProducts"
                                class="w-full"
                                :class="{ 'p-invalid': errors[`details.${index}.product_id`] }"
                                @complete="searchProducts"
                                @item-select="(e) => onProductSelect(e, index)"
                                dropdown
                            >
                                <template #option="{ option }">
                                    <div class="flex flex-col">
                                        <span class="font-medium">{{ option.kode_produk }}</span>
                                        <span class="text-sm text-surface-500">{{ option.nama_produk }}</span>
                                        <span class="text-xs text-surface-400">HPP: {{ formatCurrency(option.avg_cost) }}</span>
                                    </div>
                                </template>
                            </AutoComplete>
                            <small v-if="errors[`details.${index}.product_id`]" class="text-red-500">
                                {{ errors[`details.${index}.product_id`] }}
                            </small>
                        </template>
                    </Column>

                    <Column header="HPP Lama" style="width: 130px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="text-surface-600">{{ formatCurrency(data.hpp_lama) }}</span>
                        </template>
                    </Column>

                    <Column header="HPP Baru" style="width: 150px">
                        <template #body="{ data, index }">
                            <InputNumber
                                v-select-on-focus
                                v-model="data.hpp_baru"
                                :min="0.0001"
                                :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                :locale="getLocale"
                                :minFractionDigits="getCurrencyMinFractionDigits"
                                :maxFractionDigits="getCurrencyMaxFractionDigits"
                                class="w-full"
                                :class="{ 'p-invalid': errors[`details.${index}.hpp_baru`] }"
                            />
                        </template>
                    </Column>

                    <Column header="Selisih" style="width: 120px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span :class="getDifferenceClass(calculateDifference(data))">
                                {{ formatDifference(calculateDifference(data)) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Alasan" style="width: 180px">
                        <template #body="{ data, index }">
                            <Select v-model="data.alasan" :options="alasanOptions" optionLabel="label" optionValue="value" placeholder="Pilih alasan" class="w-full" filter :class="{ 'p-invalid': errors[`details.${index}.alasan`] }" />
                        </template>
                    </Column>

                    <Column header="Notes" style="min-width: 150px">
                        <template #body="{ data, index }">
                            <InputText
                                v-model="data.notes"
                                class="w-full"
                                :placeholder="data.alasan === 'LAINNYA' ? 'Wajib diisi...' : 'Catatan...'"
                                :class="{ 'p-invalid': errors[`details.${index}.notes`] }"
                                :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                            />
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

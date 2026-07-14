<script setup>
import { pembayaranHutangsApi, suppliersApi } from '@/api';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed, watch } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const notify = useNotification();
const router = useRouter();
const route = useRoute();
const { formatCurrency, formatDateTime, shouldUppercase, getPrimeDateFormatShort, toDateString, now, parseDateTime, getLocale, currencySettings, getCurrencyMinFractionDigits, getCurrencyMaxFractionDigits } = useFormatters();

// Mode
const isEdit = computed(() => !!route.params.ulid);
const pageTitle = computed(() => (isEdit.value ? 'Edit Pembayaran Hutang' : 'Buat Pembayaran Hutang'));

// Data
const suppliers = ref([]);
const outstandingHutangs = ref([]);
const availableDeposits = ref([]);
const loading = ref(false);
const saving = ref(false);
const loadingHutangs = ref(false);
const loadingDeposits = ref(false);

// Form
const form = ref({
    tanggal: now(),
    supplier_id: null,
    metode_pembayaran: 'cash',
    no_referensi: '',
    bank_nama: '',
    bank_rekening: '',
    notes: '',
    // Details will be built from hutangPayments
    details: [],
    // Deposit usages will be built from depositUsages
    deposit_usages: []
});

// Track payments per hutang
const hutangPayments = ref({});

// Track deposit usages
const depositUsages = ref({});

// Metode Pembayaran Options
const metodeOptions = [
    { label: 'Cash', value: 'cash' },
    { label: 'Transfer', value: 'transfer' }
];

// Validation
const errors = ref({});

// Computed totals
const totalCashPayment = computed(() => {
    return Object.values(hutangPayments.value).reduce((sum, payment) => {
        return sum + (payment.cash || 0);
    }, 0);
});

const totalDepositPayment = computed(() => {
    return Object.values(hutangPayments.value).reduce((sum, payment) => {
        return sum + (payment.deposit || 0);
    }, 0);
});

const totalDepositUsed = computed(() => {
    return Object.values(depositUsages.value).reduce((sum, amount) => sum + (amount || 0), 0);
});

const totalPayment = computed(() => {
    return totalCashPayment.value + totalDepositPayment.value;
});

const depositMismatch = computed(() => {
    return Math.abs(totalDepositPayment.value - totalDepositUsed.value) > 0.01;
});

// Selected hutangs count
const selectedHutangsCount = computed(() => {
    return Object.values(hutangPayments.value).filter((p) => (p.cash || 0) + (p.deposit || 0) > 0).length;
});

// Selected deposits count
const selectedDepositsCount = computed(() => {
    return Object.values(depositUsages.value).filter((v) => v > 0).length;
});

// Total available deposit (sum of all sisa_deposit)
const totalAvailableDeposit = computed(() => {
    return availableDeposits.value.reduce((sum, d) => sum + (parseFloat(d.sisa_deposit) || 0), 0);
});

// Remaining deposit available (total - already allocated in form)
const remainingDepositAvailable = computed(() => {
    return totalAvailableDeposit.value - totalDepositPayment.value;
});

onMounted(async () => {
    await loadSuppliers();

    if (isEdit.value) {
        await loadPembayaran();
    }
});

async function loadSuppliers() {
    try {
        const response = await suppliersApi.getList();
        if (response.data.success) {
            suppliers.value = response.data.data.suppliers;
        }
    } catch (error) {
        console.error('Failed to load suppliers:', error);
        notify.apiError(error, 'Gagal load suppliers');
    }
}

async function loadPembayaran() {
    loading.value = true;
    try {
        const response = await pembayaranHutangsApi.get(route.params.ulid);
        if (response.data.success) {
            const pembayaran = response.data.data.pembayaran;

            if (pembayaran.status !== 'draft') {
                notify.cannotEditApproved('Pembayaran');
                router.push({ name: 'pembelian-pembayaran-hutang' });
                return;
            }

            form.value = {
                tanggal: parseDateTime(pembayaran.tanggal),
                supplier_id: pembayaran.supplier_id,
                metode_pembayaran: pembayaran.metode_pembayaran || 'cash',
                no_referensi: pembayaran.no_referensi || '',
                bank_nama: pembayaran.bank_nama || '',
                bank_rekening: pembayaran.bank_rekening || '',
                notes: pembayaran.notes || '',
                details: [],
                deposit_usages: []
            };

            // Load hutangs and deposits for this supplier
            await Promise.all([loadOutstandingHutangs(pembayaran.supplier_id), loadAvailableDeposits(pembayaran.supplier_id)]);

            // Reconstruct hutangPayments from details
            pembayaran.details?.forEach((detail) => {
                const hutangId = detail.hutang_id;
                if (!hutangPayments.value[hutangId]) {
                    hutangPayments.value[hutangId] = { cash: 0, deposit: 0 };
                }
                if (detail.sumber === 'cash') {
                    hutangPayments.value[hutangId].cash += parseFloat(detail.nominal_dibayar) || 0;
                } else {
                    hutangPayments.value[hutangId].deposit += parseFloat(detail.nominal_dibayar) || 0;
                }
            });

            // Reconstruct depositUsages from deposit_usages
            pembayaran.deposit_usages?.forEach((usage) => {
                depositUsages.value[usage.deposit_id] = parseFloat(usage.nominal_digunakan) || 0;
            });
        }
    } catch (error) {
        console.error('Failed to load pembayaran:', error);
        notify.loadListError('pembayaran hutang');
        router.push({ name: 'pembelian-pembayaran-hutang' });
    } finally {
        loading.value = false;
    }
}

// Watch supplier change to load hutangs and deposits
watch(
    () => form.value.supplier_id,
    async (newVal, oldVal) => {
        if (newVal && newVal !== oldVal) {
            // Reset payments when supplier changes
            hutangPayments.value = {};
            depositUsages.value = {};

            await Promise.all([loadOutstandingHutangs(newVal), loadAvailableDeposits(newVal)]);
        }
    }
);

async function loadOutstandingHutangs(supplierId) {
    if (!supplierId) return;

    loadingHutangs.value = true;
    try {
        const response = await pembayaranHutangsApi.getOutstandingHutangs({ supplier_id: supplierId });
        if (response.data.success) {
            outstandingHutangs.value = response.data.data.items;

            // Initialize payment tracking
            outstandingHutangs.value.forEach((hutang) => {
                if (!hutangPayments.value[hutang.id]) {
                    hutangPayments.value[hutang.id] = { cash: 0, deposit: 0 };
                }
            });
        }
    } catch (error) {
        console.error('Failed to load outstanding hutangs:', error);
        notify.apiError(error, 'Gagal load outstanding hutangs');
    } finally {
        loadingHutangs.value = false;
    }
}

async function loadAvailableDeposits(supplierId) {
    if (!supplierId) return;

    loadingDeposits.value = true;
    try {
        const response = await pembayaranHutangsApi.getAvailableDeposits({ supplier_id: supplierId });
        if (response.data.success) {
            availableDeposits.value = response.data.data.items;

            // Initialize deposit usage tracking
            availableDeposits.value.forEach((deposit) => {
                if (depositUsages.value[deposit.id] === undefined) {
                    depositUsages.value[deposit.id] = 0;
                }
            });
        }
    } catch (error) {
        console.error('Failed to load available deposits:', error);
        notify.apiError(error, 'Gagal load available deposits');
    } finally {
        loadingDeposits.value = false;
    }
}

// Get max payment for a hutang
function getMaxPayment(hutang) {
    return parseFloat(hutang.sisa_hutang) || 0;
}

// Get max deposit usage
function getMaxDepositUsage(deposit) {
    return parseFloat(deposit.sisa_deposit) || 0;
}

// Validate payment doesn't exceed sisa hutang
// changedField: 'cash' or 'deposit' - the field that was just edited
function validateHutangPayment(hutangId, changedField = 'cash') {
    const hutang = outstandingHutangs.value.find((h) => h.id === hutangId);
    if (!hutang) return;

    const payment = hutangPayments.value[hutangId];
    const total = (payment.cash || 0) + (payment.deposit || 0);
    const max = getMaxPayment(hutang);

    if (total > max) {
        // Adjust the field that was just changed to not exceed max
        const excess = total - max;
        if (changedField === 'deposit') {
            payment.deposit = Math.max(0, (payment.deposit || 0) - excess);
        } else {
            payment.cash = Math.max(0, (payment.cash || 0) - excess);
        }
    }
}

// Validate deposit usage doesn't exceed sisa deposit
function validateDepositUsage(depositId) {
    const deposit = availableDeposits.value.find((d) => d.id === depositId);
    if (!deposit) return;

    const usage = depositUsages.value[depositId] || 0;
    const max = getMaxDepositUsage(deposit);

    if (usage > max) {
        depositUsages.value[depositId] = max;
    }
}

function buildPayload() {
    // Build details from hutangPayments
    const details = [];
    Object.entries(hutangPayments.value).forEach(([hutangId, payment]) => {
        if (payment.cash > 0) {
            details.push({
                hutang_id: parseInt(hutangId),
                nominal_dibayar: payment.cash,
                sumber: 'cash'
            });
        }
        if (payment.deposit > 0) {
            details.push({
                hutang_id: parseInt(hutangId),
                nominal_dibayar: payment.deposit,
                sumber: 'deposit'
            });
        }
    });

    // Build deposit_usages from depositUsages
    const deposit_usages = [];
    Object.entries(depositUsages.value).forEach(([depositId, amount]) => {
        if (amount > 0) {
            deposit_usages.push({
                deposit_id: parseInt(depositId),
                nominal_digunakan: amount
            });
        }
    });

    return {
        tanggal: toDateString(form.value.tanggal),
        supplier_id: form.value.supplier_id,
        metode_pembayaran: form.value.metode_pembayaran,
        no_referensi: form.value.no_referensi || null,
        bank_nama: form.value.bank_nama || null,
        bank_rekening: form.value.bank_rekening || null,
        notes: form.value.notes || null,
        details,
        deposit_usages
    };
}

function validate() {
    errors.value = {};

    if (!form.value.supplier_id) {
        errors.value.supplier_id = 'Supplier wajib dipilih';
    }
    if (!form.value.tanggal) {
        errors.value.tanggal = 'Tanggal wajib diisi';
    }
    if (selectedHutangsCount.value === 0) {
        errors.value.details = 'Minimal harus ada 1 hutang yang dibayar';
    }

    // Check deposit mismatch
    if (totalDepositPayment.value > 0 && depositMismatch.value) {
        errors.value.deposit_usages = `Total deposit yang dialokasikan (${formatCurrency(totalDepositPayment.value)}) tidak sama dengan total deposit yang digunakan (${formatCurrency(totalDepositUsed.value)})`;
    }

    return Object.keys(errors.value).length === 0;
}

async function save() {
    if (!validate()) {
        notify.formInvalid();
        return;
    }

    saving.value = true;
    try {
        const payload = buildPayload();

        let response;
        if (isEdit.value) {
            response = await pembayaranHutangsApi.update(route.params.ulid, payload);
        } else {
            response = await pembayaranHutangsApi.create(payload);
        }

        if (response.data.success) {
            notify.saveSuccess('Pembayaran hutang', isEdit.value);
            router.push({ name: 'pembelian-pembayaran-hutang' });
        }
    } catch (error) {
        console.error('Failed to save pembayaran:', error);
        notify.saveError(error);

        if (error.response?.data?.errors) {
            errors.value = { ...errors.value, ...error.response.data.errors };
        }
    } finally {
        saving.value = false;
    }
}

function cancel() {
    router.push({ name: 'pembelian-pembayaran-hutang' });
}

// Pay all - set cash amount to sisa hutang for all
function payAllCash() {
    outstandingHutangs.value.forEach((hutang) => {
        const max = getMaxPayment(hutang);
        const currentDeposit = hutangPayments.value[hutang.id]?.deposit || 0;
        hutangPayments.value[hutang.id] = {
            cash: Math.max(0, max - currentDeposit),
            deposit: currentDeposit
        };
    });
}

// Reset all payments
function resetPayments() {
    Object.keys(hutangPayments.value).forEach((key) => {
        hutangPayments.value[key] = { cash: 0, deposit: 0 };
    });
    Object.keys(depositUsages.value).forEach((key) => {
        depositUsages.value[key] = 0;
    });
}

// Auto-allocate available deposits
function autoAllocateDeposits() {
    // Reset deposit allocations
    Object.keys(depositUsages.value).forEach((key) => {
        depositUsages.value[key] = 0;
    });

    // Calculate how much deposit is needed
    let remainingNeed = totalDepositPayment.value;

    // Allocate from each deposit in order
    for (const deposit of availableDeposits.value) {
        if (remainingNeed <= 0) break;

        const available = getMaxDepositUsage(deposit);
        const toAllocate = Math.min(available, remainingNeed);

        if (toAllocate > 0) {
            depositUsages.value[deposit.id] = toAllocate;
            remainingNeed -= toAllocate;
        }
    }
}

// Fill max cash for single hutang row
function fillMaxCash(hutangId) {
    const hutang = outstandingHutangs.value.find((h) => h.id === hutangId);
    if (!hutang) return;

    const max = getMaxPayment(hutang);
    const currentDeposit = hutangPayments.value[hutangId]?.deposit || 0;
    hutangPayments.value[hutangId].cash = Math.max(0, max - currentDeposit);
}

// Fill max deposit for single hutang row (sequential allocation)
function fillMaxDeposit(hutangId) {
    const hutang = outstandingHutangs.value.find((h) => h.id === hutangId);
    if (!hutang) return;

    const max = getMaxPayment(hutang);
    const currentCash = hutangPayments.value[hutangId]?.cash || 0;
    const maxForThisHutang = Math.max(0, max - currentCash);

    // Calculate remaining deposit available (excluding current hutang's deposit)
    const currentDepositForThis = hutangPayments.value[hutangId]?.deposit || 0;
    const remainingAvailable = remainingDepositAvailable.value + currentDepositForThis;

    // Fill with minimum of max allowed and remaining available
    hutangPayments.value[hutangId].deposit = Math.min(maxForThisHutang, remainingAvailable);
}
</script>

<template>
    <div class="card">
        <!-- Header -->
        <div class="flex items-center gap-4 mb-6">
            <Button icon="pi pi-arrow-left" severity="secondary" text rounded @click="cancel" />
            <div>
                <h2 class="text-2xl font-semibold m-0">{{ pageTitle }}</h2>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex justify-center py-8">
            <ProgressSpinner />
        </div>

        <!-- Form -->
        <form v-else @submit.prevent="save">
            <!-- Header Fields -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <!-- Supplier -->
                <div class="flex flex-col gap-2">
                    <label for="supplier" class="font-medium">Supplier <span class="text-red-500">*</span></label>
                    <Select
                        id="supplier"
                        v-model="form.supplier_id"
                        :options="suppliers"
                        optionLabel="nama_supplier"
                        optionValue="id"
                        placeholder="Pilih Supplier"
                        filter
                        class="w-full"
                        :class="{ 'p-invalid': errors.supplier_id }"
                        :disabled="isEdit"
                    />
                    <small v-if="errors.supplier_id" class="text-red-500">{{ errors.supplier_id }}</small>
                </div>

                <!-- Tanggal -->
                <div class="flex flex-col gap-2">
                    <label for="tanggal" class="font-medium">Tanggal <span class="text-red-500">*</span></label>
                    <DatePicker id="tanggal" v-model="form.tanggal" :dateFormat="getPrimeDateFormatShort" class="w-full" :class="{ 'p-invalid': errors.tanggal }" showIcon />
                    <small v-if="errors.tanggal" class="text-red-500">{{ errors.tanggal }}</small>
                </div>

                <!-- Metode Pembayaran -->
                <div class="flex flex-col gap-2">
                    <label for="metode" class="font-medium">Metode Pembayaran</label>
                    <Select id="metode" v-model="form.metode_pembayaran" :options="metodeOptions" optionLabel="label" optionValue="value" class="w-full" />
                </div>

                <!-- No Referensi -->
                <div class="flex flex-col gap-2">
                    <label for="no_referensi" class="font-medium">No. Referensi</label>
                    <InputText id="no_referensi" v-model="form.no_referensi" placeholder="No. bukti/kwitansi" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                </div>
            </div>

            <!-- Bank Fields (only for transfer) -->
            <div v-if="form.metode_pembayaran === 'transfer'" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="flex flex-col gap-2">
                    <label for="bank_nama" class="font-medium">Nama Bank</label>
                    <InputText id="bank_nama" v-model="form.bank_nama" placeholder="Nama bank" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                </div>
                <div class="flex flex-col gap-2">
                    <label for="bank_rekening" class="font-medium">No. Rekening</label>
                    <InputText id="bank_rekening" v-model="form.bank_rekening" placeholder="Nomor rekening" class="w-full" />
                </div>
            </div>

            <!-- Outstanding Hutangs Section -->
            <div class="border border-surface-200 rounded-lg p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium m-0">Hutang Outstanding</h3>
                    <div class="flex gap-2">
                        <Button label="Bayar Semua" icon="pi pi-check-circle" size="small" severity="secondary" outlined @click="payAllCash" :disabled="!form.supplier_id" />
                        <Button label="Reset" icon="pi pi-refresh" size="small" severity="secondary" outlined @click="resetPayments" :disabled="!form.supplier_id" />
                    </div>
                </div>

                <small v-if="errors.details" class="text-red-500 block mb-4">{{ errors.details }}</small>

                <div v-if="loadingHutangs" class="flex justify-center py-8">
                    <ProgressSpinner />
                </div>

                <DataTable v-else-if="outstandingHutangs.length > 0" :value="outstandingHutangs" class="p-datatable-sm" responsiveLayout="scroll">
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>

                    <Column header="No. PO" style="min-width: 150px">
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.purchase_order?.nomor_dokumen || '-' }}</span>
                        </template>
                    </Column>

                    <Column header="Tanggal" style="min-width: 120px">
                        <template #body="{ data }">
                            {{ formatDateTime(data.tanggal) }}
                        </template>
                    </Column>

                    <Column header="Jatuh Tempo" style="min-width: 120px">
                        <template #body="{ data }">
                            <span :class="{ 'text-red-500 font-medium': data.tanggal_jatuh_tempo && new Date(data.tanggal_jatuh_tempo) < new Date() }">
                                {{ formatDateTime(data.tanggal_jatuh_tempo) || '-' }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Nominal Awal" style="min-width: 130px" bodyClass="text-right">
                        <template #body="{ data }">
                            {{ formatCurrency(data.nominal_awal) }}
                        </template>
                    </Column>

                    <Column header="Terbayar" style="min-width: 130px" bodyClass="text-right">
                        <template #body="{ data }">
                            {{ formatCurrency(data.nominal_terbayar) }}
                        </template>
                    </Column>

                    <Column header="Sisa" style="min-width: 130px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-semibold text-orange-600">{{ formatCurrency(data.sisa_hutang) }}</span>
                        </template>
                    </Column>

                    <Column header="Bayar Cash" style="min-width: 180px">
                        <template #body="{ data }">
                            <InputGroup>
                                <InputNumber
                                    v-select-on-focus
                                    v-model="hutangPayments[data.id].cash"
                                    :min="0"
                                    :max="getMaxPayment(data) - (hutangPayments[data.id]?.deposit || 0)"
                                    :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                    :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                    :locale="getLocale"
                                    :minFractionDigits="getCurrencyMinFractionDigits"
                                    :maxFractionDigits="getCurrencyMaxFractionDigits"
                                    class="w-full"
                                    @blur="validateHutangPayment(data.id, 'cash')"
                                />
                                <Button icon="pi pi-arrow-up" severity="secondary" text @click="fillMaxCash(data.id)" v-tooltip.top="'Isi maksimum'" />
                            </InputGroup>
                        </template>
                    </Column>

                    <Column header="Bayar Deposit" style="min-width: 180px">
                        <template #body="{ data }">
                            <InputGroup>
                                <InputNumber
                                    v-select-on-focus
                                    v-model="hutangPayments[data.id].deposit"
                                    :min="0"
                                    :max="getMaxPayment(data) - (hutangPayments[data.id]?.cash || 0)"
                                    :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                    :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                    :locale="getLocale"
                                    :minFractionDigits="getCurrencyMinFractionDigits"
                                    :maxFractionDigits="getCurrencyMaxFractionDigits"
                                    class="w-full"
                                    :disabled="availableDeposits.length === 0"
                                    @blur="validateHutangPayment(data.id, 'deposit')"
                                />
                                <Button icon="pi pi-arrow-up" severity="secondary" text :disabled="availableDeposits.length === 0" @click="fillMaxDeposit(data.id)" v-tooltip.top="'Isi dari deposit'" />
                            </InputGroup>
                        </template>
                    </Column>
                </DataTable>

                <div v-else-if="form.supplier_id" class="text-center py-8 text-surface-500">
                    <i class="pi pi-check-circle text-4xl mb-4 block text-green-500"></i>
                    <p class="m-0">Tidak ada hutang outstanding untuk supplier ini</p>
                </div>

                <div v-else class="text-center py-8 text-surface-500">
                    <i class="pi pi-info-circle text-4xl mb-4 block"></i>
                    <p class="m-0">Pilih supplier terlebih dahulu untuk melihat hutang outstanding</p>
                </div>
            </div>

            <!-- Available Deposits Section -->
            <div v-if="availableDeposits.length > 0 || totalDepositPayment > 0" class="border border-surface-200 rounded-lg p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium m-0">Deposit Tersedia</h3>
                    <Button v-if="totalDepositPayment > 0" label="Auto Alokasi" icon="pi pi-bolt" size="small" severity="secondary" outlined @click="autoAllocateDeposits" />
                </div>

                <small v-if="errors.deposit_usages" class="text-red-500 block mb-4">{{ errors.deposit_usages }}</small>

                <div v-if="loadingDeposits" class="flex justify-center py-8">
                    <ProgressSpinner />
                </div>

                <DataTable v-else-if="availableDeposits.length > 0" :value="availableDeposits" class="p-datatable-sm" responsiveLayout="scroll">
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>

                    <Column header="Sumber" style="min-width: 200px">
                        <template #body="{ data }">
                            <div>
                                <span v-if="data.purchase_return" class="font-medium">{{ data.purchase_return?.nomor_dokumen }}</span>
                                <span v-else class="font-medium">{{ data.no_referensi || 'Manual' }}</span>
                                <div class="text-sm text-surface-500">{{ data.keterangan || '-' }}</div>
                            </div>
                        </template>
                    </Column>

                    <Column header="Tanggal" style="min-width: 120px">
                        <template #body="{ data }">
                            {{ formatDateTime(data.tanggal) }}
                        </template>
                    </Column>

                    <Column header="Nominal Awal" style="min-width: 130px" bodyClass="text-right">
                        <template #body="{ data }">
                            {{ formatCurrency(data.nominal_awal) }}
                        </template>
                    </Column>

                    <Column header="Terpakai" style="min-width: 130px" bodyClass="text-right">
                        <template #body="{ data }">
                            {{ formatCurrency(data.nominal_terpakai) }}
                        </template>
                    </Column>

                    <Column header="Sisa" style="min-width: 130px" bodyClass="text-right">
                        <template #body="{ data }">
                            <span class="font-semibold text-green-600">{{ formatCurrency(data.sisa_deposit) }}</span>
                        </template>
                    </Column>

                    <Column header="Gunakan" style="min-width: 150px">
                        <template #body="{ data }">
                            <InputNumber
                                v-select-on-focus
                                v-model="depositUsages[data.id]"
                                :min="0"
                                :max="getMaxDepositUsage(data)"
                                :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                :locale="getLocale"
                                :minFractionDigits="getCurrencyMinFractionDigits"
                                :maxFractionDigits="getCurrencyMaxFractionDigits"
                                class="w-full"
                                @blur="validateDepositUsage(data.id)"
                            />
                        </template>
                    </Column>
                </DataTable>
            </div>

            <!-- Summary Section -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- Notes -->
                <div class="flex flex-col gap-2">
                    <label for="notes" class="font-medium">Catatan</label>
                    <Textarea id="notes" v-model="form.notes" rows="3" class="w-full" placeholder="Catatan untuk pembayaran ini..." :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                </div>

                <!-- Totals -->
                <div class="border border-surface-200 rounded-lg p-4">
                    <h4 class="font-medium mb-4">Ringkasan Pembayaran</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-surface-600">Total Bayar Cash</span>
                            <span class="font-medium">{{ formatCurrency(totalCashPayment) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-surface-600">Total Bayar Deposit</span>
                            <span class="font-medium">{{ formatCurrency(totalDepositPayment) }}</span>
                        </div>
                        <div v-if="totalDepositPayment > 0" class="flex justify-between" :class="{ 'text-red-500': depositMismatch }">
                            <span>Deposit Digunakan</span>
                            <span>{{ formatCurrency(totalDepositUsed) }}</span>
                        </div>
                        <Divider />
                        <div class="flex justify-between text-xl font-bold">
                            <span>Total Pembayaran</span>
                            <span class="text-green-600">{{ formatCurrency(totalPayment) }}</span>
                        </div>
                        <div class="text-sm text-surface-500">
                            {{ selectedHutangsCount }} hutang dipilih
                            <span v-if="selectedDepositsCount > 0">, {{ selectedDepositsCount }} deposit digunakan</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end gap-2">
                <Button label="Batal" severity="secondary" outlined @click="cancel" />
                <Button label="Simpan" icon="pi pi-save" type="submit" :loading="saving" :disabled="selectedHutangsCount === 0" />
            </div>
        </form>
    </div>
</template>

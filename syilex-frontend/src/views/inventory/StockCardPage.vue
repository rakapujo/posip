<script setup>
import { stockCardsApi, produksApi } from '@/api';
import { onMounted, ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useAuthStore } from '@/stores/auth';

const notify = useNotification();
const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const { formatQty, formatCurrency, formatDateTime, getPrimeDateFormat, toDateString, todayString } = useFormatters();

// Permissions
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));

// Data
const stockCards = ref([]);
const warehouses = ref([]);
const transactionTypes = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

// Selected product
const selectedProduct = ref(null);
const productOptions = ref([]);
const loadingProducts = ref(false);
let filterTimeout = null;

// Summary
const summary = ref({
    opening_balance: 0,
    total_in: 0,
    total_out: 0,
    ending_balance: 0
});

// Export
const exportingExcel = ref(false);

// Filters
const selectedWarehouse = ref(null);
const selectedTransactionType = ref(null);
const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const endDate = ref(new Date());

// Pagination & Sort
const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'tanggal',
    sortOrder: -1
});

onMounted(async () => {
    // Check if product_id is passed via route query
    if (route.query.product_id) {
        await loadProductById(route.query.product_id);
    }
    if (route.query.warehouse_id) {
        selectedWarehouse.value = parseInt(route.query.warehouse_id);
    }

    // Initial load
    await loadStockCards();
    await loadSummary();
});

// Watch for product change
watch(selectedProduct, async (newProduct) => {
    lazyParams.value.first = 0;

    // Only process if it's a valid product object with ulid
    if (newProduct && typeof newProduct === 'object' && newProduct.ulid) {
        // If product selected but missing unit_4, fetch full details
        if (!newProduct.unit_4) {
            try {
                const response = await produksApi.get(newProduct.ulid);
                if (response.data.success && response.data.data.produk) {
                    const produk = response.data.data.produk;
                    selectedProduct.value = {
                        ...newProduct,
                        unit_4: produk.unit_4 || 'PCS'
                    };
                }
            } catch (error) {
                console.error('Failed to load product details:', error);
                notify.apiError(error, 'Gagal load product details');
            }
        }
        loadStockCards();
        loadSummary();
    } else if (!newProduct) {
        // Product cleared - reload to show empty state
        loadStockCards();
        loadSummary();
    }
    // If newProduct is a string (user typing), ignore - don't make API calls
});

async function loadProductById(productId) {
    try {
        const response = await produksApi.get(productId);
        if (response.data.success && response.data.data.produk) {
            const produk = response.data.data.produk;
            selectedProduct.value = {
                id: produk.id,
                ulid: produk.ulid,
                kode_produk: produk.kode_produk,
                nama_produk: produk.nama_produk,
                barcode: produk.barcode,
                brand: produk.brand,
                is_serial: produk.is_serial,
                unit_4: produk.unit_4 || 'PCS'
            };
            productOptions.value = [selectedProduct.value];
        }
    } catch (error) {
        console.error('Failed to load product:', error);
        notify.apiError(error, 'Gagal load product');
    }
}

// Get product base unit for display (unit_4 = base unit, stok disimpan dalam unit ini)
const productUnit = computed(() => selectedProduct.value?.unit_4 || 'PCS');

function onProductSearch(event) {
    const query = event.query?.trim();

    // Clear previous timeout
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }

    // Don't search if less than 2 characters
    if (!query || query.length < 2) {
        productOptions.value = [];
        return;
    }

    // Debounce 300ms
    filterTimeout = setTimeout(async () => {
        loadingProducts.value = true;
        try {
            const response = await produksApi.getList({ search: query });
            if (response.data.success) {
                productOptions.value = response.data.data.produks || [];
            }
        } catch (error) {
            console.error('Failed to search products:', error);
            productOptions.value = [];
        } finally {
            loadingProducts.value = false;
        }
    }, 300);
}

async function loadStockCards() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || 'tanggal',
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };

        // Product is required
        if (selectedProduct.value) {
            params.product_id = selectedProduct.value.ulid || selectedProduct.value.id;
        }

        // Filter by warehouse
        if (selectedWarehouse.value) {
            params.warehouse_id = selectedWarehouse.value;
        }

        // Filter by date range
        if (startDate.value) {
            params.start_date = toDateString(startDate.value);
        }
        if (endDate.value) {
            params.end_date = toDateString(endDate.value);
        }

        // Filter by transaction type
        if (selectedTransactionType.value) {
            params.transaction_type = selectedTransactionType.value;
        }

        const response = await stockCardsApi.getAll(params);
        if (response.data.success) {
            stockCards.value = response.data.data.stock_cards;
            totalRecords.value = response.data.data.pagination.total;

            // Get warehouses and transaction types from response
            if (response.data.data.warehouses) {
                warehouses.value = [{ id: null, nama_warehouse: 'Semua Gudang' }, ...response.data.data.warehouses];
            }
            if (response.data.data.transaction_types) {
                transactionTypes.value = [{ value: null, label: 'Semua Tipe' }, ...response.data.data.transaction_types];
            }
        }
    } catch (error) {
        console.error('Failed to load stock cards:', error);
        notify.loadListError('kartu stok');
    } finally {
        loading.value = false;
    }
}

async function loadSummary() {
    if (!selectedProduct.value) {
        summary.value = { opening_balance: 0, total_in: 0, total_out: 0, ending_balance: 0 };
        return;
    }

    try {
        const params = {
            product_id: selectedProduct.value.ulid || selectedProduct.value.id
        };

        if (selectedWarehouse.value) {
            params.warehouse_id = selectedWarehouse.value;
        }
        if (startDate.value) {
            params.start_date = toDateString(startDate.value);
        }
        if (endDate.value) {
            params.end_date = toDateString(endDate.value);
        }
        if (selectedTransactionType.value) {
            params.transaction_type = selectedTransactionType.value;
        }

        const response = await stockCardsApi.getSummary(params);

        if (response.data.success) {
            summary.value = response.data.data.summary;
        }
    } catch (error) {
        console.error('Failed to load summary:', error);
        notify.apiError(error, 'Gagal load summary');
    }
}

// Export to Excel
async function exportExcel() {
    if (!selectedProduct.value) {
        notify.selectFirst('produk');
        return;
    }

    exportingExcel.value = true;
    try {
        const params = {
            product_id: selectedProduct.value.ulid || selectedProduct.value.id
        };

        if (selectedWarehouse.value) {
            params.warehouse_id = selectedWarehouse.value;
        }
        if (startDate.value) {
            params.start_date = toDateString(startDate.value);
        }
        if (endDate.value) {
            params.end_date = toDateString(endDate.value);
        }
        if (selectedTransactionType.value) {
            params.transaction_type = selectedTransactionType.value;
        }

        const response = await stockCardsApi.export(params);

        // Create download link
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `kartu_stok_${selectedProduct.value.kode_produk}_${todayString()}.xlsx`);
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

// DataTable events
function onPage(event) {
    lazyParams.value = { ...lazyParams.value, ...event };
    loadStockCards();
}

function onSort(event) {
    lazyParams.value = { ...lazyParams.value, ...event };
    loadStockCards();
}

function onFilterChange() {
    lazyParams.value.first = 0;
    loadStockCards();
    loadSummary();
}

function onResetAll() {
    selectedWarehouse.value = null;
    selectedTransactionType.value = null;
    startDate.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    endDate.value = new Date();
    lazyParams.value.first = 0;
    loadStockCards();
    loadSummary();
}

// Transaction type badge severity
function getTransactionSeverity(type) {
    const inTypes = ['PURCHASE', 'SALES_RETURN', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'REPACK_IN'];
    const outTypes = ['SALES', 'PURCHASE_RETURN', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'REPACK_OUT'];
    const systemTypes = ['HPP_RESET', 'STOCK_OPNAME'];

    if (inTypes.includes(type)) return 'success';
    if (outTypes.includes(type)) return 'danger';
    if (systemTypes.includes(type)) return 'warn';
    return 'info';
}

// Go back to Stock page
function goBack() {
    router.push({ name: 'inventory-stok' });
}

// Buka dokumen sumber (baris intake serial → dokumen Pembelian Serial)
function openSourceDoc(src) {
    if (src?.type === 'serial-intake' && src.ulid) {
        router.push({ name: 'inventory-serial-intake', query: { detail: src.ulid } });
    }
}
</script>

<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-6">
            <template #start>
                <div class="flex items-center gap-3">
                    <Button icon="pi pi-arrow-left" text rounded @click="goBack" v-tooltip.top="'Kembali ke Stok'" aria-label="Kembali ke Stok" />
                    <span class="text-xl font-semibold">Kartu Stok</span>
                </div>
            </template>
            <template #end>
                <Button label="Export Excel" icon="pi pi-file-excel" severity="success" outlined @click="exportExcel" :disabled="!selectedProduct" :loading="exportingExcel" />
            </template>
        </Toolbar>

        <!-- Product Selection -->
        <div class="mb-6">
            <label class="block text-surface-700 dark:text-surface-200 font-medium mb-2"> Pilih Produk <span class="text-red-500">*</span> </label>
            <AutoComplete
                v-model="selectedProduct"
                :suggestions="productOptions"
                optionLabel="nama_produk"
                placeholder="Ketik kode/nama/barcode produk..."
                :loading="loadingProducts"
                class="w-full"
                inputClass="w-full"
                @complete="onProductSearch"
                dropdown
            >
                <template #option="{ option }">
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ option.kode_produk }} - {{ option.nama_produk }}</span>
                            <Tag :value="option.is_serial ? 'SERIAL' : 'RETAIL'" :severity="option.is_serial ? 'help' : 'secondary'" class="text-xs" />
                        </div>
                        <span class="text-sm text-surface-500">{{ option.barcode }} {{ option.brand?.nama_brand ? `| ${option.brand.nama_brand}` : '' }}</span>
                    </div>
                </template>
                <template #chip="{ value }">
                    <span class="inline-flex items-center gap-2"
                        >{{ value.kode_produk }} - {{ value.nama_produk }}
                        <Tag v-if="value.is_serial" value="SERIAL" severity="help" class="text-xs" v-tooltip.top="'HPP rata-rata tertimbang — modal riil per-unit di modul serial'" />
                    </span>
                </template>
            </AutoComplete>
        </div>

        <!-- Selected Product Info -->
        <div v-if="selectedProduct" class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4 mb-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <div class="text-surface-500 text-sm">Kode Produk</div>
                    <div class="font-mono font-medium">{{ selectedProduct.kode_produk }}</div>
                </div>
                <div>
                    <div class="text-surface-500 text-sm">Barcode</div>
                    <div class="font-mono">{{ selectedProduct.barcode || '-' }}</div>
                </div>
                <div class="md:col-span-2">
                    <div class="text-surface-500 text-sm">Nama Produk</div>
                    <div class="font-medium">{{ selectedProduct.nama_produk }}</div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div v-if="selectedProduct" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-blue-600 dark:text-blue-400 text-sm mb-1">Saldo Awal</div>
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                    {{ formatQty(summary.opening_balance) }} <span class="text-base font-normal">{{ productUnit }}</span>
                </div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                <div class="text-green-600 dark:text-green-400 text-sm mb-1">Total Masuk</div>
                <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                    {{ formatQty(summary.total_in) }} <span class="text-base font-normal">{{ productUnit }}</span>
                </div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                <div class="text-red-600 dark:text-red-400 text-sm mb-1">Total Keluar</div>
                <div class="text-2xl font-bold text-red-600 dark:text-red-400">
                    {{ formatQty(summary.total_out) }} <span class="text-base font-normal">{{ productUnit }}</span>
                </div>
            </div>
            <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4">
                <div class="text-purple-600 dark:text-purple-400 text-sm mb-1">Saldo Akhir</div>
                <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
                    {{ formatQty(summary.ending_balance) }} <span class="text-base font-normal">{{ productUnit }}</span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div v-if="selectedProduct" class="flex flex-wrap gap-2 mb-4">
            <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Gudang" class="w-44" filter showClear @change="onFilterChange" />
            <Select v-model="selectedTransactionType" :options="transactionTypes" optionLabel="label" optionValue="value" placeholder="Tipe Transaksi" class="w-40" filter showClear @change="onFilterChange" />
            <div class="w-40">
                <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormat" fluid showButtonBar @date-select="onFilterChange" />
            </div>
            <div class="w-40">
                <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormat" fluid showButtonBar @date-select="onFilterChange" />
            </div>
            <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="onResetAll" />
        </div>

        <!-- DataTable -->
        <DataTable
            v-if="selectedProduct"
            :value="stockCards"
            :loading="loading"
            :lazy="true"
            :paginator="true"
            :rows="lazyParams.rows"
            :totalRecords="totalRecords"
            :rowsPerPageOptions="[10, 25, 50]"
            :sortField="lazyParams.sortField"
            :sortOrder="lazyParams.sortOrder"
            dataKey="ulid"
            @page="onPage"
            @sort="onSort"
            stripedRows
            showGridlines
            scrollable
        >
            <!-- Saldo Awal Row (Header) -->
            <template #header>
                <div class="flex justify-between items-center bg-blue-50 dark:bg-blue-900/30 -mx-4 -mt-4 px-4 py-3 border-b border-blue-200 dark:border-blue-800">
                    <span class="font-bold text-blue-700 dark:text-blue-300">SALDO AWAL</span>
                    <span class="font-bold text-blue-700 dark:text-blue-300 text-xl">{{ formatQty(summary.opening_balance) }} {{ productUnit }}</span>
                </div>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">
                    {{ selectedProduct ? 'Tidak ada data kartu stok' : 'Pilih produk terlebih dahulu' }}
                </div>
            </template>

            <!-- Saldo Akhir Row (Footer) -->
            <template #footer>
                <div class="bg-surface-100 dark:bg-surface-800 -mx-4 px-4 py-3 border-t border-surface-200 dark:border-surface-700">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-surface-600 dark:text-surface-400">Total Masuk</span>
                        <span class="text-green-600 font-semibold">+{{ formatQty(summary.total_in) }} {{ productUnit }}</span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-surface-600 dark:text-surface-400">Total Keluar</span>
                        <span class="text-red-600 font-semibold">-{{ formatQty(summary.total_out) }} {{ productUnit }}</span>
                    </div>
                    <div class="flex justify-between items-center pt-2 border-t border-surface-300 dark:border-surface-600">
                        <span class="font-bold text-purple-700 dark:text-purple-300">SALDO AKHIR</span>
                        <span class="font-bold text-purple-700 dark:text-purple-300 text-xl">{{ formatQty(summary.ending_balance) }} {{ productUnit }}</span>
                    </div>
                </div>
            </template>

            <!-- Tanggal -->
            <Column field="tanggal" header="Tanggal" sortable style="min-width: 140px">
                <template #body="{ data }">
                    {{ formatDateTime(data.tanggal) }}
                </template>
            </Column>

            <!-- Gudang -->
            <Column field="warehouse.nama" header="Gudang" style="min-width: 150px">
                <template #body="{ data }">
                    <div v-if="data.warehouse">
                        <span class="font-mono text-sm">{{ data.warehouse.kode }}</span>
                        <div class="text-sm text-surface-500">{{ data.warehouse.nama }}</div>
                    </div>
                    <span v-else>-</span>
                </template>
            </Column>

            <!-- Tipe Transaksi -->
            <Column field="transaction_type" header="Tipe" style="min-width: 130px">
                <template #body="{ data }">
                    <Tag :value="data.transaction_type_label" :severity="getTransactionSeverity(data.transaction_type)" />
                </template>
            </Column>

            <!-- No. Dokumen -->
            <Column field="transaction_no" header="No. Dokumen" style="min-width: 150px">
                <template #body="{ data }">
                    <a v-if="data.source_doc?.type === 'serial-intake'" href="#" class="font-mono text-primary hover:underline" @click.prevent="openSourceDoc(data.source_doc)" v-tooltip.top="'Buka dokumen pembelian serial'">
                        {{ data.transaction_no || '-' }}
                    </a>
                    <span v-else class="font-mono">{{ data.transaction_no || '-' }}</span>
                </template>
            </Column>

            <!-- Qty Masuk -->
            <Column field="qty_in" header="Masuk" style="min-width: 100px" class="text-right">
                <template #body="{ data }">
                    <span v-if="data.qty_in > 0" class="text-green-600 font-medium">+{{ formatQty(data.qty_in) }} {{ productUnit }}</span>
                    <span v-else class="text-surface-400">-</span>
                </template>
            </Column>

            <!-- Qty Keluar -->
            <Column field="qty_out" header="Keluar" style="min-width: 100px" class="text-right">
                <template #body="{ data }">
                    <span v-if="data.qty_out > 0" class="text-red-600 font-medium">-{{ formatQty(data.qty_out) }} {{ productUnit }}</span>
                    <span v-else class="text-surface-400">-</span>
                </template>
            </Column>

            <!-- Saldo -->
            <Column field="qty_balance" header="Saldo" style="min-width: 100px" class="text-right">
                <template #body="{ data }">
                    <span class="font-semibold" :class="{ 'text-red-600': data.qty_balance < 0 }"> {{ formatQty(data.qty_balance) }} {{ productUnit }} </span>
                </template>
            </Column>

            <!-- HPP Columns (if permission) -->
            <Column v-if="canViewHpp" field="cost_per_unit" header="HPP/Unit" style="min-width: 110px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.cost_per_unit) }}
                </template>
            </Column>

            <Column v-if="canViewHpp" field="total_cost" header="Total HPP" style="min-width: 120px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.total_cost) }}
                </template>
            </Column>

            <Column v-if="canViewHpp" field="avg_cost_after" header="Avg Cost" style="min-width: 110px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.avg_cost_after) }}
                </template>
            </Column>

            <!-- Keterangan -->
            <Column field="notes" header="Keterangan" style="min-width: 150px">
                <template #body="{ data }">
                    <span class="text-sm">{{ data.notes || '-' }}</span>
                </template>
            </Column>

            <!-- Created By -->
            <Column field="created_by" header="Dibuat Oleh" style="min-width: 120px">
                <template #body="{ data }">
                    <span class="text-sm">{{ data.created_by || '-' }}</span>
                </template>
            </Column>
        </DataTable>

        <!-- Empty State when no product selected -->
        <div v-if="!selectedProduct" class="text-center py-12">
            <i class="pi pi-search text-6xl text-surface-300 mb-4"></i>
            <h3 class="text-xl font-semibold text-surface-700 dark:text-surface-200 mb-2">Pilih Produk</h3>
            <p class="text-surface-500">Cari dan pilih produk untuk melihat kartu stok</p>
        </div>
    </div>
</template>

<style scoped>
:deep(.p-autocomplete-input) {
    width: 100%;
}
</style>

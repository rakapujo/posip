<script setup>
import { inventoryStocksApi } from '@/api';
import { useRouter, useRoute } from 'vue-router';
import { onMounted, ref, computed } from 'vue';
import DetailItem from '@/components/common/DetailItem.vue';
import DetailTable from '@/components/common/DetailTable.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useAuthStore } from '@/stores/auth';

const notify = useNotification();
const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const { formatQty, formatCurrency, formatUnitHierarchy, formatStockBreakdown, todayString } = useFormatters();

// Permissions
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));

// Detail table columns - dynamic based on canViewHpp permission
const stockDetailColumns = computed(() => {
    const cols = [
        { field: 'warehouse_kode', header: 'Kode', width: '100px' },
        { field: 'warehouse_nama', header: 'Nama Gudang' },
        { field: 'qty', header: 'Qty', align: 'right', width: '100px' },
        { field: 'stock_breakdown', header: 'Stok per Satuan', width: '140px' }
    ];
    if (canViewHpp.value) {
        cols.push({ field: 'avg_cost', header: 'HPP', align: 'right', width: '100px' }, { field: 'total_value', header: 'Nilai', align: 'right', width: '120px' });
    }
    cols.push({ field: 'stock_status', header: 'Status', width: '90px' }, { field: 'warehouse_status', header: 'Gudang', width: '80px' });
    return cols;
});

// Data
const dt = ref();
const products = ref([]);
const warehouses = ref([]);
const loading = ref(false);
const totalRecords = ref(0);
const expandedRows = ref([]);

// Summary
const summary = ref({
    total_products: 0,
    total_warehouses: 0,
    total_qty: 0,
    total_value: null,
    low_stock_count: 0,
    negative_stock_count: 0
});

// Valuation per warehouse (E2)
const valuation = ref({ loading: false, grand_total_value: 0, items: [] });

// Search & Filters
const searchQuery = ref('');
const selectedWarehouse = ref(null);
const selectedStatus = ref('active');
const lowStockOnly = ref(false);

// Pagination & Sort
const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'kode_produk',
    sortOrder: 1
});

const statusOptions = ref([
    { label: 'Semua Status', value: null },
    { label: 'Aktif', value: 'active' },
    { label: 'Nonaktif', value: 'inactive' }
]);

// Export
const exportingExcel = ref(false);

// Detail dialog
const detailDialog = ref(false);
const loadingDetail = ref(false);
const detailData = ref({});

onMounted(async () => {
    // Check for warehouse_id in query params (from warehouse detail page)
    const warehouseIdParam = route.query.warehouse_id;
    if (warehouseIdParam) {
        selectedWarehouse.value = parseInt(warehouseIdParam, 10);
    }

    await Promise.all([loadStocks(), loadSummary(), loadValuation()]);
});

function toggleLowStockFilter() {
    lowStockOnly.value = !lowStockOnly.value;
    onFilterChange();
}

async function loadValuation() {
    // E2: guard — only when user has stok.view_hpp (backend returns 403 otherwise)
    if (!canViewHpp.value) return;
    valuation.value.loading = true;
    try {
        const response = await inventoryStocksApi.getValuationByWarehouse();
        if (response.data.success) {
            valuation.value.grand_total_value = response.data.data.grand_total_value;
            valuation.value.items = response.data.data.items;
        }
    } catch (error) {
        console.error('Failed to load valuation:', error);
        notify.apiError(error, 'Gagal load valuation');
    } finally {
        valuation.value.loading = false;
    }
}

async function loadStocks() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || 'kode_produk',
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };

        // Search
        if (searchQuery.value?.trim()) {
            params.search = searchQuery.value.trim();
        }

        // Filter by warehouse
        if (selectedWarehouse.value) {
            params.warehouse_id = selectedWarehouse.value;
        }

        // Filter by status
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }

        // Filter low stock only
        if (lowStockOnly.value) {
            params.low_stock = true;
        }

        const response = await inventoryStocksApi.getAll(params);
        if (response.data.success) {
            products.value = response.data.data.products;
            totalRecords.value = response.data.data.pagination.total;

            // Get warehouses from response (includes id)
            if (response.data.data.warehouses && warehouses.value.length <= 1) {
                warehouses.value = [{ id: null, nama_warehouse: 'Semua Gudang' }, ...response.data.data.warehouses];
            }
        }
    } catch (error) {
        console.error('Failed to load stocks:', error);
        notify.loadListError('stok');
    } finally {
        loading.value = false;
    }
}

async function loadSummary() {
    try {
        const params = {};
        if (selectedWarehouse.value) {
            params.warehouse_id = selectedWarehouse.value;
        }

        const response = await inventoryStocksApi.getSummary(params);
        if (response.data.success) {
            summary.value = response.data.data.summary;
        }
    } catch (error) {
        console.error('Failed to load summary:', error);
        notify.apiError(error, 'Gagal load summary');
    }
}

// View detail for a product
async function viewDetail(product) {
    detailDialog.value = true;
    loadingDetail.value = true;
    detailData.value = {};

    try {
        const response = await inventoryStocksApi.getByProduct(product.ulid);
        if (response.data.success) {
            detailData.value = response.data.data;
        }
    } catch (error) {
        notify.loadDetailError('stok');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

// Export to Excel
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const params = {};
        if (selectedWarehouse.value) {
            params.warehouse_id = selectedWarehouse.value;
        }
        if (searchQuery.value?.trim()) {
            params.search = searchQuery.value.trim();
        }
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }
        if (lowStockOnly.value) {
            params.low_stock = true;
        }

        const response = await inventoryStocksApi.export(params);

        // Create download link
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `inventory_stock_${todayString()}.xlsx`);
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
    loadStocks();
}

function onSort(event) {
    lazyParams.value = { ...lazyParams.value, ...event };
    loadStocks();
}

function onSearch() {
    lazyParams.value.first = 0;
    expandedRows.value = [];
    loadStocks();
    loadSummary();
}

function clearSearch() {
    searchQuery.value = '';
    lazyParams.value.first = 0;
    expandedRows.value = [];
    loadStocks();
    loadSummary();
}

function onResetAll() {
    searchQuery.value = '';
    selectedWarehouse.value = null;
    selectedStatus.value = 'active';
    lowStockOnly.value = false;
    lazyParams.value.first = 0;
    expandedRows.value = [];
    loadStocks();
    loadSummary();
}

function onFilterChange() {
    lazyParams.value.first = 0;
    expandedRows.value = [];
    loadStocks();
    loadSummary();
}

// Status severity for badges
function getStatusSeverity(status) {
    return status === 'active' ? 'success' : 'danger';
}

function getStatusLabel(status) {
    return status === 'active' ? 'Aktif' : 'Nonaktif';
}

// Stock status helpers
function getStockSeverity(qty, minimumStok) {
    if (qty < 0) return 'danger';
    if (qty < minimumStok) return 'warn';
    return 'success';
}

function getStockLabel(qty, minimumStok) {
    if (qty < 0) return 'Negatif';
    if (qty < minimumStok) return 'Menipis';
    return 'OK';
}

// Navigate to Stock Card page
function viewStockCard(product, stock) {
    router.push({
        name: 'inventory-kartu-stok',
        query: {
            product_id: product.ulid,
            warehouse_id: stock.warehouse_id
        }
    });
}
</script>

<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-6">
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Gudang" class="w-40" filter showClear @change="onFilterChange" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-32" filter showClear @change="onFilterChange" />
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="lowStockOnly" :binary="true" inputId="lowStock" @change="onFilterChange" />
                        <label for="lowStock" class="text-surface-700 dark:text-surface-200 cursor-pointer whitespace-nowrap text-sm">Menipis</label>
                    </div>
                    <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="onResetAll" />
                </div>
            </template>
        </Toolbar>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Produk</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatQty(summary.total_products) }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Gudang</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatQty(summary.total_warehouses) }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Qty (Base Unit)</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatQty(summary.total_qty) }}</div>
            </div>
            <div v-if="canViewHpp" class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Nilai</div>
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(summary.total_value) }}</div>
            </div>
            <div
                class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4 cursor-pointer hover:ring-2 hover:ring-orange-400 transition"
                :class="{ 'ring-2 ring-orange-500': lowStockOnly }"
                @click="toggleLowStockFilter"
                role="button"
                tabindex="0"
                @keydown.enter="toggleLowStockFilter"
                aria-label="Filter stok menipis"
            >
                <div class="text-orange-600 dark:text-orange-400 text-sm mb-1 flex items-center gap-1">
                    Stok Menipis
                    <i v-if="lowStockOnly" class="pi pi-filter-fill text-xs"></i>
                </div>
                <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">{{ formatQty(summary.low_stock_count) }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                <div class="text-red-600 dark:text-red-400 text-sm mb-1">Stok Negatif</div>
                <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ formatQty(summary.negative_stock_count) }}</div>
            </div>
        </div>

        <!-- E2: Valuation per Warehouse -->
        <div v-if="canViewHpp && valuation.items.length > 0" class="mb-4 bg-surface-0 dark:bg-surface-900 rounded-lg border border-surface-200 dark:border-surface-700 p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-surface-800 dark:text-surface-100">Valuation per Gudang</h3>
                <span class="text-sm text-surface-500"
                    >Total: <span class="font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(valuation.grand_total_value) }}</span></span
                >
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div v-for="item in valuation.items" :key="item.warehouse_id" class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3 border border-surface-100 dark:border-surface-700">
                    <div class="flex items-center justify-between mb-1">
                        <span class="font-medium text-sm text-surface-700 dark:text-surface-200">{{ item.nama_warehouse }}</span>
                        <span class="text-xs text-surface-500">{{ item.percent }}%</span>
                    </div>
                    <div class="text-lg font-bold text-surface-900 dark:text-surface-0">{{ formatCurrency(item.value_total) }}</div>
                    <div class="text-xs text-surface-500 mt-1">{{ formatQty(item.qty_total) }} qty · {{ item.product_count }} produk</div>
                </div>
            </div>
        </div>

        <!-- DataTable with Row Expansion -->
        <DataTable
            ref="dt"
            v-model:expandedRows="expandedRows"
            :value="products"
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
            <template #header>
                <DataTableHeader v-model="searchQuery" title="Stok per Gudang" placeholder="Cari kode, barcode, nama..." @search="onSearch" @clear="clearSearch">
                    <template #extra>
                        <Button icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                    </template>
                </DataTableHeader>
            </template>

            <template #empty>
                <div class="text-center py-8 text-surface-500">Tidak ada data stok</div>
            </template>

            <!-- Expand Column -->
            <Column expander style="width: 3rem" />

            <!-- Kode Produk -->
            <Column field="kode_produk" header="Kode" sortable style="min-width: 120px">
                <template #body="{ data }">
                    <span class="font-mono font-medium">{{ data.kode_produk }}</span>
                </template>
            </Column>

            <!-- Nama Produk -->
            <Column field="nama_produk" header="Nama Produk" sortable style="min-width: 200px">
                <template #body="{ data }">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ data.nama_produk }}</span>
                            <Tag v-if="data.is_serial" value="SERIAL" severity="help" class="text-xs" v-tooltip.top="'HPP rata-rata tertimbang — modal riil per-unit di modul serial'" />
                            <Tag v-else value="RETAIL" severity="secondary" class="text-xs" />
                        </div>
                        <div v-if="data.brand" class="text-sm text-surface-500">{{ data.brand.nama_brand }}</div>
                    </div>
                </template>
            </Column>

            <!-- Hierarki Satuan -->
            <Column header="Hierarki Satuan" style="min-width: 180px">
                <template #body="{ data }">
                    <span class="text-xs text-surface-500">{{ formatUnitHierarchy(data) }}</span>
                </template>
            </Column>

            <!-- Total Qty -->
            <Column field="total_qty" header="Total Qty" sortable style="min-width: 120px" class="text-right">
                <template #body="{ data }">
                    <span :class="{ 'text-red-600': data.total_qty < 0, 'text-orange-600': data.has_low_stock && data.total_qty >= 0 }"> {{ formatQty(data.total_qty) }} {{ data.unit_4 }} </span>
                </template>
            </Column>

            <!-- Stok per Satuan -->
            <Column header="Stok per Satuan" style="min-width: 160px">
                <template #body="{ data }">
                    <span class="text-xs text-surface-500">{{ formatStockBreakdown(data.total_qty, data) }}</span>
                </template>
            </Column>

            <!-- Min Stok -->
            <Column field="minimum_stok" header="Min. Stok" style="min-width: 120px" class="text-right">
                <template #body="{ data }"> {{ formatQty(data.minimum_stok) }} {{ data.unit_4 }} </template>
            </Column>

            <!-- Total Value (if can view HPP) -->
            <Column v-if="canViewHpp" field="total_value" header="Total Nilai" style="min-width: 140px" class="text-right">
                <template #body="{ data }">
                    {{ formatCurrency(data.total_value) }}
                </template>
            </Column>

            <!-- Gudang Count -->
            <Column field="total_warehouses" header="Gudang" style="min-width: 80px" class="text-center">
                <template #body="{ data }">
                    <Tag :value="data.stocks?.length || 0" severity="info" />
                </template>
            </Column>

            <!-- Status -->
            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="getStatusLabel(data.status)" :severity="getStatusSeverity(data.status)" />
                </template>
            </Column>

            <!-- Actions -->
            <Column :exportable="false" style="min-width: 80px" alignFrozen="right" frozen>
                <template #body="{ data }">
                    <Button icon="pi pi-eye" outlined rounded severity="info" @click="viewDetail(data)" v-tooltip.top="'Lihat Detail'" aria-label="Lihat Detail" />
                </template>
            </Column>

            <!-- Row Expansion Template -->
            <template #expansion="{ data }">
                <div class="p-4">
                    <h5 class="font-semibold mb-3">Stok per Gudang - {{ data.nama_produk }}</h5>
                    <div class="mb-3 text-xs text-surface-500"><span class="font-medium">Hierarki Satuan:</span> {{ formatUnitHierarchy(data) }}</div>
                    <DataTable :value="data.stocks" class="p-datatable-sm">
                        <Column field="warehouse_kode" header="Kode Gudang" style="min-width: 120px">
                            <template #body="{ data: stock }">
                                <span class="font-mono">{{ stock.warehouse_kode }}</span>
                            </template>
                        </Column>
                        <Column field="warehouse_nama" header="Nama Gudang" style="min-width: 180px" />
                        <Column field="qty" header="Qty" style="min-width: 120px" class="text-right">
                            <template #body="{ data: stock }">
                                <span :class="{ 'text-red-600 font-semibold': stock.qty < 0, 'text-orange-600': stock.is_low_stock && stock.qty >= 0 }"> {{ formatQty(stock.qty) }} {{ data.unit_4 }} </span>
                            </template>
                        </Column>
                        <Column header="Stok per Satuan" style="min-width: 160px">
                            <template #body="{ data: stock }">
                                <span class="text-xs text-surface-500">{{ formatStockBreakdown(stock.qty, data) }}</span>
                            </template>
                        </Column>
                        <Column v-if="canViewHpp" field="avg_cost" header="HPP" style="min-width: 120px" class="text-right">
                            <template #body="{ data: stock }">
                                {{ formatCurrency(stock.avg_cost) }}
                            </template>
                        </Column>
                        <Column v-if="canViewHpp" field="total_value" header="Nilai" style="min-width: 140px" class="text-right">
                            <template #body="{ data: stock }">
                                {{ formatCurrency(stock.total_value) }}
                            </template>
                        </Column>
                        <Column header="Status" style="min-width: 100px">
                            <template #body="{ data: stock }">
                                <Tag :value="getStockLabel(stock.qty, data.minimum_stok)" :severity="getStockSeverity(stock.qty, data.minimum_stok)" />
                            </template>
                        </Column>
                        <Column header="Aksi" style="min-width: 100px">
                            <template #body="{ data: stock }">
                                <Button icon="pi pi-history" outlined rounded size="small" severity="secondary" @click="viewStockCard(data, stock)" v-tooltip.top="'Kartu Stok'" />
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </template>
        </DataTable>

        <!-- Detail Dialog -->
        <Dialog v-model:visible="detailDialog" modal header="Detail Stok Produk" :style="{ width: '850px' }">
            <div v-if="loadingDetail" class="flex justify-center py-8">
                <ProgressSpinner style="width: 50px; height: 50px" />
            </div>

            <template v-else-if="detailData.product">
                <!-- Product Info -->
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <DetailItem label="Kode Produk" :value="detailData.product.kode_produk" />
                    <DetailItem label="Barcode" :value="detailData.product.barcode" />
                    <DetailItem label="Nama Produk" :value="detailData.product.nama_produk" />
                    <DetailItem label="Brand" :value="detailData.product.brand?.nama_brand" />
                    <DetailItem label="Minimum Stok" :value="`${formatQty(detailData.product.minimum_stok)} ${detailData.product.unit_4}`" />
                    <DetailItem label="Hierarki Satuan" :value="formatUnitHierarchy(detailData.product)" />
                </div>

                <Divider />

                <!-- Summary -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Total Qty</div>
                        <div class="text-xl font-bold">{{ formatQty(detailData.summary?.total_qty) }} {{ detailData.product.unit_4 }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Stok per Satuan</div>
                        <div class="text-xs text-surface-500">{{ formatStockBreakdown(detailData.summary?.total_qty, detailData.product) }}</div>
                    </div>
                    <div v-if="canViewHpp" class="text-center">
                        <div class="text-surface-500 text-sm">Total Nilai</div>
                        <div class="text-xl font-bold">{{ formatCurrency(detailData.summary?.total_value) }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Gudang</div>
                        <div class="text-xl font-bold">{{ detailData.summary?.total_warehouses }}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-surface-500 text-sm">Stok Menipis</div>
                        <div class="text-xl font-bold text-orange-600">{{ detailData.summary?.low_stock_warehouses }}</div>
                    </div>
                </div>

                <Divider />

                <!-- Stock per Warehouse -->
                <h6 class="font-semibold mb-3">Stok per Gudang</h6>
                <DetailTable :data="detailData.stocks" :columns="stockDetailColumns">
                    <template #warehouse_kode="{ item }">
                        <span class="font-mono">{{ item.warehouse_kode }}</span>
                    </template>
                    <template #qty="{ item }">
                        <span :class="{ 'text-red-600': item.qty < 0, 'text-orange-600': item.is_low_stock && item.qty >= 0 }"> {{ formatQty(item.qty) }} {{ detailData.product.unit_4 }} </span>
                    </template>
                    <template #stock_breakdown="{ item }">
                        <span class="text-xs text-surface-500">{{ formatStockBreakdown(item.qty, detailData.product) }}</span>
                    </template>
                    <template #avg_cost="{ item }">{{ formatCurrency(item.avg_cost) }}</template>
                    <template #total_value="{ item }">{{ formatCurrency(item.total_value) }}</template>
                    <template #stock_status="{ item }">
                        <Tag :value="getStockLabel(item.qty, detailData.product.minimum_stok)" :severity="getStockSeverity(item.qty, detailData.product.minimum_stok)" />
                    </template>
                    <template #warehouse_status="{ item }">
                        <Tag :value="getStatusLabel(item.warehouse_status)" :severity="getStatusSeverity(item.warehouse_status)" />
                    </template>
                </DetailTable>
            </template>

            <template #footer>
                <Button label="Tutup" icon="pi pi-times" text @click="detailDialog = false" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
:deep(.p-datatable-sm .p-datatable-tbody > tr > td) {
    padding: 0.5rem 0.75rem;
}
</style>

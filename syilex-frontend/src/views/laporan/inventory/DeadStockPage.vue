<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { reportsApi, kategorisApi, grupsApi, warehousesApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { downloadBlob } from '@/utils/downloadBlob';
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';

const router = useRouter();

const { formatCurrency, formatQty } = useFormatters();
const notify = useNotification();
const authStore = useAuthStore();

const canExport = computed(() => authStore.can('laporan.export'));

// HPP (avg_cost) & nilai stok = sensitif → hanya tampil bila berizin lihat HPP (backend juga strip field)
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));

const minDaysIdle = ref(60);
const includeNeverSold = ref(true);
const selectedSort = ref('days_desc');
const selectedKategori = ref(null);
const selectedGrup = ref(null);
const selectedWarehouse = ref(null);
const selectedStatus = ref(null);
const minStock = ref(null);
const selectedIsSerial = ref(null); // B3.6 — null=semua, true=serial, false=non-serial
const kategoris = ref([]);
const grups = ref([]);
const warehouses = ref([]);

const statusOptions = [
    { label: 'Aktif', value: 'active' },
    { label: 'Nonaktif', value: 'inactive' }
];

const serialOptions = [
    { label: 'Serial', value: true },
    { label: 'Non-Serial', value: false }
];

const activeFilterCount = computed(() => {
    let n = 0;
    if (minDaysIdle.value !== 60) n++;
    if (!includeNeverSold.value) n++;
    if (selectedSort.value !== 'days_desc') n++;
    if (selectedKategori.value) n++;
    if (selectedGrup.value) n++;
    if (selectedWarehouse.value) n++;
    if (selectedStatus.value) n++;
    if (minStock.value) n++;
    if (selectedIsSerial.value !== null) n++;
    return n;
});

const data = ref({ loading: false, items: [], cutoff_days: 60, total_value: 0, total_products: 0 });
const exportingExcel = ref(false);

const sortOptions = [
    { label: 'Paling Lama Tidak Laku', value: 'days_desc' },
    { label: 'Nilai Stok Terbesar', value: 'value_desc' },
    { label: 'Qty Stok Terbesar', value: 'qty_desc' }
];

const daysOptions = [
    { label: '30 hari', value: 30 },
    { label: '60 hari', value: 60 },
    { label: '90 hari', value: 90 },
    { label: '180 hari', value: 180 },
    { label: '365 hari', value: 365 }
];

function extraFilterParams() {
    const params = {};
    if (selectedKategori.value) params.kategori_id = selectedKategori.value;
    if (selectedGrup.value) params.grup_id = selectedGrup.value;
    if (selectedWarehouse.value) params.warehouse_id = selectedWarehouse.value;
    if (selectedStatus.value) params.status = selectedStatus.value;
    if (minStock.value) params.min_stock = minStock.value;
    if (selectedIsSerial.value !== null) params.is_serial = selectedIsSerial.value ? 1 : 0;
    return params;
}

// B3.6 — deep-link ke Kartu Stok untuk drill-down riwayat mutasi produk.
function goToStockCard(row) {
    router.push({ name: 'inventory-kartu-stok', query: { product_id: row.product_ulid } });
}

async function loadDropdowns() {
    try {
        const [kategorisRes, grupsRes, warehousesRes] = await Promise.all([kategorisApi.getList(), grupsApi.getList(), warehousesApi.getList()]);
        if (kategorisRes.data.success) kategoris.value = kategorisRes.data.data.kategoris ?? [];
        if (grupsRes.data.success) grups.value = grupsRes.data.data.grups ?? [];
        if (warehousesRes.data.success) warehouses.value = warehousesRes.data.data.warehouses ?? [];
    } catch (e) {
        notify.apiError(e, 'Gagal load filter');
    }
}

async function loadData() {
    data.value.loading = true;
    try {
        const params = {
            min_days_idle: minDaysIdle.value,
            include_never_sold: includeNeverSold.value ? 1 : 0,
            sort: selectedSort.value,
            limit: 200,
            ...extraFilterParams()
        };
        const r = await reportsApi.deadStock.list(params);
        if (r.data.success) {
            data.value.items = r.data.data.items;
            data.value.cutoff_days = r.data.data.cutoff_days;
            data.value.total_value = r.data.data.total_value;
            data.value.total_products = r.data.data.total_products;
        }
    } catch (e) {
        notify.apiError(e, 'Gagal load data');
    } finally {
        data.value.loading = false;
    }
}

onMounted(() => {
    loadDropdowns();
    loadData();
});

async function exportExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const params = {
            min_days_idle: minDaysIdle.value,
            include_never_sold: includeNeverSold.value ? 1 : 0,
            sort: selectedSort.value,
            ...extraFilterParams()
        };
        const response = await reportsApi.deadStock.exportExcel(params);
        downloadBlob(response.data, `laporan_dead_stock_${minDaysIdle.value}hr.xlsx`);
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}
</script>

<template>
    <div class="card">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
            <h2 class="text-xl font-bold m-0">Dead Stock</h2>
            <div class="flex gap-2 flex-wrap items-center">
                <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                <ListFiltersSheet :active-count="activeFilterCount">
                    <Select v-model="minDaysIdle" :options="daysOptions" optionLabel="label" optionValue="value" @change="loadData" />
                    <div class="flex items-center gap-2 px-3 bg-surface-100 dark:bg-surface-800 rounded h-full">
                        <Checkbox v-model="includeNeverSold" :binary="true" inputId="neverSold" @change="loadData" />
                        <label for="neverSold" class="text-sm cursor-pointer whitespace-nowrap">Termasuk never sold</label>
                    </div>
                    <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" @change="loadData" />
                    <Select v-model="selectedKategori" :options="kategoris" optionLabel="nama_kategori" optionValue="id" placeholder="Kategori" filter showClear @change="loadData" />
                    <Select v-model="selectedGrup" :options="grups" optionLabel="nama_grup" optionValue="id" placeholder="Grup" filter showClear @change="loadData" />
                    <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Warehouse" filter showClear @change="loadData" />
                    <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" showClear @change="loadData" />
                    <Select v-model="selectedIsSerial" :options="serialOptions" optionLabel="label" optionValue="value" placeholder="Tipe Produk" showClear @change="loadData" />
                    <InputNumber v-model="minStock" placeholder="Min Stok" :min="0" class="w-32" @update:modelValue="loadData" />
                </ListFiltersSheet>
            </div>
        </div>

        <!-- Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
            <div class="summary-stat-card bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                <div class="text-xs text-red-600 mb-1">Total Dead Stock</div>
                <div class="summary-money-value text-red-700">{{ data.total_products }} produk</div>
            </div>
            <div v-if="canViewHpp" class="summary-stat-card bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4">
                <div class="text-xs text-orange-600 mb-1">Nilai Stok Terpendam</div>
                <div class="summary-money-value text-orange-700">{{ formatCurrency(data.total_value) }}</div>
            </div>
            <div class="bg-surface-100 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-xs text-surface-500 mb-1">Cutoff</div>
                <div class="summary-money-value">≥ {{ data.cutoff_days }} hari</div>
            </div>
        </div>

        <DataTable :value="data.items" :loading="data.loading" stripedRows scrollable>
            <template #empty>
                <div class="py-6 text-center text-surface-500">
                    <i class="pi pi-check-circle text-green-500 text-2xl mb-2"></i>
                    <div>Tidak ada dead stock dalam filter ini.</div>
                </div>
            </template>
            <Column header="Produk">
                <template #body="{ data: r }">
                    <div class="font-medium">{{ r.kode_produk }}</div>
                    <div class="text-xs text-surface-500">{{ r.nama_produk }}</div>
                </template>
            </Column>
            <Column field="kategori" header="Kategori" style="width: 150px" />
            <Column field="grup" header="Grup" style="width: 130px" />
            <Column header="Tipe" style="width: 100px">
                <template #body="{ data: r }">
                    <Tag :value="r.is_serial ? 'Serial' : 'Non-Serial'" :severity="r.is_serial ? 'info' : 'secondary'" />
                </template>
            </Column>
            <Column field="stock_qty" header="Stok" bodyClass="text-right" style="width: 120px">
                <template #body="{ data: r }">{{ formatQty(r.stock_qty) }}</template>
            </Column>
            <Column v-if="canViewHpp" field="stock_value" header="Nilai" bodyClass="text-right" style="width: 140px">
                <template #body="{ data: r }">{{ formatCurrency(r.stock_value) }}</template>
            </Column>
            <Column header="Last Sold" style="width: 140px">
                <template #body="{ data: r }">
                    <div v-if="r.never_sold">
                        <Tag value="Never Sold" severity="danger" />
                    </div>
                    <div v-else>
                        <div class="text-xs">{{ r.last_sold }}</div>
                        <div class="text-xs text-red-600">{{ r.days_idle }} hari</div>
                    </div>
                </template>
            </Column>
            <Column header="" style="width: 60px" alignFrozen="right" frozen>
                <template #body="{ data: r }">
                    <Button icon="pi pi-external-link" text rounded size="small" @click="goToStockCard(r)" v-tooltip.top="'Lihat Kartu Stok'" aria-label="Lihat Kartu Stok" />
                </template>
            </Column>
        </DataTable>
    </div>
</template>

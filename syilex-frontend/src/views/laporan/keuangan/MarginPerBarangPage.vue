<script setup>
import { ref, computed, onMounted } from 'vue';
import { reportsApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { downloadBlob } from '@/utils/downloadBlob';

const authStore = useAuthStore();
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));
const canExport = computed(() => authStore.can('laporan.export'));

const { formatCurrency } = useFormatters();
const notify = useNotification();

const exportingExcel = ref(false);

const summary = ref({});
const items = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

const lazyParams = ref({ first: 0, rows: 25 });
const selectedBucket = ref('any');
const searchQuery = ref('');
const selectedSort = ref('margin_asc');

const bucketOptions = [
    { label: 'Semua', value: 'any' },
    { label: 'Rendah (<10%)', value: 'low' },
    { label: 'Sedang (10-20%)', value: 'medium' },
    { label: 'Tinggi (>20%)', value: 'high' }
];

const sortOptions = [
    { label: 'Margin Terkecil Dulu', value: 'margin_asc' },
    { label: 'Margin Terbesar Dulu', value: 'margin_desc' },
    { label: 'Kode A-Z', value: 'kode_asc' },
    { label: 'Nama A-Z', value: 'nama_asc' }
];

async function loadSummary() {
    try {
        const r = await reportsApi.marginPerBarang.summary();
        if (r.data.success) summary.value = r.data.data;
    } catch (e) {
        notify.apiError(e, 'Gagal load summary');
    }
}

async function loadList() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            margin_bucket: selectedBucket.value,
            sort: selectedSort.value
        };
        if (searchQuery.value) params.search = searchQuery.value;

        const r = await reportsApi.marginPerBarang.list(params);
        if (r.data.success) {
            items.value = r.data.data.items;
            totalRecords.value = r.data.data.pagination.total;
        }
    } catch (e) {
        notify.apiError(e, 'Gagal load data');
    } finally {
        loading.value = false;
    }
}

function onPage(e) {
    lazyParams.value.first = e.first;
    lazyParams.value.rows = e.rows;
    loadList();
}

function onFilterChange() {
    lazyParams.value.first = 0;
    loadList();
}

onMounted(() => {
    loadSummary();
    loadList();
});

async function exportExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const response = await reportsApi.marginPerBarang.exportExcel();
        downloadBlob(response.data, 'laporan_margin_per_barang.xlsx');
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

function marginSeverity(pct) {
    pct = parseFloat(pct);
    if (pct < 10) return 'danger';
    if (pct < 20) return 'warn';
    return 'success';
}
</script>

<template>
    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h2 class="text-xl font-bold">Margin per Barang</h2>
            <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
        </div>

        <!-- Summary -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3">
                <div class="text-xs text-surface-500 mb-1">Total Produk</div>
                <div class="text-xl font-bold">{{ summary.total_produk || 0 }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3">
                <div class="text-xs text-surface-500 mb-1">Tanpa Harga</div>
                <div class="text-xl font-bold text-surface-600">{{ summary.tanpa_harga || 0 }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                <div class="text-xs text-red-600 mb-1">Margin Rendah</div>
                <div class="text-xl font-bold text-red-600">{{ summary.margin_rendah || 0 }}</div>
            </div>
            <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-3">
                <div class="text-xs text-yellow-600 mb-1">Margin Sedang</div>
                <div class="text-xl font-bold text-yellow-600">{{ summary.margin_sedang || 0 }}</div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3">
                <div class="text-xs text-green-600 mb-1">Margin Tinggi</div>
                <div class="text-xl font-bold text-green-600">{{ summary.margin_tinggi || 0 }}</div>
            </div>
            <div class="bg-red-100 dark:bg-red-900/30 rounded-lg p-3 border border-red-300">
                <div class="text-xs text-red-700 mb-1">⚠️ Rugi Margin</div>
                <div class="text-xl font-bold text-red-700">{{ summary.rugi_margin || 0 }}</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-2 mb-4">
            <IconField class="flex-1 min-w-[240px]">
                <InputIcon class="pi pi-search" />
                <InputText v-model="searchQuery" placeholder="Cari kode atau nama..." @input="onFilterChange" class="w-full" />
            </IconField>
            <Select v-model="selectedBucket" :options="bucketOptions" optionLabel="label" optionValue="value" class="w-44" @change="onFilterChange" />
            <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" class="w-52" @change="onFilterChange" />
        </div>

        <DataTable :value="items" :loading="loading" :lazy="true" :paginator="true" :rows="lazyParams.rows" :totalRecords="totalRecords" :first="lazyParams.first" :rowsPerPageOptions="[25, 50, 100]" @page="onPage" stripedRows>
            <template #empty>
                <div class="py-6 text-center text-surface-500">Tidak ada data.</div>
            </template>
            <Column field="kode_produk" header="Kode" style="width: 120px" />
            <Column field="nama_produk" header="Nama Produk" />
            <Column field="nama_kategori" header="Kategori" style="width: 140px" />
            <Column v-if="canViewHpp" field="avg_cost" header="HPP" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.avg_cost) }}</template>
            </Column>
            <Column field="harga_jual" header="Harga Jual" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.harga_jual) }}</template>
            </Column>
            <Column v-if="canViewHpp" field="margin_nominal" header="Margin" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.margin_nominal) }}</template>
            </Column>
            <Column v-if="canViewHpp" field="margin_percent" header="Margin %" bodyClass="text-right" style="width: 120px">
                <template #body="{ data }">
                    <Tag :value="`${data.margin_percent}%`" :severity="marginSeverity(data.margin_percent)" />
                </template>
            </Column>
        </DataTable>
    </div>
</template>

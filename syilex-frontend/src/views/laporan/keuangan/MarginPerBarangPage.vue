<script setup>
import { ref, computed, onMounted } from 'vue';
import { reportsApi, brandsApi, tipesApi, kategorisApi, grupsApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import { downloadBlob } from '@/utils/downloadBlob';
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import MoneySummaryPanel from '@/components/common/MoneySummaryPanel.vue';

const authStore = useAuthStore();
const settingsStore = useSettingsStore();
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));
const canExport = computed(() => authStore.can('laporan.export'));
const serialEnabled = computed(() => settingsStore.serialEnabled);

const { formatCurrency } = useFormatters();
const notify = useNotification();
const { exporting: exportingPdf, exportListPdf } = useExportPdf();

const exportingExcel = ref(false);
const expandedRows = ref({});

const summary = ref({});
const items = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

const lazyParams = ref({ first: 0, rows: 25 });
const selectedBucket = ref('any');
const searchQuery = ref('');
const selectedSort = ref('nama_asc');
const selectedBrand = ref(null);
const selectedTipe = ref(null);
const selectedKategori = ref(null);
const selectedGrup = ref(null);
const selectedStatus = ref(null);
const selectedPriceField = ref(null);
const brands = ref([]);
const tipes = ref([]);
const kategoris = ref([]);
const grups = ref([]);

const statusOptions = [
    { label: 'Aktif', value: 'active' },
    { label: 'Nonaktif', value: 'inactive' }
];

const priceFieldOptions = [
    { label: 'Harga 1', value: 'harga_1' },
    { label: 'Harga 2', value: 'harga_2' },
    { label: 'Harga 3', value: 'harga_3' },
    { label: 'Harga 4 (Default)', value: 'harga_4' }
];

const summaryItems = computed(() => [
    { label: 'Total Produk', value: String(summary.value.total_produk || 0), hint: 'Produk active (atau filter status) · snapshot setup, bukan margin nota' },
    { label: 'Tanpa Harga', value: String(summary.value.tanpa_harga || 0), hint: 'Harga jual efektif ≤ 0 (retail: field harga; serial: AVG unit tersedia)', tone: 'default' },
    { label: 'Margin Rendah', value: String(summary.value.margin_rendah || 0), hint: 'Margin % < 10%', tone: 'danger' },
    { label: 'Margin Sedang', value: String(summary.value.margin_sedang || 0), hint: 'Margin 10%–20%', tone: 'warn' },
    { label: 'Margin Tinggi', value: String(summary.value.margin_tinggi || 0), hint: 'Margin > 20%', tone: 'success' },
    { label: 'Rugi Margin', value: String(summary.value.rugi_margin || 0), hint: 'Harga jual < HPP/modal', tone: 'danger' }
]);

const activeFilterCount = computed(() => {
    let n = 0;
    if (selectedBucket.value && selectedBucket.value !== 'any') n++;
    if (selectedSort.value && selectedSort.value !== 'nama_asc') n++;
    if (selectedBrand.value) n++;
    if (selectedTipe.value) n++;
    if (selectedKategori.value) n++;
    if (selectedGrup.value) n++;
    if (selectedStatus.value) n++;
    if (selectedPriceField.value) n++;
    return n;
});

const bucketOptions = [
    { label: 'Semua', value: 'any' },
    { label: 'Rendah (<10%)', value: 'low' },
    { label: 'Sedang (10-20%)', value: 'medium' },
    { label: 'Tinggi (>20%)', value: 'high' }
];

const sortOptions = [
    { label: 'Nama A-Z', value: 'nama_asc' },
    { label: 'Margin Terkecil Dulu', value: 'margin_asc' },
    { label: 'Margin Terbesar Dulu', value: 'margin_desc' },
    { label: 'Kode A-Z', value: 'kode_asc' }
];

function extraFilterParams() {
    const params = {};
    if (selectedBrand.value) params.brand_id = selectedBrand.value;
    if (selectedTipe.value) params.tipe_id = selectedTipe.value;
    if (selectedKategori.value) params.kategori_id = selectedKategori.value;
    if (selectedGrup.value) params.grup_id = selectedGrup.value;
    if (selectedStatus.value) params.status = selectedStatus.value;
    if (selectedPriceField.value) params.price_field = selectedPriceField.value;
    return params;
}

function onRowExpand(event) {
    if (!event.data?.is_serial) {
        const key = event.data?.ulid;
        if (key && expandedRows.value[key]) {
            const next = { ...expandedRows.value };
            delete next[key];
            expandedRows.value = next;
        }
    }
}

async function loadDropdowns() {
    try {
        const [brandsRes, tipesRes, kategorisRes, grupsRes] = await Promise.all([brandsApi.getList(), tipesApi.getList(), kategorisApi.getList(), grupsApi.getList()]);
        if (brandsRes.data.success) brands.value = brandsRes.data.data.brands ?? [];
        if (tipesRes.data.success) tipes.value = tipesRes.data.data.tipes ?? [];
        if (kategorisRes.data.success) kategoris.value = kategorisRes.data.data.kategoris ?? [];
        if (grupsRes.data.success) grups.value = grupsRes.data.data.grups ?? [];
    } catch (e) {
        notify.apiError(e, 'Gagal load filter');
    }
}

async function loadSummary() {
    try {
        const r = await reportsApi.marginPerBarang.summary(extraFilterParams());
        if (r.data.success) summary.value = r.data.data;
    } catch (e) {
        notify.apiError(e, 'Gagal load summary');
    }
}

async function loadList() {
    loading.value = true;
    expandedRows.value = {};
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            margin_bucket: selectedBucket.value,
            sort: selectedSort.value,
            ...extraFilterParams()
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
    loadSummary();
    loadList();
}

onMounted(() => {
    loadDropdowns();
    loadSummary();
    loadList();
});

async function exportExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const response = await reportsApi.marginPerBarang.exportExcel({
            margin_bucket: selectedBucket.value,
            sort: selectedSort.value,
            search: searchQuery.value || undefined,
            ...extraFilterParams()
        });
        downloadBlob(response.data, 'laporan_margin_per_barang.xlsx');
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

async function exportPdf() {
    if (!canExport.value) return;
    try {
        const params = {
            margin_bucket: selectedBucket.value,
            sort: selectedSort.value,
            search: searchQuery.value || undefined,
            per_page: 100,
            page: 1,
            ...extraFilterParams()
        };
        // Flatten pages for PDF (cap 500 rows product pages → units expanded client-side)
        const flat = [];
        let page = 1;
        let lastPage = 1;
        do {
            params.page = page;
            const r = await reportsApi.marginPerBarang.list(params);
            if (!r.data.success) break;
            const chunk = r.data.data.items || [];
            lastPage = r.data.data.pagination?.last_page || 1;
            for (const p of chunk) {
                if (p.is_serial && Array.isArray(p.units) && p.units.length) {
                    flat.push({
                        tipe: 'SERIAL',
                        kode_produk: p.kode_produk,
                        nama_produk: p.nama_produk,
                        kode_internal: '-',
                        serial_number: '-',
                        avg_cost: p.avg_cost,
                        harga_jual: p.harga_jual,
                        margin_nominal: p.margin_nominal,
                        margin_percent: p.tanpa_harga ? 'Tanpa harga' : `${p.margin_percent}%`
                    });
                    for (const u of p.units) {
                        flat.push({
                            tipe: 'UNIT',
                            kode_produk: p.kode_produk,
                            nama_produk: p.nama_produk,
                            kode_internal: u.kode_internal || '-',
                            serial_number: u.serial_number || '-',
                            avg_cost: u.cost_per_unit,
                            harga_jual: u.harga_jual,
                            margin_nominal: u.margin_nominal,
                            margin_percent: u.tanpa_harga ? 'Tanpa harga' : `${u.margin_percent}%`
                        });
                    }
                } else {
                    flat.push({
                        tipe: p.is_serial ? 'SERIAL' : 'RETAIL',
                        kode_produk: p.kode_produk,
                        nama_produk: p.nama_produk,
                        kode_internal: '-',
                        serial_number: '-',
                        avg_cost: p.avg_cost,
                        harga_jual: p.harga_jual,
                        margin_nominal: p.margin_nominal,
                        margin_percent: p.tanpa_harga ? 'Tanpa harga' : `${p.margin_percent}%`
                    });
                }
            }
            page++;
        } while (page <= lastPage && page <= 5);

        const cols = [
            { header: 'Tipe', field: 'tipe', width: 16 },
            { header: 'Kode', field: 'kode_produk', width: 20 },
            { header: 'Nama', field: 'nama_produk', width: 40 },
            { header: 'Kode Int.', field: 'kode_internal', width: 20 },
            { header: 'SN', field: 'serial_number', width: 30 }
        ];
        if (canViewHpp.value) {
            cols.push(
                { header: 'HPP/Modal', field: 'avg_cost', width: 28, align: 'right', accessor: (row) => formatCurrency(row.avg_cost) },
                { header: 'Harga', field: 'harga_jual', width: 28, align: 'right', accessor: (row) => formatCurrency(row.harga_jual) },
                { header: 'Margin', field: 'margin_nominal', width: 28, align: 'right', accessor: (row) => formatCurrency(row.margin_nominal) },
                { header: 'Margin %', field: 'margin_percent', width: 20, align: 'right' }
            );
        } else {
            cols.push(
                { header: 'Harga', field: 'harga_jual', width: 28, align: 'right', accessor: (row) => formatCurrency(row.harga_jual) }
            );
        }

        await exportListPdf({
            title: 'Laporan Margin per Barang',
            filename: 'laporan_margin_per_barang',
            columns: cols,
            data: flat,
            filters: 'Snapshot setup (bukan margin nota)'
        });
    } catch (e) {
        notify.apiError(e, 'Gagal export PDF');
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
            <div class="flex gap-2">
                <Button v-if="canExport" icon="pi pi-file-pdf" severity="secondary" outlined :loading="exportingPdf" @click="exportPdf" v-tooltip.top="'Export PDF'" aria-label="Export PDF" />
                <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
            </div>
        </div>

        <MoneySummaryPanel title="Ringkasan Margin" :items="summaryItems" :cols="6" :primary-index="5" />

        <div class="flex flex-wrap gap-2 mb-4 items-center">
            <IconField class="flex-1 min-w-[240px]">
                <InputIcon class="pi pi-search" />
                <InputText v-model="searchQuery" placeholder="Cari kode atau nama..." @input="onFilterChange" class="w-full" />
            </IconField>
            <ListFiltersSheet :active-count="activeFilterCount">
                <Select v-model="selectedBucket" :options="bucketOptions" optionLabel="label" optionValue="value" @change="onFilterChange" />
                <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" @change="onFilterChange" />
                <Select v-model="selectedBrand" :options="brands" optionLabel="nama_brand" optionValue="id" placeholder="Brand" filter showClear @change="onFilterChange" />
                <Select v-model="selectedTipe" :options="tipes" optionLabel="nama_tipe" optionValue="id" placeholder="Tipe" filter showClear @change="onFilterChange" />
                <Select v-model="selectedKategori" :options="kategoris" optionLabel="nama_kategori" optionValue="id" placeholder="Kategori" filter showClear @change="onFilterChange" />
                <Select v-model="selectedGrup" :options="grups" optionLabel="nama_grup" optionValue="id" placeholder="Grup" filter showClear @change="onFilterChange" />
                <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" showClear @change="onFilterChange" />
                <Select v-model="selectedPriceField" :options="priceFieldOptions" optionLabel="label" optionValue="value" placeholder="Field Harga (retail)" showClear @change="onFilterChange" />
            </ListFiltersSheet>
        </div>

        <DataTable
            v-model:expandedRows="expandedRows"
            :value="items"
            :loading="loading"
            :lazy="true"
            :paginator="true"
            :rows="lazyParams.rows"
            :totalRecords="totalRecords"
            :first="lazyParams.first"
            :rowsPerPageOptions="[25, 50, 100]"
            dataKey="ulid"
            stripedRows
            @page="onPage"
            @rowExpand="onRowExpand"
        >
            <template #empty>
                <div class="py-6 text-center text-surface-500">Tidak ada data.</div>
            </template>

            <Column v-if="serialEnabled" expander style="width: 3rem" />
            <Column field="kode_produk" header="Kode" style="width: 120px" />
            <Column field="nama_produk" header="Nama Produk">
                <template #body="{ data }">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span>{{ data.nama_produk }}</span>
                        <Tag v-if="serialEnabled && data.is_serial" value="SERIAL" severity="help" class="text-xs" />
                        <Tag v-else-if="serialEnabled" value="RETAIL" severity="secondary" class="text-xs" />
                        <span v-if="data.is_serial" class="text-xs text-surface-500">{{ data.unit_count || 0 }} unit</span>
                    </div>
                </template>
            </Column>
            <Column field="nama_kategori" header="Kategori" style="width: 140px" />
            <Column v-if="canViewHpp" field="avg_cost" header="HPP / Modal" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.avg_cost) }}</template>
            </Column>
            <Column field="harga_jual" header="Harga Jual" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.harga_jual) }}</template>
            </Column>
            <Column v-if="canViewHpp" field="margin_nominal" header="Margin" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.margin_nominal) }}</template>
            </Column>
            <Column v-if="canViewHpp" field="margin_percent" header="Margin %" bodyClass="text-right" style="width: 130px">
                <template #body="{ data }">
                    <Tag v-if="data.tanpa_harga" value="Tanpa harga" severity="secondary" />
                    <Tag v-else :value="`${data.margin_percent}%`" :severity="marginSeverity(data.margin_percent)" />
                </template>
            </Column>

            <template #expansion="{ data }">
                <div v-if="data.is_serial" class="p-3">
                    <h5 class="font-semibold mb-2 text-sm">Unit serial — {{ data.nama_produk }}</h5>
                    <DataTable :value="data.units || []" class="p-datatable-sm" size="small">
                        <template #empty>
                            <div class="py-3 text-center text-surface-500 text-sm">Tidak ada unit tersedia.</div>
                        </template>
                        <Column field="kode_internal" header="Kode Internal" style="min-width: 120px" />
                        <Column field="serial_number" header="SN" style="min-width: 120px" />
                        <Column v-if="canViewHpp" field="cost_per_unit" header="Modal" bodyClass="text-right">
                            <template #body="{ data: u }">{{ formatCurrency(u.cost_per_unit) }}</template>
                        </Column>
                        <Column field="harga_jual" header="Harga Jual" bodyClass="text-right">
                            <template #body="{ data: u }">{{ formatCurrency(u.harga_jual) }}</template>
                        </Column>
                        <Column v-if="canViewHpp" field="margin_nominal" header="Margin" bodyClass="text-right">
                            <template #body="{ data: u }">{{ formatCurrency(u.margin_nominal) }}</template>
                        </Column>
                        <Column v-if="canViewHpp" header="Margin %" bodyClass="text-right" style="width: 120px">
                            <template #body="{ data: u }">
                                <Tag v-if="u.tanpa_harga" value="Tanpa harga" severity="secondary" />
                                <Tag v-else :value="`${u.margin_percent}%`" :severity="marginSeverity(u.margin_percent)" />
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </template>
        </DataTable>
    </div>
</template>

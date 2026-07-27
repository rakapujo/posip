<script setup>
import ListFiltersSheet from '@/components/common/ListFiltersSheet.vue';
import MoneySummaryPanel from '@/components/common/MoneySummaryPanel.vue';
import { ref, computed, onMounted } from 'vue';
import { reportsApi, salesProductReportApi, warehousesApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import { downloadBlob } from '@/utils/downloadBlob';

const authStore = useAuthStore();
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));
const canExport = computed(() => authStore.can('laporan.export'));

const { formatCurrency, getPrimeDateFormatShort, toDateString } = useFormatters();
const notify = useNotification();
const { exporting: exportingPdf, exportListPdf } = useExportPdf();

const exportingExcel = ref(false);

const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const endDate = ref(new Date());
const selectedTerminal = ref(null);
const selectedKategori = ref(null);
const selectedWarehouse = ref(null);
const terminals = ref([]);
const kategoris = ref([]);
const warehouses = ref([]);

const summary = ref({ loading: false });
const byKategori = ref({ loading: false, items: [] });
const topProducts = ref({ loading: false, items: [] });
const daily = ref({ loading: false, items: [] });

const summaryItems = computed(() => {
    const trx = summary.value.trx_count || 0;
    const items = [
        {
            label: 'Setelah dikurangi retur',
            value: formatCurrency(summary.value.revenue_net || 0),
            hint: `Sama dengan card di Penjualan → Per Barang · ${trx} transaksi`,
            tone: 'info'
        }
    ];
    if (canViewHpp.value) {
        items.push(
            {
                label: 'Modal barang',
                value: formatCurrency(summary.value.hpp_net || 0),
                hint: 'Setelah dikurangi modal barang yang dikembalikan',
                tone: 'orange'
            },
            {
                label: 'Perkiraan untung',
                value: formatCurrency(summary.value.gross_profit || 0),
                hint: 'Setelah dikurangi retur − modal barang',
                tone: 'success'
            }
        );
    }
    items.push({
        label: 'Margin %',
        value: `${(summary.value.margin_percent || 0).toFixed(2)}%`,
        hint: 'Perkiraan untung ÷ setelah dikurangi retur × 100',
        tone: (summary.value.margin_percent || 0) < 10 ? 'danger' : (summary.value.margin_percent || 0) < 20 ? 'warn' : 'success'
    });
    return items;
});

const activeFilterCount = computed(() => {
    let n = 0;
    if (startDate.value) n++;
    if (endDate.value) n++;
    if (selectedTerminal.value) n++;
    if (selectedKategori.value) n++;
    if (selectedWarehouse.value) n++;
    return n;
});

async function loadDropdowns() {
    try {
        const [r, whRes] = await Promise.all([salesProductReportApi.getDropdowns(), warehousesApi.getList()]);
        if (r.data.success) {
            terminals.value = r.data.data.terminals ?? [];
            kategoris.value = r.data.data.kategoris ?? [];
        }
        if (whRes.data.success) warehouses.value = whRes.data.data.warehouses ?? [];
    } catch (e) {
        notify.apiError(e, 'Gagal load filter');
    }
}

function baseParams() {
    const params = {
        date_from: toDateString(startDate.value),
        date_to: toDateString(endDate.value)
    };
    if (selectedTerminal.value) params.terminal_id = selectedTerminal.value;
    if (selectedKategori.value) params.kategori_id = selectedKategori.value;
    if (selectedWarehouse.value) params.warehouse_id = selectedWarehouse.value;
    return params;
}

async function loadAll() {
    const params = baseParams();
    await Promise.all([loadSummary(params), loadByKategori(params), loadTopProducts(params), loadDaily(params)]);
}

async function loadSummary(params) {
    summary.value.loading = true;
    try {
        const r = await reportsApi.grossProfit.summary(params);
        if (r.data.success) Object.assign(summary.value, r.data.data);
    } catch (e) {
        notify.apiError(e, 'Gagal load summary');
    } finally {
        summary.value.loading = false;
    }
}

async function loadByKategori(params) {
    byKategori.value.loading = true;
    try {
        const r = await reportsApi.grossProfit.byKategori(params);
        if (r.data.success) byKategori.value.items = r.data.data.items;
    } catch (e) {
        notify.apiError(e, 'Gagal load per kategori');
    } finally {
        byKategori.value.loading = false;
    }
}

async function loadTopProducts(params) {
    topProducts.value.loading = true;
    try {
        const r = await reportsApi.grossProfit.topProducts({ ...params, limit: 10 });
        if (r.data.success) topProducts.value.items = r.data.data.items;
    } catch (e) {
        notify.apiError(e, 'Gagal load top products');
    } finally {
        topProducts.value.loading = false;
    }
}

async function loadDaily(params) {
    daily.value.loading = true;
    try {
        const r = await reportsApi.grossProfit.daily(params);
        if (r.data.success) daily.value.items = r.data.data.items;
    } catch (e) {
        notify.apiError(e, 'Gagal load daily');
    } finally {
        daily.value.loading = false;
    }
}

onMounted(() => {
    loadDropdowns();
    loadAll();
});

async function exportDailyExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const params = baseParams();
        const response = await reportsApi.grossProfit.exportDaily(params);
        downloadBlob(response.data, `laporan_gross_profit_harian_${params.date_from}.xlsx`);
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

// B3.1 — PDF summary enough: export trend harian (client-side, sama pola dengan Per Nota).
function exportDailyPdf() {
    const columns = [
        { header: 'Tanggal', field: 'tanggal', width: 28 },
        { header: 'Revenue', width: 42, align: 'right', accessor: (row) => formatCurrency(row.revenue) }
    ];
    if (canViewHpp.value) {
        columns.push({ header: 'HPP', width: 42, align: 'right', accessor: (row) => formatCurrency(row.hpp) });
    }
    columns.push(
        { header: 'Profit', width: 42, align: 'right', accessor: (row) => formatCurrency(row.profit) },
        { header: 'Margin %', width: 30, align: 'right', accessor: (row) => `${row.margin_percent}%` },
        { header: 'Trx', field: 'trx_count', width: 24, align: 'right' }
    );

    exportListPdf({
        title: 'Laporan Gross Profit Harian',
        filename: `laporan_gross_profit_harian_${baseParams().date_from}`,
        columns,
        data: daily.value.items,
        totalLabel: `Ringkasan periode — Revenue: ${formatCurrency(summary.value.revenue_net || 0)} · Profit: ${formatCurrency(summary.value.gross_profit || 0)} · Margin: ${(summary.value.margin_percent || 0).toFixed(2)}%`
    });
}

async function exportByKategoriExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const params = baseParams();
        const response = await reportsApi.grossProfit.exportByKategori(params);
        downloadBlob(response.data, `laporan_gross_profit_kategori_${params.date_from}.xlsx`);
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

async function exportTopProductsExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const params = { ...baseParams(), limit: 10 };
        const response = await reportsApi.grossProfit.exportTopProducts(params);
        downloadBlob(response.data, `laporan_gross_profit_top_produk_${params.date_from}.xlsx`);
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

function marginClass(pct) {
    if (pct < 10) return 'text-red-600';
    if (pct < 20) return 'text-yellow-600';
    return 'text-green-600';
}
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Laporan Gross Profit</span>
            </template>
            <template #end>
                <ListFiltersSheet :active-count="activeFilterCount">
                    <div class="list-filter-control">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <div class="list-filter-control">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <div class="list-filter-control">
                        <Select v-model="selectedTerminal" :options="terminals" optionLabel="nama_terminal" optionValue="id" placeholder="Terminal" filter showClear fluid @change="loadAll" />
                    </div>
                    <div class="list-filter-control">
                        <Select v-model="selectedKategori" :options="kategoris" optionLabel="nama_kategori" optionValue="id" placeholder="Kategori" filter showClear fluid @change="loadAll" />
                    </div>
                    <div class="list-filter-control">
                        <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Warehouse" filter showClear fluid @change="loadAll" />
                    </div>
                    <Button icon="pi pi-refresh" outlined @click="loadAll" v-tooltip.top="'Refresh'" aria-label="Refresh" />
                    <Button v-if="canExport" icon="pi pi-file-pdf" severity="secondary" outlined :loading="exportingPdf" @click="exportDailyPdf" v-tooltip.top="'Export PDF (Harian)'" aria-label="Export PDF" />
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportDailyExcel" v-tooltip.top="'Export Excel (Harian)'" aria-label="Export Excel" />
                </ListFiltersSheet>
            </template>
        </Toolbar>

        <Message severity="info" :closable="false" class="mb-4">
            Angka <strong>Setelah dikurangi retur</strong> di sini sama dengan card serupa di
            <RouterLink :to="{ name: 'laporan-penjualan-per-barang' }" class="underline font-medium">Penjualan → Per Barang</RouterLink>
            (filter tanggal / terminal / gudang yang sama, tanpa filter brand).
        </Message>

        <!-- Summary -->
        <MoneySummaryPanel title="Ringkasan Gross Profit" :items="summaryItems" :cols="summaryItems.length" :primary-index="0" />

        <!-- Per Kategori + Top Products -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="font-semibold m-0">Per Kategori</h3>
                    <Button
                        v-if="canExport"
                        icon="pi pi-file-excel"
                        severity="success"
                        outlined
                        size="small"
                        :loading="exportingExcel"
                        @click="exportByKategoriExcel"
                        v-tooltip.top="'Export Excel (Per Kategori)'"
                        aria-label="Export Excel Per Kategori"
                    />
                </div>
                <DataTable :value="byKategori.items" :loading="byKategori.loading" stripedRows>
                    <template #empty>
                        <div class="py-4 text-center text-surface-500">Belum ada data.</div>
                    </template>
                    <Column field="nama_kategori" header="Kategori" />
                    <Column field="revenue" header="Revenue" bodyClass="text-right">
                        <template #body="{ data }">{{ formatCurrency(data.revenue) }}</template>
                    </Column>
                    <Column field="profit" header="Profit" bodyClass="text-right">
                        <template #body="{ data }">{{ formatCurrency(data.profit) }}</template>
                    </Column>
                    <Column field="margin_percent" header="Margin" bodyClass="text-right">
                        <template #body="{ data }">
                            <span :class="marginClass(data.margin_percent)">{{ data.margin_percent }}%</span>
                        </template>
                    </Column>
                </DataTable>
            </div>
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="font-semibold m-0">Top 10 Produk by Profit</h3>
                    <Button
                        v-if="canExport"
                        icon="pi pi-file-excel"
                        severity="success"
                        outlined
                        size="small"
                        :loading="exportingExcel"
                        @click="exportTopProductsExcel"
                        v-tooltip.top="'Export Excel (Top Produk)'"
                        aria-label="Export Excel Top Produk"
                    />
                </div>
                <DataTable :value="topProducts.items" :loading="topProducts.loading" stripedRows>
                    <template #empty>
                        <div class="py-4 text-center text-surface-500">Belum ada data.</div>
                    </template>
                    <Column header="#" style="width: 40px">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>
                    <Column field="nama_produk" header="Produk">
                        <template #body="{ data }">
                            <div class="font-medium">{{ data.kode_produk }}</div>
                            <div class="text-xs text-surface-500">{{ data.nama_produk }}</div>
                        </template>
                    </Column>
                    <Column field="profit" header="Profit" bodyClass="text-right">
                        <template #body="{ data }">{{ formatCurrency(data.profit) }}</template>
                    </Column>
                    <Column field="margin_percent" header="Margin" bodyClass="text-right">
                        <template #body="{ data }">
                            <span :class="marginClass(data.margin_percent)">{{ data.margin_percent }}%</span>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>

        <!-- Daily Trend -->
        <div>
            <h3 class="font-semibold mb-2">Trend Harian</h3>
            <DataTable :value="daily.items" :loading="daily.loading" stripedRows scrollable scrollHeight="400px">
                <template #empty>
                    <div class="py-4 text-center text-surface-500">Belum ada data.</div>
                </template>
                <Column field="tanggal" header="Tanggal" />
                <Column field="revenue" header="Revenue" bodyClass="text-right">
                    <template #body="{ data }">{{ formatCurrency(data.revenue) }}</template>
                </Column>
                <Column v-if="canViewHpp" field="hpp" header="HPP" bodyClass="text-right">
                    <template #body="{ data }">{{ formatCurrency(data.hpp) }}</template>
                </Column>
                <Column field="profit" header="Profit" bodyClass="text-right">
                    <template #body="{ data }">{{ formatCurrency(data.profit) }}</template>
                </Column>
                <Column field="margin_percent" header="Margin" bodyClass="text-right">
                    <template #body="{ data }">
                        <span :class="marginClass(data.margin_percent)">{{ data.margin_percent }}%</span>
                    </template>
                </Column>
                <Column field="trx_count" header="Trx" bodyClass="text-right" />
            </DataTable>
        </div>
    </div>
</template>

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

const { formatCurrency, getPrimeDateFormatShort, toDateString } = useFormatters();
const notify = useNotification();

const exportingExcel = ref(false);

const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const endDate = ref(new Date());

const summary = ref({ loading: false });
const byKategori = ref({ loading: false, items: [] });
const topProducts = ref({ loading: false, items: [] });
const daily = ref({ loading: false, items: [] });

async function loadAll() {
    const params = {
        date_from: toDateString(startDate.value),
        date_to: toDateString(endDate.value)
    };
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

onMounted(loadAll);

async function exportDailyExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const params = {
            date_from: toDateString(startDate.value),
            date_to: toDateString(endDate.value)
        };
        const response = await reportsApi.grossProfit.exportDaily(params);
        downloadBlob(response.data, `laporan_gross_profit_harian_${params.date_from}.xlsx`);
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

function exportParams() {
    return {
        date_from: toDateString(startDate.value),
        date_to: toDateString(endDate.value)
    };
}

async function exportByKategoriExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const params = exportParams();
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
        const params = { ...exportParams(), limit: 10 };
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
                <div class="flex flex-wrap gap-2 items-center">
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <Button icon="pi pi-refresh" outlined @click="loadAll" v-tooltip.top="'Refresh'" aria-label="Refresh" />
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportDailyExcel" v-tooltip.top="'Export Excel (Harian)'" aria-label="Export Excel" />
                </div>
            </template>
        </Toolbar>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-blue-600 dark:text-blue-400 text-sm mb-1">Revenue (Net)</div>
                <div class="text-2xl font-bold text-blue-700 dark:text-blue-300">{{ formatCurrency(summary.revenue_net || 0) }}</div>
                <div class="text-xs text-surface-500 mt-1">{{ summary.trx_count || 0 }} transaksi</div>
            </div>
            <div v-if="canViewHpp" class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4">
                <div class="text-orange-600 dark:text-orange-400 text-sm mb-1">HPP</div>
                <div class="text-2xl font-bold text-orange-700 dark:text-orange-300">{{ formatCurrency(summary.hpp_net || 0) }}</div>
            </div>
            <div v-if="canViewHpp" class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                <div class="text-green-600 dark:text-green-400 text-sm mb-1">Gross Profit</div>
                <div class="text-2xl font-bold text-green-700 dark:text-green-300">{{ formatCurrency(summary.gross_profit || 0) }}</div>
            </div>
            <div class="bg-surface-100 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-600 dark:text-surface-400 text-sm mb-1">Margin %</div>
                <div class="text-2xl font-bold" :class="marginClass(summary.margin_percent || 0)">{{ (summary.margin_percent || 0).toFixed(2) }}%</div>
            </div>
        </div>

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

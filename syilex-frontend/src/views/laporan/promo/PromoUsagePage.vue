<script setup>
import { ref, computed, onMounted } from 'vue';
import { reportsApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useReportDetailDialog } from '@/composables/useReportDetailDialog';
import { downloadBlob } from '@/utils/downloadBlob';

const authStore = useAuthStore();
const canExport = computed(() => authStore.can('laporan.export'));

const { formatCurrency, formatQty, formatDate, getPrimeDateFormatShort, toDateString } = useFormatters();
const notify = useNotification();

const exportingExcel = ref(false);

const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const endDate = ref(new Date());
const includeUnused = ref(false);
const selectedSort = ref('diskon_desc');

const summary = ref({});
const items = ref([]);
const loading = ref(false);

const {
    detailDialog,
    loadingDetail,
    detailPayload: detailData,
    openDetail: openPromoDetail
} = useReportDetailDialog({
    paginated: false,
    fetchDetail: (row, params) => reportsApi.promoUsage.detail(row.promo_ulid, params),
    parseResponse: (data) => ({ payload: data }),
    onError: (e) => notify.apiError(e, 'Gagal load detail')
});

function detailFilterParams() {
    return {
        date_from: toDateString(startDate.value),
        date_to: toDateString(endDate.value),
        limit: 10
    };
}

async function viewDetail(row) {
    await openPromoDetail(row, detailFilterParams());
}

const sortOptions = [
    { label: 'Diskon Terbesar', value: 'diskon_desc' },
    { label: 'Diskon Terkecil', value: 'diskon_asc' },
    { label: 'Transaksi Terbanyak', value: 'trx_desc' },
    { label: 'Revenue Terbesar', value: 'revenue_desc' }
];

async function loadAll() {
    const params = {
        date_from: toDateString(startDate.value),
        date_to: toDateString(endDate.value)
    };
    await Promise.all([loadSummary(params), loadList({ ...params, sort: selectedSort.value, include_unused: includeUnused.value ? 1 : 0 })]);
}

async function loadSummary(params) {
    try {
        const r = await reportsApi.promoUsage.summary(params);
        if (r.data.success) summary.value = r.data.data;
    } catch (e) {
        notify.apiError(e, 'Gagal load summary');
    }
}

async function loadList(params) {
    loading.value = true;
    try {
        const r = await reportsApi.promoUsage.list(params);
        if (r.data.success) items.value = r.data.data.items;
    } catch (e) {
        notify.apiError(e, 'Gagal load data');
    } finally {
        loading.value = false;
    }
}

onMounted(loadAll);

async function exportExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const params = {
            date_from: toDateString(startDate.value),
            date_to: toDateString(endDate.value),
            sort: selectedSort.value,
            include_unused: includeUnused.value ? 1 : 0
        };
        const response = await reportsApi.promoUsage.exportExcel(params);
        downloadBlob(response.data, `laporan_promo_usage_${params.date_from}.xlsx`);
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Promo Usage & ROI</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" class="w-48" @change="loadAll" />
                    <div class="flex items-center gap-2 px-3 py-2 bg-surface-100 dark:bg-surface-800 rounded">
                        <Checkbox v-model="includeUnused" :binary="true" inputId="includeUnused" @change="loadAll" />
                        <label for="includeUnused" class="text-sm cursor-pointer">Include unused</label>
                    </div>
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                </div>
            </template>
        </Toolbar>

        <!-- Summary -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4">
                <div class="text-xs text-purple-600 mb-1">Promo Dipakai</div>
                <div class="text-xl font-bold text-purple-700">{{ summary.promo_used || 0 }} / {{ summary.total_promos_approved || 0 }}</div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-xs text-blue-600 mb-1">Transaksi</div>
                <div class="text-xl font-bold text-blue-700">{{ summary.trx_count || 0 }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
                <div class="text-xs text-red-600 mb-1">Total Diskon</div>
                <div class="text-xl font-bold text-red-700">{{ formatCurrency(summary.diskon_total || 0) }}</div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                <div class="text-xs text-green-600 mb-1">Revenue (Net)</div>
                <div class="text-xl font-bold text-green-700">{{ formatCurrency(summary.revenue_net || 0) }}</div>
            </div>
        </div>

        <DataTable :value="items" :loading="loading" stripedRows>
            <template #empty>
                <div class="py-6 text-center text-surface-500">Belum ada promo yang dipakai.</div>
            </template>
            <Column header="Promo">
                <template #body="{ data }">
                    <div class="font-medium">{{ data.kode_promo }}</div>
                    <div class="text-xs text-surface-500">{{ data.nama_promo }}</div>
                </template>
            </Column>
            <Column header="Periode" style="width: 200px">
                <template #body="{ data }">
                    <div class="text-xs">{{ formatDate(data.periode?.tanggal_mulai) }} → {{ data.periode?.tanggal_selesai ? formatDate(data.periode.tanggal_selesai) : 'tanpa batas' }}</div>
                    <div v-if="data.periode?.jam_mulai" class="text-xs text-surface-500">{{ data.periode.jam_mulai }} - {{ data.periode.jam_selesai }}</div>
                </template>
            </Column>
            <Column field="trx_count" header="Trx" bodyClass="text-right" style="width: 80px" />
            <Column field="qty_total" header="Qty" bodyClass="text-right" style="width: 100px">
                <template #body="{ data }">{{ formatQty(data.qty_total) }}</template>
            </Column>
            <Column field="diskon_total" header="Diskon" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="text-red-600 font-medium">{{ formatCurrency(data.diskon_total) }}</span>
                </template>
            </Column>
            <Column field="revenue_net" header="Revenue" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.revenue_net) }}</template>
            </Column>
            <Column header="" style="width: 70px">
                <template #body="{ data }">
                    <Button v-if="data.trx_count > 0" icon="pi pi-eye" text rounded size="small" @click="viewDetail(data)" v-tooltip.top="'Detail'" aria-label="Detail" />
                </template>
            </Column>
        </DataTable>

        <!-- Detail Dialog -->
        <Dialog v-model:visible="detailDialog" :header="detailData.promo?.nama_promo || 'Detail Promo'" modal :style="{ width: '640px' }">
            <div v-if="loadingDetail" class="py-8 text-center">
                <i class="pi pi-spin pi-spinner text-2xl"></i>
            </div>
            <div v-else-if="detailData.promo">
                <div class="mb-4 p-3 bg-surface-50 dark:bg-surface-800 rounded">
                    <div class="font-medium mb-1">{{ detailData.promo.kode_promo }}</div>
                    <div class="text-xs text-surface-500">
                        {{ formatDate(detailData.promo.periode?.tanggal_mulai) }} → {{ detailData.promo.periode?.tanggal_selesai ? formatDate(detailData.promo.periode.tanggal_selesai) : 'tanpa batas' }}
                        <span v-if="detailData.promo.periode?.jam_mulai"> , {{ detailData.promo.periode.jam_mulai }}-{{ detailData.promo.periode.jam_selesai }} </span>
                    </div>
                </div>

                <h4 class="font-semibold mb-2">Top 5 Produk Kena Promo</h4>
                <DataTable :value="detailData.top_products" class="mb-4" stripedRows>
                    <Column field="kode_produk" header="Kode" style="width: 100px" />
                    <Column field="nama_produk" header="Produk" />
                    <Column field="qty" header="Qty" bodyClass="text-right">
                        <template #body="{ data }">{{ formatQty(data.qty) }}</template>
                    </Column>
                    <Column field="diskon" header="Diskon" bodyClass="text-right">
                        <template #body="{ data }">{{ formatCurrency(data.diskon) }}</template>
                    </Column>
                </DataTable>

                <h4 class="font-semibold mb-2">Top 5 Customer</h4>
                <DataTable :value="detailData.top_customers" stripedRows>
                    <Column field="customer_nama" header="Customer" />
                    <Column field="trx_count" header="Trx" bodyClass="text-right" />
                    <Column field="diskon_total" header="Total Diskon" bodyClass="text-right">
                        <template #body="{ data }">{{ formatCurrency(data.diskon_total) }}</template>
                    </Column>
                </DataTable>
            </div>
            <template #footer>
                <Button label="Tutup" outlined @click="detailDialog = false" />
            </template>
        </Dialog>
    </div>
</template>

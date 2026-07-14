<script setup>
import { ref, computed, onMounted } from 'vue';
import { reportsApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { downloadBlob } from '@/utils/downloadBlob';

const authStore = useAuthStore();
const canExport = computed(() => authStore.can('laporan.export'));

const { formatCurrency, getPrimeDateFormatShort, toDateString } = useFormatters();
const notify = useNotification();

const exportingExcel = ref(false);

const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const endDate = ref(new Date());

const summary = ref({});
const daily = ref({ loading: false, items: [] });

async function loadAll() {
    const params = { date_from: toDateString(startDate.value), date_to: toDateString(endDate.value) };
    await Promise.all([loadSummary(params), loadDaily(params)]);
}

async function loadSummary(params) {
    try {
        const r = await reportsApi.cashFlow.summary(params);
        if (r.data.success) Object.assign(summary.value, r.data.data);
    } catch (e) {
        notify.apiError(e, 'Gagal load summary');
    }
}

async function loadDaily(params) {
    daily.value.loading = true;
    try {
        const r = await reportsApi.cashFlow.daily(params);
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
        const response = await reportsApi.cashFlow.exportDaily(params);
        downloadBlob(response.data, `laporan_arus_kas_${params.date_from}.xlsx`);
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
                <span class="text-xl font-semibold">Laporan Arus Kas Harian</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadAll" />
                    </div>
                    <Button icon="pi pi-refresh" outlined @click="loadAll" aria-label="Refresh" />
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportDailyExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                </div>
            </template>
        </Toolbar>

        <!-- Summary -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3">
                <div class="text-xs text-blue-600 mb-1">Setor Awal</div>
                <div class="text-lg font-bold text-blue-700">{{ formatCurrency(summary.setor_awal || 0) }}</div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3">
                <div class="text-xs text-green-600 mb-1">Kas Masuk</div>
                <div class="text-lg font-bold text-green-700">{{ formatCurrency(summary.kas_masuk || 0) }}</div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3">
                <div class="text-xs text-green-600 mb-1">Jual Tunai (Net)</div>
                <div class="text-lg font-bold text-green-700">{{ formatCurrency(summary.penjualan_tunai_net || 0) }}</div>
            </div>
            <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-3">
                <div class="text-xs text-orange-600 mb-1">Kas Keluar</div>
                <div class="text-lg font-bold text-orange-700">{{ formatCurrency(summary.kas_keluar_manual || 0) }}</div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                <div class="text-xs text-red-600 mb-1">Refund Tunai</div>
                <div class="text-lg font-bold text-red-700">{{ formatCurrency(summary.refund_tunai || 0) }}</div>
            </div>
            <div class="bg-surface-100 dark:bg-surface-800 rounded-lg p-3 border-2 border-primary">
                <div class="text-xs text-primary mb-1">Net Cash Flow</div>
                <div class="text-lg font-bold text-primary">{{ formatCurrency(summary.net_cash_flow || 0) }}</div>
            </div>
        </div>

        <!-- Daily -->
        <DataTable :value="daily.items" :loading="daily.loading" stripedRows scrollable scrollHeight="500px">
            <template #empty>
                <div class="py-6 text-center text-surface-500">Belum ada data.</div>
            </template>
            <Column field="tanggal" header="Tanggal" />
            <Column field="setor_awal" header="Setor Awal" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.setor_awal) }}</template>
            </Column>
            <Column field="kas_masuk" header="Kas Masuk" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.kas_masuk) }}</template>
            </Column>
            <Column field="penjualan_tunai_net" header="Jual Tunai" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.penjualan_tunai_net) }}</template>
            </Column>
            <Column field="kas_keluar_manual" header="Kas Keluar" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="text-orange-600">({{ formatCurrency(data.kas_keluar_manual) }})</span>
                </template>
            </Column>
            <Column field="refund_tunai" header="Refund" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="text-red-600">({{ formatCurrency(data.refund_tunai) }})</span>
                </template>
            </Column>
            <Column field="net_cash_flow" header="Net" bodyClass="text-right">
                <template #body="{ data }">
                    <span class="font-bold" :class="data.net_cash_flow >= 0 ? 'text-green-700' : 'text-red-700'">
                        {{ formatCurrency(data.net_cash_flow) }}
                    </span>
                </template>
            </Column>
        </DataTable>
    </div>
</template>

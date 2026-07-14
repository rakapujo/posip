<script setup>
import { ref } from 'vue';
import { reportsApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useReportAnalytic } from '@/composables/useReportAnalytic';

const { formatCurrency } = useFormatters();

const selectedSort = ref('omzet_desc');

const sortOptions = [
    { label: 'Omzet Terbesar', value: 'omzet_desc' },
    { label: 'Omzet Terkecil', value: 'omzet_asc' },
    { label: 'Transaksi Terbanyak', value: 'trx_desc' },
    { label: 'Void Terbanyak', value: 'void_desc' },
    { label: 'Retur Terbanyak', value: 'retur_desc' }
];

const { canExport, exportingExcel, loading, items, startDate, endDate, getPrimeDateFormatShort, loadData, exportExcel } = useReportAnalytic({
    fetchList: (params) => reportsApi.kasirPerformance.list(params),
    exportFn: reportsApi.kasirPerformance.exportExcel,
    buildParams: ({ date_from, date_to }) => ({ date_from, date_to, sort: selectedSort.value }),
    exportFilename: (params) => `laporan_kasir_performance_${params.date_from}.xlsx`,
    loadErrorLabel: 'performance kasir'
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Performance Kasir</span>
            </template>
            <template #end>
                <div class="flex flex-wrap gap-2 items-center">
                    <div class="w-40">
                        <DatePicker v-model="startDate" :manualInput="false" showIcon placeholder="Tanggal Awal" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <div class="w-40">
                        <DatePicker v-model="endDate" :manualInput="false" showIcon placeholder="Tanggal Akhir" :dateFormat="getPrimeDateFormatShort" fluid showButtonBar @date-select="loadData" />
                    </div>
                    <Select v-model="selectedSort" :options="sortOptions" optionLabel="label" optionValue="value" class="w-52" @change="loadData" />
                    <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" v-tooltip.top="'Export Excel'" aria-label="Export Excel" />
                </div>
            </template>
        </Toolbar>

        <DataTable :value="items" :loading="loading" stripedRows scrollable>
            <template #empty>
                <div class="py-6 text-center text-surface-500">Tidak ada data transaksi dalam periode ini.</div>
            </template>
            <Column header="Kasir">
                <template #body="{ data }">
                    <div class="font-medium">{{ data.user_name }}</div>
                </template>
            </Column>
            <Column field="trx_completed" header="Trx OK" bodyClass="text-right" />
            <Column field="trx_voided" header="Void" bodyClass="text-right">
                <template #body="{ data }">
                    <span :class="data.trx_voided > 5 ? 'text-red-600 font-bold' : ''">{{ data.trx_voided }}</span>
                </template>
            </Column>
            <Column field="omzet" header="Omzet" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.omzet) }}</template>
            </Column>
            <Column field="avg_per_trx" header="Rata-rata/Trx" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.avg_per_trx) }}</template>
            </Column>
            <Column field="diskon_total" header="Diskon" bodyClass="text-right">
                <template #body="{ data }">{{ formatCurrency(data.diskon_total) }}</template>
            </Column>
            <Column field="retur_count" header="Retur">
                <template #body="{ data }">
                    <div class="text-right">{{ data.retur_count }}</div>
                    <div class="text-xs text-right text-surface-500">{{ formatCurrency(data.retur_nominal) }}</div>
                </template>
            </Column>
            <Column header="Shift">
                <template #body="{ data }">
                    <div>{{ data.shift_total }} shift</div>
                    <div v-if="data.shift_paksa > 0" class="text-xs text-orange-600">{{ data.shift_paksa }} paksa</div>
                    <div v-if="data.shift_selisih !== 0" class="text-xs" :class="data.shift_selisih < 0 ? 'text-red-600' : 'text-green-600'">
                        {{ formatCurrency(data.shift_selisih) }}
                    </div>
                </template>
            </Column>
        </DataTable>
    </div>
</template>

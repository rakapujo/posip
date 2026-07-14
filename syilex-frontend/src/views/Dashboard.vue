<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { dashboardApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useLayout } from '@/layout/composables/layout';

const router = useRouter();
const authStore = useAuthStore();
const { formatCurrency, formatQty, formatDate } = useFormatters();
const notify = useNotification();
const { layoutConfig, isDarkTheme } = useLayout();

const loading = ref(true);
const data = ref(null);

// ─── KPI Cards ─────────────────────────────────────────────
const kpiCards = computed(() => {
    if (!data.value) return [];
    const d = data.value;
    const cards = [];

    if (d.sales_today !== undefined) {
        cards.push({
            label: 'Penjualan Hari Ini',
            value: d.sales_today.count,
            suffix: 'transaksi',
            icon: 'pi pi-shopping-cart',
            color: 'blue'
        });
    }

    if (d.sales_today?.omzet !== undefined) {
        cards.push({
            label: 'Omzet Hari Ini',
            value: formatCurrency(d.sales_today.omzet),
            icon: 'pi pi-dollar',
            color: 'green'
        });
    }

    if (d.products !== undefined) {
        cards.push({
            label: 'Produk Aktif',
            value: d.products.total_active,
            icon: 'pi pi-box',
            color: 'cyan'
        });
    }

    if (d.stock !== undefined) {
        cards.push({
            label: 'Stok Rendah',
            value: d.stock.low_stock_count,
            suffix: 'produk',
            icon: 'pi pi-exclamation-triangle',
            color: 'orange'
        });
    }

    if (d.hutang !== undefined) {
        cards.push({
            label: 'Total Hutang',
            value: formatCurrency(d.hutang.total),
            icon: 'pi pi-wallet',
            color: 'red'
        });
    }

    if (d.po_pending !== undefined) {
        cards.push({
            label: 'PO Pending',
            value: d.po_pending,
            icon: 'pi pi-file-edit',
            color: 'purple'
        });
    }

    if (d.pending_approval !== undefined) {
        const total = Object.values(d.pending_approval).reduce((a, b) => a + b, 0);
        cards.push({
            label: 'Pending Approval',
            value: total,
            suffix: 'dokumen',
            icon: 'pi pi-clock',
            color: 'yellow'
        });
    }

    return cards;
});

// ─── KPI Color Classes ─────────────────────────────────────
const kpiColorClasses = {
    blue: { bg: 'bg-blue-100 dark:bg-blue-400/10', icon: 'text-blue-500 dark:text-blue-400' },
    green: { bg: 'bg-green-100 dark:bg-green-400/10', icon: 'text-green-500 dark:text-green-400' },
    cyan: { bg: 'bg-cyan-100 dark:bg-cyan-400/10', icon: 'text-cyan-500 dark:text-cyan-400' },
    orange: { bg: 'bg-orange-100 dark:bg-orange-400/10', icon: 'text-orange-500 dark:text-orange-400' },
    red: { bg: 'bg-red-100 dark:bg-red-400/10', icon: 'text-red-500 dark:text-red-400' },
    purple: { bg: 'bg-purple-100 dark:bg-purple-400/10', icon: 'text-purple-500 dark:text-purple-400' },
    yellow: { bg: 'bg-yellow-100 dark:bg-yellow-400/10', icon: 'text-yellow-500 dark:text-yellow-400' }
};

// ─── Charts ────────────────────────────────────────────────
const salesChartData = ref(null);
const salesChartOptions = ref(null);
const paymentChartData = ref(null);
const paymentChartOptions = ref(null);

function buildSalesChart() {
    if (!data.value?.sales_chart) return;

    const documentStyle = getComputedStyle(document.documentElement);
    const textMuted = documentStyle.getPropertyValue('--text-color-secondary');
    const borderColor = documentStyle.getPropertyValue('--surface-border');
    const primaryColor = documentStyle.getPropertyValue('--p-primary-500');
    const greenColor = documentStyle.getPropertyValue('--p-green-500') || '#22c55e';

    const chart = data.value.sales_chart;
    const hasTotal = chart.some((item) => item.total !== undefined);

    const datasets = [
        {
            label: 'Jumlah Transaksi',
            data: chart.map((item) => item.count),
            fill: false,
            borderColor: primaryColor,
            backgroundColor: primaryColor,
            tension: 0.4,
            yAxisID: 'y'
        }
    ];

    if (hasTotal) {
        datasets.push({
            label: 'Omzet',
            data: chart.map((item) => item.total || 0),
            fill: false,
            borderColor: greenColor,
            backgroundColor: greenColor,
            tension: 0.4,
            borderDash: [5, 5],
            yAxisID: 'y1'
        });
    }

    salesChartData.value = {
        labels: chart.map((item) => item.label),
        datasets
    };

    const scales = {
        x: {
            ticks: { color: textMuted },
            grid: { color: 'transparent' }
        },
        y: {
            position: 'left',
            ticks: {
                color: textMuted,
                stepSize: 1,
                precision: 0
            },
            grid: { color: borderColor }
        }
    };

    if (hasTotal) {
        scales.y1 = {
            position: 'right',
            ticks: {
                color: textMuted,
                callback: (val) => {
                    if (val >= 1000000) return (val / 1000000).toFixed(1) + 'jt';
                    if (val >= 1000) return (val / 1000).toFixed(0) + 'rb';
                    return val;
                }
            },
            grid: { drawOnChartArea: false }
        };
    }

    salesChartOptions.value = {
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: { color: textMuted }
            },
            tooltip: {
                callbacks: {
                    label: (ctx) => {
                        if (ctx.datasetIndex === 1) {
                            return `${ctx.dataset.label}: ${formatCurrency(ctx.raw)}`;
                        }
                        return `${ctx.dataset.label}: ${ctx.raw}`;
                    }
                }
            }
        },
        scales
    };
}

function buildPaymentChart() {
    if (!data.value?.payment_methods?.length) return;

    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue('--text-color');
    const surfaceBorder = documentStyle.getPropertyValue('--surface-border');

    const colors = [
        documentStyle.getPropertyValue('--p-blue-500') || '#3b82f6',
        documentStyle.getPropertyValue('--p-green-500') || '#22c55e',
        documentStyle.getPropertyValue('--p-orange-500') || '#f97316',
        documentStyle.getPropertyValue('--p-purple-500') || '#a855f7',
        documentStyle.getPropertyValue('--p-cyan-500') || '#06b6d4',
        documentStyle.getPropertyValue('--p-red-500') || '#ef4444',
        documentStyle.getPropertyValue('--p-yellow-500') || '#eab308'
    ];

    const hoverColors = [
        documentStyle.getPropertyValue('--p-blue-400') || '#60a5fa',
        documentStyle.getPropertyValue('--p-green-400') || '#4ade80',
        documentStyle.getPropertyValue('--p-orange-400') || '#fb923c',
        documentStyle.getPropertyValue('--p-purple-400') || '#c084fc',
        documentStyle.getPropertyValue('--p-cyan-400') || '#22d3ee',
        documentStyle.getPropertyValue('--p-red-400') || '#f87171',
        documentStyle.getPropertyValue('--p-yellow-400') || '#facc15'
    ];

    const methods = data.value.payment_methods;

    paymentChartData.value = {
        labels: methods.map((m) => m.label),
        datasets: [
            {
                data: methods.map((m) => m.total),
                backgroundColor: colors.slice(0, methods.length),
                hoverBackgroundColor: hoverColors.slice(0, methods.length)
            }
        ]
    };

    paymentChartOptions.value = {
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    color: textColor,
                    padding: 16
                }
            },
            tooltip: {
                callbacks: {
                    label: (ctx) => `${ctx.label}: ${formatCurrency(ctx.raw)}`
                }
            }
        },
        cutout: '60%',
        borderColor: surfaceBorder
    };
}

function buildCharts() {
    buildSalesChart();
    buildPaymentChart();
}

watch([() => layoutConfig.primary, () => layoutConfig.surface, isDarkTheme], () => {
    buildCharts();
});

// ─── Pending Approval Routing ──────────────────────────────
const pendingRouteMap = {
    adjustment: 'inventory-adjustment',
    transfer: 'inventory-transfer',
    opname: 'inventory-opname',
    repack: 'inventory-repack',
    hpp: 'inventory-hpp-correction',
    po: 'pembelian-po'
};

const pendingIconMap = {
    adjustment: 'pi pi-sliders-h',
    transfer: 'pi pi-arrows-h',
    opname: 'pi pi-clipboard',
    repack: 'pi pi-sync',
    hpp: 'pi pi-calculator',
    po: 'pi pi-shopping-cart'
};

function goToPending(item) {
    const routeName = pendingRouteMap[item.module];
    if (routeName) {
        router.push({ name: routeName });
    }
}

// ─── Top Products Progress ─────────────────────────────────
const maxProductQty = computed(() => {
    if (!data.value?.top_products?.length) return 1;
    return Math.max(...data.value.top_products.map((p) => p.total_qty));
});

// ─── Status badge ──────────────────────────────────────────
function getSalesStatusSeverity(status) {
    const map = { completed: 'success', voided: 'danger' };
    return map[status] || 'info';
}

function getSalesStatusLabel(status) {
    const map = { completed: 'Selesai', voided: 'Void' };
    return map[status] || status;
}

// ─── Load ──────────────────────────────────────────────────
async function loadDashboard() {
    loading.value = true;
    try {
        const response = await dashboardApi.get();
        data.value = response.data.data;
        buildCharts();
    } catch (error) {
        notify.error('Gagal memuat data dashboard');
    } finally {
        loading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Welcome Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold m-0">Selamat datang, {{ authStore.displayName }}!</h1>
                <p class="text-surface-500 dark:text-surface-400 mt-1 mb-0">
                    {{ authStore.user?.roles?.[0] ? authStore.user.roles[0] : '' }}
                    <span class="mx-2">&middot;</span>
                    {{ formatDate(new Date().toISOString().slice(0, 10)) }}
                </p>
            </div>
            <Button icon="pi pi-refresh" label="Refresh" severity="secondary" size="small" :loading="loading" @click="loadDashboard" />
        </div>

        <!-- KPI Cards -->
        <div v-if="loading" class="grid grid-cols-12 gap-4">
            <div v-for="i in 4" :key="i" class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="card mb-0">
                    <Skeleton height="5rem" />
                </div>
            </div>
        </div>

        <div v-else-if="kpiCards.length" class="grid grid-cols-12 gap-4">
            <div v-for="(card, index) in kpiCards" :key="index" class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="card mb-0">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="block text-surface-500 dark:text-surface-400 font-medium text-sm">
                                {{ card.label }}
                            </span>
                            <div class="text-xl font-bold mt-2">
                                {{ card.value }}
                                <span v-if="card.suffix" class="text-sm font-normal text-surface-500">
                                    {{ card.suffix }}
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center justify-center rounded-full w-12 h-12" :class="kpiColorClasses[card.color]?.bg">
                            <i :class="[card.icon, kpiColorClasses[card.color]?.icon]" class="text-xl" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-12 gap-6">
            <!-- Sales Chart (7 Days) -->
            <div v-if="loading || data?.sales_chart" :class="data?.payment_methods?.length ? 'col-span-12 xl:col-span-7' : 'col-span-12'">
                <div class="card">
                    <div class="font-semibold text-xl mb-4">Penjualan 7 Hari Terakhir</div>
                    <Skeleton v-if="loading" height="18rem" />
                    <Chart v-else-if="salesChartData" type="line" :data="salesChartData" :options="salesChartOptions" class="h-72" />
                    <div v-else class="flex items-center justify-center h-72 text-surface-400">Belum ada data penjualan</div>
                </div>
            </div>

            <!-- Payment Methods Chart -->
            <div v-if="loading || data?.payment_methods?.length" class="col-span-12 xl:col-span-5">
                <div class="card">
                    <div class="font-semibold text-xl mb-4">Metode Pembayaran (7 Hari)</div>
                    <Skeleton v-if="loading" height="18rem" />
                    <div v-else-if="paymentChartData" class="flex justify-center" style="position: relative; height: 18rem">
                        <Chart type="doughnut" :data="paymentChartData" :options="paymentChartOptions" style="position: relative; width: 100%; height: 100%" />
                    </div>
                    <div v-else class="flex items-center justify-center h-72 text-surface-400">Belum ada data pembayaran</div>
                </div>
            </div>
        </div>

        <!-- Lists Row -->
        <div class="grid grid-cols-12 gap-6">
            <!-- Top Products -->
            <div v-if="loading || data?.top_products" class="col-span-12 lg:col-span-4">
                <div class="card">
                    <div class="font-semibold text-xl mb-4">Top 5 Produk Terlaris</div>
                    <template v-if="loading">
                        <Skeleton v-for="i in 5" :key="i" height="2.5rem" class="mb-3" />
                    </template>
                    <template v-else-if="data?.top_products?.length">
                        <ol class="list-none p-0 m-0">
                            <li v-for="(product, index) in data.top_products" :key="index" class="flex flex-col gap-1 pb-3 mb-3 border-b border-surface-200 dark:border-surface-700 last:border-0 last:pb-0 last:mb-0">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="flex items-center justify-center bg-primary text-primary-contrast rounded-full w-6 h-6 text-xs font-bold">
                                            {{ index + 1 }}
                                        </span>
                                        <div>
                                            <span class="font-medium text-sm">{{ product.kode }}</span>
                                            <p class="text-surface-500 text-xs m-0 mt-0.5">{{ product.nama }}</p>
                                        </div>
                                    </div>
                                    <span class="font-semibold text-sm">{{ formatQty(product.total_qty) }}</span>
                                </div>
                                <ProgressBar :value="(product.total_qty / maxProductQty) * 100" :showValue="false" style="height: 6px" />
                            </li>
                        </ol>
                    </template>
                    <div v-else class="text-surface-400 text-center py-8">Belum ada data penjualan</div>
                </div>
            </div>

            <!-- Recent Sales -->
            <div v-if="loading || data?.recent_sales" class="col-span-12 lg:col-span-4">
                <div class="card">
                    <div class="font-semibold text-xl mb-4">Transaksi Terbaru</div>
                    <template v-if="loading">
                        <Skeleton v-for="i in 5" :key="i" height="2.5rem" class="mb-3" />
                    </template>
                    <template v-else-if="data?.recent_sales?.length">
                        <ul class="list-none p-0 m-0">
                            <li v-for="(sale, index) in data.recent_sales" :key="index" class="flex items-center justify-between pb-3 mb-3 border-b border-surface-200 dark:border-surface-700 last:border-0 last:pb-0 last:mb-0">
                                <div>
                                    <span class="font-medium text-sm">{{ sale.nomor }}</span>
                                    <p class="text-surface-500 text-xs m-0 mt-0.5">
                                        {{ sale.customer }}
                                        <span class="mx-1">&middot;</span>
                                        {{ sale.tanggal }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span v-if="sale.grand_total !== undefined" class="font-semibold text-sm">
                                        {{ formatCurrency(sale.grand_total) }}
                                    </span>
                                    <Tag :value="getSalesStatusLabel(sale.status)" :severity="getSalesStatusSeverity(sale.status)" class="text-xs" />
                                </div>
                            </li>
                        </ul>
                    </template>
                    <div v-else class="text-surface-400 text-center py-8">Belum ada transaksi</div>
                </div>
            </div>

            <!-- Low Stock / Pending Approval -->
            <div v-if="loading || data?.low_stock_items || data?.pending_items" class="col-span-12 lg:col-span-4">
                <!-- Low Stock -->
                <div v-if="loading || data?.low_stock_items" class="card" :class="{ 'mb-6': data?.pending_items?.length }">
                    <div class="font-semibold text-xl mb-4">Stok Rendah</div>
                    <template v-if="loading">
                        <Skeleton v-for="i in 5" :key="i" height="2.5rem" class="mb-3" />
                    </template>
                    <template v-else-if="data?.low_stock_items?.length">
                        <ul class="list-none p-0 m-0">
                            <li v-for="(item, index) in data.low_stock_items" :key="index" class="flex items-center justify-between pb-3 mb-3 border-b border-surface-200 dark:border-surface-700 last:border-0 last:pb-0 last:mb-0">
                                <div>
                                    <span class="font-medium text-sm">{{ item.kode }}</span>
                                    <p class="text-surface-500 text-xs m-0 mt-0.5">
                                        {{ item.nama }}
                                        <span class="mx-1">&middot;</span>
                                        {{ item.warehouse }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <Tag :value="`${formatQty(item.qty)} / ${formatQty(item.minimum)}`" :severity="item.qty <= 0 ? 'danger' : 'warn'" class="text-xs" />
                                </div>
                            </li>
                        </ul>
                    </template>
                    <div v-else class="text-surface-400 text-center py-8">Semua stok mencukupi</div>
                </div>

                <!-- Pending Approval -->
                <div v-if="!loading && data?.pending_items?.length" class="card">
                    <div class="font-semibold text-xl mb-4">Pending Approval</div>
                    <ul class="list-none p-0 m-0">
                        <li
                            v-for="(item, index) in data.pending_items"
                            :key="index"
                            class="flex items-center justify-between pb-3 mb-3 border-b border-surface-200 dark:border-surface-700 last:border-0 last:pb-0 last:mb-0 cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700 -mx-2 px-2 py-1 rounded"
                            @click="goToPending(item)"
                        >
                            <div class="flex items-center gap-2">
                                <i :class="pendingIconMap[item.module]" class="text-surface-500" />
                                <div>
                                    <span class="font-medium text-sm">{{ item.nomor }}</span>
                                    <p class="text-surface-500 text-xs m-0 mt-0.5">{{ item.label }}</p>
                                </div>
                            </div>
                            <span class="text-surface-400 text-xs">{{ item.tanggal }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Empty State (no data at all — shouldn't happen, but just in case) -->
        <div v-if="!loading && data && !kpiCards.length && !data.sales_chart && !data.top_products && !data.recent_sales && !data.low_stock_items && !data.pending_items" class="card text-center py-12">
            <i class="pi pi-home text-4xl text-surface-300 mb-4" />
            <p class="text-surface-500 text-lg">Selamat datang di POSIP</p>
            <p class="text-surface-400">Dashboard akan menampilkan data sesuai permission Anda.</p>
        </div>
    </div>
</template>

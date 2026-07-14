<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { reportsApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useNotification } from '@/composables/useNotification';
import { useFormatters } from '@/composables/useFormatters';
import { downloadBlob } from '@/utils/downloadBlob';

const { formatDate } = useFormatters();

const router = useRouter();
const authStore = useAuthStore();
const notify = useNotification();
const canExport = computed(() => authStore.can('laporan.export'));
const exportingExcel = ref(false);
const canOpenPromo = () => authStore.can('promo.view');

function openPromo(promoUlid) {
    if (!promoUlid || !canOpenPromo()) return;
    router.push({ name: 'master-promo-edit', params: { ulid: promoUlid } });
}

const activeTab = ref('by_product');
const statusFilter = ref('active_now');
const searchQuery = ref('');

const byProduct = ref({ loading: false, items: [], pagination: { total: 0 } });
const byPromo = ref({ loading: false, items: [] });
const lazyParams = ref({ first: 0, rows: 25 });
const onlyWithPromo = ref(false);

const statusOptions = [
    { label: 'Aktif Sekarang', value: 'active_now' },
    { label: 'Semua Approved', value: 'approved_all' },
    { label: 'Upcoming', value: 'upcoming' },
    { label: 'Expired', value: 'expired' }
];

async function loadByProduct() {
    byProduct.value.loading = true;
    try {
        const params = {
            status: statusFilter.value,
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows
        };
        if (searchQuery.value) params.search = searchQuery.value;
        if (onlyWithPromo.value) params.only_with_promo = 1;

        const r = await reportsApi.productPromo.byProduct(params);
        if (r.data.success) {
            byProduct.value.items = r.data.data.items;
            byProduct.value.pagination = r.data.data.pagination;
        }
    } catch (e) {
        notify.apiError(e, 'Gagal load data');
    } finally {
        byProduct.value.loading = false;
    }
}

async function loadByPromo() {
    byPromo.value.loading = true;
    try {
        const r = await reportsApi.productPromo.byPromo({ status: statusFilter.value });
        if (r.data.success) byPromo.value.items = r.data.data.items;
    } catch (e) {
        notify.apiError(e, 'Gagal load data');
    } finally {
        byPromo.value.loading = false;
    }
}

function onTabChange(tab) {
    activeTab.value = tab;
    if (tab === 'by_promo' && byPromo.value.items.length === 0) loadByPromo();
}

function onFilterChange() {
    lazyParams.value.first = 0;
    if (activeTab.value === 'by_product') loadByProduct();
    else loadByPromo();
}

function onPage(e) {
    lazyParams.value.first = e.first;
    lazyParams.value.rows = e.rows;
    loadByProduct();
}

function formatDiskonSlot(slot) {
    if (!slot || slot.tipe === 'none') return '-';
    return slot.tipe === 'percent' ? `${slot.nilai}%` : `Rp ${slot.nilai.toLocaleString('id-ID')}`;
}

function slotLabel(key) {
    const num = String(key).replace(/\D/g, '');
    return `Diskon ${num}`;
}

onMounted(loadByProduct);

async function exportByPromoExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const response = await reportsApi.productPromo.exportByPromo({ status: statusFilter.value });
        downloadBlob(response.data, 'laporan_product_promo.xlsx');
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

async function exportByProductExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const response = await reportsApi.productPromo.exportByProduct({ status: statusFilter.value });
        downloadBlob(response.data, 'laporan_product_promo_by_produk.xlsx');
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
            <h2 class="text-xl font-bold m-0">Produk Dapat Promo</h2>
            <div class="flex gap-2 flex-wrap">
                <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" class="w-44" @change="onFilterChange" />
                <Button
                    v-if="canExport && activeTab === 'by_product'"
                    icon="pi pi-file-excel"
                    severity="success"
                    outlined
                    :loading="exportingExcel"
                    @click="exportByProductExcel"
                    v-tooltip.top="'Export Excel (Per Produk)'"
                    aria-label="Export Excel Per Produk"
                />
                <Button v-if="canExport && activeTab === 'by_promo'" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportByPromoExcel" v-tooltip.top="'Export Excel (Per Promo)'" aria-label="Export Excel" />
            </div>
        </div>

        <!-- Tabs -->
        <div class="mb-4 border-b border-surface-200 dark:border-surface-700 flex gap-1">
            <button class="px-4 py-2 text-sm font-medium border-b-2 transition" :class="activeTab === 'by_product' ? 'border-primary text-primary' : 'border-transparent text-surface-600'" @click="onTabChange('by_product')" type="button">
                <i class="pi pi-box mr-1"></i> Per Produk
            </button>
            <button class="px-4 py-2 text-sm font-medium border-b-2 transition" :class="activeTab === 'by_promo' ? 'border-primary text-primary' : 'border-transparent text-surface-600'" @click="onTabChange('by_promo')" type="button">
                <i class="pi pi-tag mr-1"></i> Per Promo
            </button>
        </div>

        <!-- Tab: Per Produk -->
        <div v-if="activeTab === 'by_product'">
            <div class="flex gap-2 mb-3">
                <IconField class="flex-1">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="searchQuery" placeholder="Cari produk..." @input="onFilterChange" class="w-full" />
                </IconField>
                <div class="flex items-center gap-2 px-3 bg-surface-100 dark:bg-surface-800 rounded">
                    <Checkbox v-model="onlyWithPromo" :binary="true" inputId="onlyPromo" @change="onFilterChange" />
                    <label for="onlyPromo" class="text-sm cursor-pointer">Hanya yang ada promo</label>
                </div>
            </div>

            <DataTable
                v-model:expandedRows="byProduct.expandedRows"
                :value="byProduct.items"
                :loading="byProduct.loading"
                :lazy="true"
                :paginator="true"
                :rows="lazyParams.rows"
                :totalRecords="byProduct.pagination.total"
                :first="lazyParams.first"
                :rowsPerPageOptions="[25, 50, 100]"
                @page="onPage"
                dataKey="product_id"
                stripedRows
            >
                <template #empty>
                    <div class="py-6 text-center text-surface-500">Tidak ada data.</div>
                </template>
                <Column expander style="width: 40px" />
                <Column header="Produk">
                    <template #body="{ data }">
                        <div class="font-medium">{{ data.nama_produk }}</div>
                        <div class="text-xs text-surface-500">{{ data.kode_produk }}</div>
                    </template>
                </Column>
                <Column field="kategori" header="Kategori" style="width: 140px" />
                <Column field="grup" header="Grup" style="width: 120px" />
                <Column header="Promo" style="width: 110px">
                    <template #body="{ data }">
                        <Tag v-if="data.promo_count > 0" :value="`${data.promo_count} promo`" severity="success" />
                        <Tag v-else value="-" severity="secondary" />
                    </template>
                </Column>
                <template #expansion="{ data }">
                    <div class="p-3 bg-surface-50 dark:bg-surface-800">
                        <div v-if="data.promos.length === 0" class="text-sm text-surface-500">Tidak ada promo eligible.</div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="p in data.promos"
                                :key="p.promo_id"
                                class="bg-surface-0 dark:bg-surface-900 rounded p-3 border border-surface-200 dark:border-surface-700"
                                :class="canOpenPromo() ? 'cursor-pointer hover:border-primary hover:bg-surface-50 dark:hover:bg-surface-800 transition' : ''"
                                @click="openPromo(p.promo_ulid)"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-medium flex items-center gap-1">
                                            {{ p.nama_promo }}
                                            <i v-if="canOpenPromo()" class="pi pi-external-link text-xs text-surface-400"></i>
                                        </div>
                                        <div class="text-xs text-surface-500">{{ p.kode_promo }}</div>
                                    </div>
                                    <Tag :value="p.cover_type" severity="info" />
                                </div>
                                <div class="text-xs text-surface-500 mt-1">
                                    {{ formatDate(p.periode?.tanggal_mulai) }} → {{ p.periode?.tanggal_selesai ? formatDate(p.periode.tanggal_selesai) : 'tanpa batas' }}
                                    <span v-if="p.periode?.jam_mulai">, {{ p.periode.jam_mulai }}-{{ p.periode.jam_selesai }}</span>
                                </div>
                                <div v-if="p.diskon" class="mt-2 flex gap-2 text-xs">
                                    <span v-for="(slot, k) in p.diskon" :key="k" class="bg-red-100 text-red-700 px-2 py-1 rounded"> {{ slotLabel(k) }}: {{ formatDiskonSlot(slot) }} </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>

        <!-- Tab: Per Promo -->
        <div v-else>
            <DataTable v-model:expandedRows="byPromo.expandedRows" :value="byPromo.items" :loading="byPromo.loading" dataKey="promo_id" stripedRows>
                <template #empty>
                    <div class="py-6 text-center text-surface-500">Tidak ada promo.</div>
                </template>
                <Column expander style="width: 40px" />
                <Column header="Promo">
                    <template #body="{ data }">
                        <div :class="canOpenPromo() ? 'cursor-pointer hover:text-primary transition' : ''" @click="openPromo(data.promo_ulid)">
                            <div class="font-medium flex items-center gap-1">
                                {{ data.nama_promo }}
                                <i v-if="canOpenPromo()" class="pi pi-external-link text-xs text-surface-400"></i>
                            </div>
                            <div class="text-xs text-surface-500">{{ data.kode_promo }}</div>
                        </div>
                    </template>
                </Column>
                <Column header="Periode" style="width: 240px">
                    <template #body="{ data }">
                        <div class="text-xs">{{ formatDate(data.periode?.tanggal_mulai) }} → {{ data.periode?.tanggal_selesai ? formatDate(data.periode.tanggal_selesai) : 'tanpa batas' }}</div>
                        <div v-if="data.periode?.jam_mulai" class="text-xs text-surface-500">{{ data.periode.jam_mulai }}-{{ data.periode.jam_selesai }}</div>
                    </template>
                </Column>
                <Column field="product_count" header="Cover" style="width: 100px">
                    <template #body="{ data }">
                        <Tag :value="`${data.product_count} produk`" severity="success" />
                    </template>
                </Column>
                <template #expansion="{ data }">
                    <div class="p-3 bg-surface-50 dark:bg-surface-800">
                        <div class="mb-3">
                            <div class="text-xs text-surface-500 mb-1">Target:</div>
                            <div v-for="d in data.details" :key="d.target_type + d.target_id" class="mb-2">
                                <span class="text-sm font-medium">{{ d.target_label }}</span>
                                <span class="text-xs text-surface-500 ml-2">min qty {{ d.min_qty }}</span>
                                <div v-if="d.diskon" class="mt-1 flex gap-2 text-xs">
                                    <span v-for="(slot, k) in d.diskon" :key="k" class="bg-red-100 text-red-700 px-2 py-1 rounded"> {{ slotLabel(k) }}: {{ formatDiskonSlot(slot) }} </span>
                                </div>
                            </div>
                        </div>
                        <div class="text-xs text-surface-500 mb-2">Produk ter-cover ({{ data.product_count }}):</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                            <div v-for="p in data.products.slice(0, 20)" :key="p.id" class="bg-surface-0 dark:bg-surface-900 rounded p-2 border border-surface-200 dark:border-surface-700">
                                <div class="font-medium text-sm">{{ p.nama_produk }}</div>
                                <div class="text-xs text-surface-500">{{ p.kode_produk }}</div>
                            </div>
                            <div v-if="data.products.length > 20" class="text-xs text-surface-500 col-span-full">+{{ data.products.length - 20 }} produk lainnya</div>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>
    </div>
</template>

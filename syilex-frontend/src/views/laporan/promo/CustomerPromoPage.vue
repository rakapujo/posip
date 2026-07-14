<script setup>
import { ref, computed, onMounted } from 'vue';
import { reportsApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useNotification } from '@/composables/useNotification';
import { useReportDetailDialog } from '@/composables/useReportDetailDialog';
import { downloadBlob } from '@/utils/downloadBlob';

const notify = useNotification();
const authStore = useAuthStore();
const canExport = computed(() => authStore.can('laporan.export'));
const exportingExcel = ref(false);

const statusFilter = ref('active_now');
const activeTab = ref('by_tipe');

const summary = ref({});
const byTipe = ref({ loading: false, items: [] });
const byKategori = ref({ loading: false, items: [] });
const byCustomer = ref({ loading: false, items: [], pagination: { total: 0 } });
const searchQuery = ref('');
const onlyTerjaring = ref(false);
const lazyParams = ref({ first: 0, rows: 25 });

const {
    detailDialog,
    loadingDetail,
    detailPayload: detailData,
    openDetail: openCustomerDetail
} = useReportDetailDialog({
    paginated: false,
    fetchDetail: (row, params) => reportsApi.customerPromo.showCustomer(row.customer_ulid, params),
    parseResponse: (data) => ({ payload: data }),
    onError: (e) => notify.apiError(e, 'Gagal load detail')
});

const statusOptions = [
    { label: 'Aktif Sekarang', value: 'active_now' },
    { label: 'Semua Approved', value: 'approved_all' }
];

async function loadSummary() {
    try {
        const r = await reportsApi.customerPromo.summary({ status: statusFilter.value });
        if (r.data.success) summary.value = r.data.data;
    } catch (e) {
        notify.apiError(e, 'Gagal load summary');
    }
}

async function loadByTipe() {
    byTipe.value.loading = true;
    try {
        const r = await reportsApi.customerPromo.byTipe({ status: statusFilter.value });
        if (r.data.success) byTipe.value.items = r.data.data.items;
    } catch (e) {
        notify.apiError(e, 'Gagal load per tipe');
    } finally {
        byTipe.value.loading = false;
    }
}

async function loadByKategori() {
    byKategori.value.loading = true;
    try {
        const r = await reportsApi.customerPromo.byKategori({ status: statusFilter.value });
        if (r.data.success) byKategori.value.items = r.data.data.items;
    } catch (e) {
        notify.apiError(e, 'Gagal load per kategori');
    } finally {
        byKategori.value.loading = false;
    }
}

async function loadByCustomer() {
    byCustomer.value.loading = true;
    try {
        const params = {
            status: statusFilter.value,
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows
        };
        if (searchQuery.value) params.search = searchQuery.value;
        if (onlyTerjaring.value) params.only_terjaring = 1;

        const r = await reportsApi.customerPromo.byCustomer(params);
        if (r.data.success) {
            byCustomer.value.items = r.data.data.items;
            byCustomer.value.pagination = r.data.data.pagination;
        }
    } catch (e) {
        notify.apiError(e, 'Gagal load per customer');
    } finally {
        byCustomer.value.loading = false;
    }
}

function onTabChange(tab) {
    activeTab.value = tab;
    if (tab === 'by_tipe' && byTipe.value.items.length === 0) loadByTipe();
    else if (tab === 'by_kategori' && byKategori.value.items.length === 0) loadByKategori();
    else if (tab === 'by_customer' && byCustomer.value.items.length === 0) loadByCustomer();
}

function onFilterChange() {
    byTipe.value.items = [];
    byKategori.value.items = [];
    byCustomer.value.items = [];
    loadSummary();
    onTabChange(activeTab.value);
}

function onCustomerPage(e) {
    lazyParams.value.first = e.first;
    lazyParams.value.rows = e.rows;
    loadByCustomer();
}

async function viewCustomerDetail(row) {
    await openCustomerDetail(row, { status: statusFilter.value });
}

onMounted(() => {
    loadSummary();
    loadByTipe();
});

async function exportByCustomerExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const params = {
            status: statusFilter.value,
            only_terjaring: onlyTerjaring.value ? 1 : 0
        };
        if (searchQuery.value) params.search = searchQuery.value;
        const response = await reportsApi.customerPromo.exportByCustomer(params);
        downloadBlob(response.data, 'laporan_customer_promo.xlsx');
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

function promoExportParams() {
    return { status: statusFilter.value };
}

async function exportSummaryExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const response = await reportsApi.customerPromo.exportSummary(promoExportParams());
        downloadBlob(response.data, 'laporan_customer_promo_summary.xlsx');
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

async function exportByTipeExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const response = await reportsApi.customerPromo.exportByTipe(promoExportParams());
        downloadBlob(response.data, 'laporan_customer_promo_tipe.xlsx');
    } catch (e) {
        notify.apiError(e, 'Gagal export Excel');
    } finally {
        exportingExcel.value = false;
    }
}

async function exportByKategoriExcel() {
    if (!canExport.value) return;
    exportingExcel.value = true;
    try {
        const response = await reportsApi.customerPromo.exportByKategori(promoExportParams());
        downloadBlob(response.data, 'laporan_customer_promo_kategori.xlsx');
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
            <h2 class="text-xl font-bold m-0">Customer Dapat Promo</h2>
            <div class="flex gap-2 flex-wrap items-center">
                <Select v-model="statusFilter" :options="statusOptions" optionLabel="label" optionValue="value" class="w-44" @change="onFilterChange" />
                <Button v-if="canExport" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportSummaryExcel" v-tooltip.top="'Export Excel (Summary)'" aria-label="Export Excel Summary" />
                <Button
                    v-if="canExport && activeTab === 'by_tipe'"
                    icon="pi pi-file-excel"
                    severity="success"
                    outlined
                    :loading="exportingExcel"
                    @click="exportByTipeExcel"
                    v-tooltip.top="'Export Excel (Per Tipe)'"
                    aria-label="Export Excel Per Tipe"
                />
                <Button
                    v-if="canExport && activeTab === 'by_kategori'"
                    icon="pi pi-file-excel"
                    severity="success"
                    outlined
                    :loading="exportingExcel"
                    @click="exportByKategoriExcel"
                    v-tooltip.top="'Export Excel (Per Kategori)'"
                    aria-label="Export Excel Per Kategori"
                />
                <Button
                    v-if="canExport && activeTab === 'by_customer'"
                    icon="pi pi-file-excel"
                    severity="success"
                    outlined
                    :loading="exportingExcel"
                    @click="exportByCustomerExcel"
                    v-tooltip.top="'Export Excel (Per Customer)'"
                    aria-label="Export Excel"
                />
            </div>
        </div>

        <!-- Summary -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3">
                <div class="text-xs text-surface-500 mb-1">Tipe dgn Disc</div>
                <div class="text-lg font-bold">{{ summary.tipe_with_disc || 0 }} / {{ summary.tipe_total || 0 }}</div>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3">
                <div class="text-xs text-surface-500 mb-1">Kategori dgn Disc</div>
                <div class="text-lg font-bold">{{ summary.kategori_with_disc || 0 }} / {{ summary.kategori_total || 0 }}</div>
            </div>
            <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-3">
                <div class="text-xs text-purple-600 mb-1">Promo Aktif</div>
                <div class="text-lg font-bold text-purple-700">{{ summary.promo_aktif || 0 }}</div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3">
                <div class="text-xs text-green-600 mb-1">Customer Terjaring</div>
                <div class="text-lg font-bold text-green-700">{{ summary.customer_terjaring || 0 }} / {{ summary.customer_total || 0 }}</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="mb-4 border-b border-surface-200 dark:border-surface-700 flex gap-1">
            <button
                v-for="t in [
                    { key: 'by_tipe', label: 'Per Tipe', icon: 'pi-id-card' },
                    { key: 'by_kategori', label: 'Per Kategori', icon: 'pi-tags' },
                    { key: 'by_customer', label: 'Per Customer', icon: 'pi-users' }
                ]"
                :key="t.key"
                class="px-4 py-2 text-sm font-medium border-b-2 transition"
                :class="activeTab === t.key ? 'border-primary text-primary' : 'border-transparent text-surface-600'"
                @click="onTabChange(t.key)"
                type="button"
            >
                <i class="pi mr-1" :class="t.icon"></i> {{ t.label }}
            </button>
        </div>

        <!-- Tab: Per Tipe -->
        <div v-if="activeTab === 'by_tipe'">
            <DataTable v-model:expandedRows="byTipe.expandedRows" :value="byTipe.items" :loading="byTipe.loading" dataKey="tipe_id" stripedRows>
                <template #empty>
                    <div class="py-6 text-center text-surface-500">Belum ada tipe customer.</div>
                </template>
                <Column expander style="width: 40px" />
                <Column field="kode_tipe" header="Kode" style="width: 120px" />
                <Column field="nama_tipe" header="Tipe" />
                <Column header="Disc Nota" style="width: 140px">
                    <template #body="{ data }">
                        <Tag v-if="data.disc_nota.has_disc" :value="data.disc_nota.display" severity="success" />
                        <span v-else class="text-surface-500">-</span>
                    </template>
                </Column>
                <Column field="customer_count" header="Customer" style="width: 110px" bodyClass="text-right" />
                <Column field="promo_count" header="Promo Eligible" style="width: 140px">
                    <template #body="{ data }">
                        <Tag v-if="data.promo_count > 0" :value="`${data.promo_count} promo`" severity="info" />
                        <span v-else class="text-surface-500">-</span>
                    </template>
                </Column>
                <template #expansion="{ data }">
                    <div class="p-3 bg-surface-50 dark:bg-surface-800">
                        <div v-if="data.promos.length === 0" class="text-sm text-surface-500">Tidak ada promo line eligible.</div>
                        <div v-else class="space-y-2">
                            <div v-for="p in data.promos" :key="p.promo_id" class="bg-surface-0 dark:bg-surface-900 rounded p-2 text-sm">
                                <span class="font-medium">{{ p.kode_promo }}</span> — {{ p.nama_promo }}
                                <Tag v-if="p.scope.is_global" value="global" severity="secondary" class="ml-2" />
                            </div>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>

        <!-- Tab: Per Kategori -->
        <div v-else-if="activeTab === 'by_kategori'">
            <DataTable v-model:expandedRows="byKategori.expandedRows" :value="byKategori.items" :loading="byKategori.loading" dataKey="kategori_id" stripedRows>
                <template #empty>
                    <div class="py-6 text-center text-surface-500">Belum ada kategori customer.</div>
                </template>
                <Column expander style="width: 40px" />
                <Column field="kode_kategori" header="Kode" style="width: 120px" />
                <Column field="nama_kategori" header="Kategori" />
                <Column header="Disc Nota" style="width: 140px">
                    <template #body="{ data }">
                        <Tag v-if="data.disc_nota.has_disc" :value="data.disc_nota.display" severity="success" />
                        <span v-else class="text-surface-500">-</span>
                    </template>
                </Column>
                <Column field="customer_count" header="Customer" style="width: 110px" bodyClass="text-right" />
                <Column field="promo_count" header="Promo" style="width: 140px">
                    <template #body="{ data }">
                        <Tag v-if="data.promo_count > 0" :value="`${data.promo_count} promo`" severity="info" />
                        <span v-else class="text-surface-500">-</span>
                    </template>
                </Column>
                <template #expansion="{ data }">
                    <div class="p-3 bg-surface-50 dark:bg-surface-800">
                        <div v-if="data.promos.length === 0" class="text-sm text-surface-500">Tidak ada promo.</div>
                        <div v-else class="space-y-2">
                            <div v-for="p in data.promos" :key="p.promo_id" class="bg-surface-0 dark:bg-surface-900 rounded p-2 text-sm">
                                <span class="font-medium">{{ p.kode_promo }}</span> — {{ p.nama_promo }}
                            </div>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>

        <!-- Tab: Per Customer -->
        <div v-else>
            <div class="flex gap-2 mb-3">
                <IconField class="flex-1">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="searchQuery" placeholder="Cari customer..." @input="onFilterChange" class="w-full" />
                </IconField>
                <div class="flex items-center gap-2 px-3 bg-surface-100 dark:bg-surface-800 rounded">
                    <Checkbox v-model="onlyTerjaring" :binary="true" inputId="onlyTerjaring" @change="onFilterChange" />
                    <label for="onlyTerjaring" class="text-sm cursor-pointer">Hanya yang terjaring</label>
                </div>
            </div>

            <DataTable
                :value="byCustomer.items"
                :loading="byCustomer.loading"
                :lazy="true"
                :paginator="true"
                :rows="lazyParams.rows"
                :totalRecords="byCustomer.pagination.total"
                :first="lazyParams.first"
                :rowsPerPageOptions="[25, 50, 100]"
                @page="onCustomerPage"
                stripedRows
            >
                <template #empty>
                    <div class="py-6 text-center text-surface-500">Tidak ada customer.</div>
                </template>
                <Column header="" style="width: 30px">
                    <template #body="{ data }">
                        <span v-if="data.terjaring" class="text-green-500">●</span>
                        <span v-else class="text-surface-300">○</span>
                    </template>
                </Column>
                <Column header="Customer">
                    <template #body="{ data }">
                        <div class="font-medium">{{ data.nama_customer }}</div>
                        <div class="text-xs text-surface-500">{{ data.kode_customer }}</div>
                    </template>
                </Column>
                <Column header="Tipe / Kategori" style="width: 200px">
                    <template #body="{ data }">
                        <Tag v-if="data.tipe" :value="data.tipe.kode" severity="info" class="mr-1" />
                        <Tag v-if="data.kategori" :value="data.kategori.kode" severity="secondary" />
                    </template>
                </Column>
                <Column header="Disc Nota" style="width: 180px">
                    <template #body="{ data }">
                        <div class="flex gap-1 flex-wrap">
                            <Tag v-if="data.disc_nota_tipe?.has_disc" :value="`T: ${data.disc_nota_tipe.display}`" severity="success" />
                            <Tag v-if="data.disc_nota_kategori?.has_disc" :value="`K: ${data.disc_nota_kategori.display}`" severity="success" />
                            <span v-if="!data.disc_nota_tipe?.has_disc && !data.disc_nota_kategori?.has_disc" class="text-surface-500">-</span>
                        </div>
                    </template>
                </Column>
                <Column field="promo_line_count" header="Promo Line" style="width: 110px">
                    <template #body="{ data }">
                        <Tag v-if="data.promo_line_count > 0" :value="data.promo_line_count" severity="info" />
                        <span v-else class="text-surface-500">0</span>
                    </template>
                </Column>
                <Column header="" style="width: 70px">
                    <template #body="{ data }">
                        <Button icon="pi pi-eye" text rounded size="small" @click="viewCustomerDetail(data)" v-tooltip.top="'Detail'" aria-label="Detail customer" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- Customer Detail Dialog -->
        <Dialog v-model:visible="detailDialog" :header="detailData.customer?.nama || 'Detail Customer'" modal :style="{ width: '640px' }">
            <div v-if="loadingDetail" class="py-8 text-center">
                <i class="pi pi-spin pi-spinner text-2xl"></i>
            </div>
            <div v-else-if="detailData.customer">
                <div class="mb-3 flex gap-2">
                    <Tag v-if="detailData.customer.tipe" :value="`Tipe: ${detailData.customer.tipe.nama}`" severity="info" />
                    <Tag v-if="detailData.customer.kategori" :value="`Kategori: ${detailData.customer.kategori.nama}`" severity="secondary" />
                </div>

                <h4 class="font-semibold mb-2">Disc Nota Auto</h4>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="bg-surface-50 dark:bg-surface-800 rounded p-3">
                        <div class="text-xs text-surface-500 mb-1">Via Tipe</div>
                        <Tag v-if="detailData.disc_nota?.via_tipe?.has_disc" :value="detailData.disc_nota.via_tipe.display" severity="success" />
                        <span v-else class="text-sm text-surface-500">-</span>
                    </div>
                    <div class="bg-surface-50 dark:bg-surface-800 rounded p-3">
                        <div class="text-xs text-surface-500 mb-1">Via Kategori</div>
                        <Tag v-if="detailData.disc_nota?.via_kategori?.has_disc" :value="detailData.disc_nota.via_kategori.display" severity="success" />
                        <span v-else class="text-sm text-surface-500">-</span>
                    </div>
                </div>

                <h4 class="font-semibold mb-2">Promo Line Eligible ({{ detailData.total_promo_eligible }})</h4>
                <div class="space-y-3">
                    <div v-if="detailData.promo_line?.via_tipe?.length" class="bg-blue-50 dark:bg-blue-900/20 rounded p-3">
                        <div class="text-xs font-semibold text-blue-700 mb-2">Via Tipe ({{ detailData.promo_line.via_tipe.length }})</div>
                        <div v-for="p in detailData.promo_line.via_tipe" :key="p.promo_id" class="text-sm mb-1">
                            • <span class="font-medium">{{ p.kode_promo }}</span> — {{ p.nama_promo }}
                        </div>
                    </div>
                    <div v-if="detailData.promo_line?.via_kategori?.length" class="bg-purple-50 dark:bg-purple-900/20 rounded p-3">
                        <div class="text-xs font-semibold text-purple-700 mb-2">Via Kategori ({{ detailData.promo_line.via_kategori.length }})</div>
                        <div v-for="p in detailData.promo_line.via_kategori" :key="p.promo_id" class="text-sm mb-1">
                            • <span class="font-medium">{{ p.kode_promo }}</span> — {{ p.nama_promo }}
                        </div>
                    </div>
                    <div v-if="detailData.promo_line?.via_global?.length" class="bg-green-50 dark:bg-green-900/20 rounded p-3">
                        <div class="text-xs font-semibold text-green-700 mb-2">Via Global ({{ detailData.promo_line.via_global.length }})</div>
                        <div v-for="p in detailData.promo_line.via_global" :key="p.promo_id" class="text-sm mb-1">
                            • <span class="font-medium">{{ p.kode_promo }}</span> — {{ p.nama_promo }}
                        </div>
                    </div>
                    <div v-if="detailData.total_promo_eligible === 0" class="text-sm text-surface-500 text-center py-4">Tidak ada promo line eligible.</div>
                </div>
            </div>
            <template #footer>
                <Button label="Tutup" outlined @click="detailDialog = false" />
            </template>
        </Dialog>
    </div>
</template>

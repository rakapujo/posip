<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { serialUnitsApi, produksApi, warehousesApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useExportPdf } from '@/composables/useExportPdf';
import { useAuthStore } from '@/stores/auth';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import SerialLabelPrintDialog from '@/components/common/SerialLabelPrintDialog.vue';

const router = useRouter();
const notify = useNotification();
const { formatCurrency, formatPercent, formatDateTime } = useFormatters();
const authStore = useAuthStore();

// Cost (modal + HPP landed) sensitif → hanya untuk yang berizin lihat HPP (backend juga strip)
const canViewHpp = computed(() => authStore.can('stok.view_hpp'));
const { exporting, exportListPdf } = useExportPdf();
const exportingExcel = ref(false);

// Cetak label barcode
const selectedUnits = ref([]);
const printDialogVisible = ref(false);
const printUnits = ref([]);
const loadingPrint = ref(false);

// Data
const items = ref([]);
const loading = ref(false);
const totalRecords = ref(0);
const summary = ref({ total: 0, tersedia: 0, terjual: 0 });

// Filters
const searchQuery = ref('');
const selectedProduct = ref(null);
const productOptions = ref([]);
const loadingProducts = ref(false);
const warehouses = ref([]);
const selectedWarehouse = ref(null);
const selectedStatus = ref(null);
let filterTimeout = null;

const statusOptions = [
    { label: 'Tersedia', value: 'tersedia' },
    { label: 'Terjual', value: 'terjual' },
    { label: 'Rusak', value: 'rusak' },
    { label: 'Hilang', value: 'hilang' },
    { label: 'Retur', value: 'retur' },
    { label: 'Pending', value: 'pending' }
];

const lazyParams = ref({ first: 0, rows: 15, sortField: 'created_at', sortOrder: -1 });

function buildParams() {
    const params = {
        page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
        per_page: lazyParams.value.rows,
        sort_field: lazyParams.value.sortField || 'created_at',
        sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
    };
    if (searchQuery.value?.trim()) params.search = searchQuery.value.trim();
    if (selectedProduct.value?.ulid) params.product_id = selectedProduct.value.ulid;
    if (selectedWarehouse.value) params.warehouse_id = selectedWarehouse.value;
    if (selectedStatus.value) params.status = selectedStatus.value;
    return params;
}

async function loadData() {
    loading.value = true;
    try {
        const res = await serialUnitsApi.getAll(buildParams());
        if (res.data.success) {
            items.value = res.data.data.items;
            totalRecords.value = res.data.data.pagination.total;
            summary.value = res.data.data.summary;
        }
    } catch (error) {
        notify.loadListError('register unit serial');
    } finally {
        loading.value = false;
    }
}

async function loadWarehouses() {
    try {
        const res = await warehousesApi.getList();
        if (res.data.success) warehouses.value = res.data.data.warehouses;
    } catch (error) {
        console.error('Failed to load warehouses:', error);
    }
}

function onProductSearch(event) {
    // Query kosong (klik dropdown) tetap muat daftar produk serial (limit 50 dari backend)
    const query = event.query?.trim() || '';
    if (filterTimeout) clearTimeout(filterTimeout);
    filterTimeout = setTimeout(async () => {
        loadingProducts.value = true;
        try {
            const res = await produksApi.getList({ search: query, is_serial: 1 });
            if (res.data.success) productOptions.value = res.data.data.produks || [];
        } catch {
            productOptions.value = [];
        } finally {
            loadingProducts.value = false;
        }
    }, 300);
}

function onPage(event) {
    lazyParams.value = { ...lazyParams.value, ...event };
    loadData();
}
function onSort(event) {
    lazyParams.value = { ...lazyParams.value, ...event };
    loadData();
}
function onFilter() {
    lazyParams.value.first = 0;
    loadData();
}
function doSearch() {
    lazyParams.value.first = 0;
    loadData();
}
function clearSearch() {
    searchQuery.value = '';
    doSearch();
}
function resetFilters() {
    searchQuery.value = '';
    selectedProduct.value = null;
    selectedWarehouse.value = null;
    selectedStatus.value = null;
    lazyParams.value.first = 0;
    loadData();
}

// Buka dokumen asal (Pembelian Serial) → auto-open detail
function openIntake(intake) {
    if (!intake?.ulid) return;
    router.push({ name: 'inventory-serial-intake', query: { detail: intake.ulid } });
}

function statusSeverity(status) {
    if (status === 'tersedia') return 'success';
    if (status === 'terjual') return 'info';
    return 'secondary';
}

// Export PDF daftar (hormati filter aktif)
async function exportPdf() {
    const params = { ...buildParams(), page: 1, per_page: 999999 };
    let data;
    try {
        const res = await serialUnitsApi.getAll(params);
        if (!res.data.success) return;
        data = res.data.data.items;
    } catch {
        notify.exportError();
        return;
    }

    const columns = [
        { header: 'No', field: '#', width: 8, align: 'center' },
        { header: 'Kode Internal', width: 26, accessor: (r) => r.kode_internal || '-' },
        { header: 'Nomor Seri', width: 30, accessor: (r) => r.serial_number },
        { header: 'Produk', width: 36, accessor: (r) => (r.product ? `${r.product.kode_produk} - ${r.product.nama_produk}` : '-') },
        // Kolom cost hanya untuk yang berizin lihat HPP
        ...(canViewHpp.value
            ? [
                  { header: 'Modal', width: 24, align: 'right', accessor: (r) => formatCurrency(r.harga_modal) },
                  { header: 'Modal Landed', width: 24, align: 'right', accessor: (r) => (r.cost_per_unit != null ? formatCurrency(r.cost_per_unit) : '-') }
              ]
            : []),
        { header: 'Harga Jual', width: 24, align: 'right', accessor: (r) => (r.harga_jual != null ? formatCurrency(r.harga_jual) : '-') },
        { header: 'Grade', width: 12, align: 'center', accessor: (r) => r.grade || '-' },
        { header: 'Baterai', width: 20, accessor: (r) => r.battery_condition || '-' },
        { header: 'Health', width: 16, align: 'right', accessor: (r) => (r.battery_health != null ? formatPercent(r.battery_health) : '-') },
        { header: 'Akun', width: 18, align: 'center', accessor: (r) => r.account_status || '-' },
        { header: 'Status', width: 16, align: 'center', accessor: (r) => (r.status || '-').toUpperCase() },
        { header: 'Asal Dok.', width: 26, accessor: (r) => r.intake?.nomor_dokumen || '-' }
    ];

    exportListPdf({
        title: 'Register Unit Serial',
        filename: `register_unit_serial`,
        columns,
        data,
        totalLabel: `Total: ${data.length} unit (Tersedia: ${summary.value.tersedia}, Terjual: ${summary.value.terjual})`
    });
}

// Export Excel daftar (hormati filter aktif) — server-side via Maatwebsite
async function exportExcel() {
    exportingExcel.value = true;
    try {
        const res = await serialUnitsApi.export(buildParams());
        const url = window.URL.createObjectURL(new Blob([res.data]));
        const link = document.createElement('a');
        link.href = url;
        const stamp = new Date().toISOString().slice(0, 10);
        link.setAttribute('download', `register_unit_serial_${stamp}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
        notify.exportSuccess();
    } catch {
        notify.exportError();
    } finally {
        exportingExcel.value = false;
    }
}

// Cetak label: pakai unit tercentang; bila kosong → semua sesuai filter aktif
async function openPrint() {
    if (selectedUnits.value.length > 0) {
        printUnits.value = [...selectedUnits.value];
        printDialogVisible.value = true;
        return;
    }
    loadingPrint.value = true;
    try {
        const res = await serialUnitsApi.getAll({ ...buildParams(), page: 1, per_page: 999999 });
        const data = res.data?.success ? res.data.data.items : [];
        if (!data.length) {
            notify.error('Tidak ada unit untuk dicetak');
            return;
        }
        printUnits.value = data;
        printDialogVisible.value = true;
    } catch {
        notify.error('Gagal memuat unit');
    } finally {
        loadingPrint.value = false;
    }
}

onMounted(async () => {
    await loadWarehouses();
    await loadData();
});
</script>

<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <span class="text-xl font-semibold">Register Unit Serial</span>
            </template>
            <template #end>
                <div class="flex gap-2">
                    <Button
                        :label="selectedUnits.length ? `Cetak Label (${selectedUnits.length})` : 'Cetak Label'"
                        icon="pi pi-print"
                        severity="primary"
                        :loading="loadingPrint"
                        @click="openPrint"
                        v-tooltip.bottom="'Cetak label barcode unit (terpilih / semua sesuai filter)'"
                    />
                    <Button label="Export Excel" icon="pi pi-file-excel" severity="success" outlined :loading="exportingExcel" @click="exportExcel" />
                    <Button label="Export PDF" icon="pi pi-file-pdf" severity="help" outlined :loading="exporting" @click="exportPdf" />
                </div>
            </template>
        </Toolbar>

        <!-- Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                <div class="text-surface-500 text-sm mb-1">Total Unit</div>
                <div class="text-2xl font-bold">{{ summary.total }}</div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                <div class="text-green-600 dark:text-green-400 text-sm mb-1">Tersedia</div>
                <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ summary.tersedia }}</div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <div class="text-blue-600 dark:text-blue-400 text-sm mb-1">Terjual</div>
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ summary.terjual }}</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-2 mb-4">
            <AutoComplete
                v-model="selectedProduct"
                :suggestions="productOptions"
                optionLabel="nama_produk"
                placeholder="Filter produk serial..."
                :loading="loadingProducts"
                class="w-72"
                inputClass="w-full"
                @complete="onProductSearch"
                @item-select="onFilter"
                @clear="onFilter"
                dropdown
            >
                <template #option="{ option }">
                    <div class="flex flex-col">
                        <span class="font-medium">{{ option.kode_produk }} - {{ option.nama_produk }}</span>
                        <span class="text-sm text-surface-500">{{ option.barcode }}</span>
                    </div>
                </template>
                <template #chip="{ value }">
                    <span>{{ value.kode_produk }} - {{ value.nama_produk }}</span>
                </template>
            </AutoComplete>
            <Select v-model="selectedWarehouse" :options="warehouses" optionLabel="nama_warehouse" optionValue="id" placeholder="Gudang" class="w-44" filter showClear @change="onFilter" />
            <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-36" showClear @change="onFilter" />
            <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
        </div>

        <DataTable
            :value="items"
            :loading="loading"
            :lazy="true"
            :paginator="true"
            :rows="lazyParams.rows"
            :totalRecords="totalRecords"
            :rowsPerPageOptions="[15, 25, 50]"
            :first="lazyParams.first"
            :sortField="lazyParams.sortField"
            :sortOrder="lazyParams.sortOrder"
            @page="onPage"
            @sort="onSort"
            v-model:selection="selectedUnits"
            removableSort
            dataKey="ulid"
            stripedRows
            showGridlines
            scrollable
        >
            <template #header>
                <DataTableHeader v-model="searchQuery" title="Daftar Unit" placeholder="Cari kode internal / nomor seri..." @search="doSearch" @clear="clearSearch" />
            </template>

            <template #empty>
                <div class="text-center py-6">
                    <i class="pi pi-inbox text-4xl text-surface-400 mb-4"></i>
                    <p class="text-surface-500 m-0">Tidak ada unit serial</p>
                </div>
            </template>

            <Column selectionMode="multiple" headerStyle="width: 3rem" />

            <Column field="kode_internal" header="Kode Internal" sortable style="min-width: 150px">
                <template #body="{ data }"
                    ><span class="font-mono font-medium">{{ data.kode_internal || '—' }}</span></template
                >
            </Column>

            <Column field="serial_number" header="Nomor Seri" sortable style="min-width: 160px">
                <template #body="{ data }"
                    ><span class="font-mono">{{ data.serial_number }}</span></template
                >
            </Column>

            <Column header="Produk" style="min-width: 200px">
                <template #body="{ data }">
                    <div>
                        <span class="font-medium">{{ data.product?.nama_produk }}</span>
                        <div class="text-sm text-surface-500">{{ data.product?.kode_produk }}</div>
                    </div>
                </template>
            </Column>

            <Column field="status" header="Status" style="min-width: 100px">
                <template #body="{ data }">
                    <Tag :value="(data.status || '').toUpperCase()" :severity="statusSeverity(data.status)" />
                </template>
            </Column>

            <Column v-if="canViewHpp" field="harga_modal" sortable style="min-width: 120px" bodyClass="text-right">
                <template #header>
                    <span v-tooltip.top="'Harga beli per unit yang diinput (sebelum biaya/pajak)'">Modal</span>
                </template>
                <template #body="{ data }">{{ formatCurrency(data.harga_modal) }}</template>
            </Column>

            <Column v-if="canViewHpp" style="min-width: 130px" bodyClass="text-right">
                <template #header>
                    <span v-tooltip.top="'HPP riil per unit = Modal + alokasi biaya tambahan + pembulatan (+ pajak bila pengaturan tax_purchase_included_in_hpp aktif). Diskon header tidak mengurangi HPP.'">
                        Modal Landed <i class="pi pi-info-circle text-xs text-surface-400"></i>
                    </span>
                </template>
                <template #body="{ data }">
                    <span class="font-medium">{{ data.cost_per_unit != null ? formatCurrency(data.cost_per_unit) : '—' }}</span>
                </template>
            </Column>

            <Column field="harga_jual" header="Harga Jual" sortable style="min-width: 120px" bodyClass="text-right">
                <template #body="{ data }">{{ data.harga_jual != null ? formatCurrency(data.harga_jual) : '—' }}</template>
            </Column>

            <Column header="Grade" style="min-width: 70px; text-align: center">
                <template #body="{ data }">{{ data.grade || '—' }}</template>
            </Column>

            <Column header="Baterai" style="min-width: 110px">
                <template #body="{ data }">{{ data.battery_condition || '—' }}</template>
            </Column>

            <Column header="Health" style="min-width: 90px" bodyClass="text-right">
                <template #body="{ data }">{{ data.battery_health != null ? formatPercent(data.battery_health) : '—' }}</template>
            </Column>

            <Column header="Akun" style="min-width: 100px; text-align: center">
                <template #body="{ data }">
                    <Tag v-if="data.account_status" :value="data.account_status" :severity="data.account_status === 'unlocked' ? 'success' : 'danger'" />
                    <span v-else>—</span>
                </template>
            </Column>

            <Column header="Gudang" style="min-width: 130px">
                <template #body="{ data }">{{ data.warehouse?.nama_warehouse || '—' }}</template>
            </Column>

            <Column header="Asal Dokumen" style="min-width: 150px">
                <template #body="{ data }">
                    <a v-if="data.intake?.ulid" href="#" class="font-mono text-primary hover:underline" @click.prevent="openIntake(data.intake)" v-tooltip.top="'Buka dokumen pembelian serial'">
                        {{ data.intake.nomor_dokumen }}
                    </a>
                    <span v-else>—</span>
                </template>
            </Column>

            <Column header="Terjual" style="min-width: 140px">
                <template #body="{ data }">
                    <span class="text-sm">{{ data.sold_at ? formatDateTime(data.sold_at) : '—' }}</span>
                </template>
            </Column>
        </DataTable>

        <SerialLabelPrintDialog v-model:visible="printDialogVisible" :units="printUnits" />
    </div>
</template>

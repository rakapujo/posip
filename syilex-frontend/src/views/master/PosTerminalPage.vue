<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useConfirm } from 'primevue/useconfirm';
import { posTerminalsApi, warehousesApi, customersApi, metodePembayaransApi, usersApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { usePrintAdapter } from '@/composables/print/usePrintAdapter';
import { usePrintService } from '@/composables/usePrintService';
import PrinterPickerPanel from '@/components/print/PrinterPickerPanel.vue';
import { useShiftReport } from '@/composables/useShiftReport';
import { useAuthStore } from '@/stores/auth';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import ShiftReportDialog from '@/components/pos/ShiftReportDialog.vue';

const router = useRouter();
const { shouldUppercase, formatDateTime } = useFormatters();
const notify = useNotification();
const confirm = useConfirm();
const authStore = useAuthStore();
const printAdapter = usePrintAdapter();
const legacyPrintService = usePrintService();

// ESC/POS generation
import { useReceiptEscPos } from '@/composables/useReceiptEscPos';
const escpos = useReceiptEscPos();

// Shift Report (composable)
const { shiftReportDialog, shiftReportData, loadingShiftReport, loadShiftReport, printShiftReport: browserPrintShiftReport, downloadShiftReportPdf, closeShiftReport } = useShiftReport();

// Override printShiftReport: direct thermal when available, fallback to browser
const printShiftReport = async () => {
    if (shiftReportData.value) {
        await printAdapter.reconnect();
        const bytes = escpos.buildShiftReport(shiftReportData.value, getPrintOpts());
        const result = await printAdapter.printRaw(bytes, {
            legacyPrinterId: getLegacyPrinterId()
        });
        if (result.success) return;
    }
    browserPrintShiftReport();
};

function getPrintOpts() {
    const t = item.value?.ulid ? item.value : detailData.value;
    return {
        charWidth: t?.char_per_line || 42,
        feedLines: t?.print_feed_before_cut ?? 4,
        compact: t?.paper_mode === 'compact'
    };
}

function getLegacyPrinterId() {
    return (item.value?.default_printer || detailData.value?.default_printer)?.trim() || undefined;
}

const testingPrint = ref(false);
async function testThermalPrint() {
    testingPrint.value = true;
    try {
        await printAdapter.reconnect();
        const bytes = escpos.buildTestPage(getPrintOpts());
        const result = await printAdapter.printRaw(bytes, {
            legacyPrinterId: getLegacyPrinterId()
        });
        if (result.success) notify.success('Test print terkirim');
        else notify.warn(result.message || 'Test print gagal — coba pasangkan printer atau gunakan legacy Print Service');
    } finally {
        testingPrint.value = false;
    }
}

// Permissions
const canCreate = computed(() => authStore.can('terminal.create'));
const canEdit = computed(() => authStore.can('terminal.edit'));
const canDelete = computed(() => authStore.can('terminal.delete'));
const canToggleStatus = computed(() => authStore.can('terminal.toggle-status'));
const canForceRelease = computed(() => authStore.can('terminal.force-release'));

// Current user ULID
const currentUserUlid = computed(() => authStore.user?.ulid);

// ==================== STATE ====================

// Data
const items = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

// Search & Filters
const searchQuery = ref('');
const selectedStatus = ref(null);
const statusOptions = ref([
    { label: 'Semua Status', value: null },
    { label: 'Aktif', value: 'active' },
    { label: 'Nonaktif', value: 'inactive' }
]);

// Pagination
const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'created_at',
    sortOrder: -1
});

// Dialog states
const itemDialog = ref(false);
const detailDialog = ref(false);

// Form states
const submitted = ref(false);
const saving = ref(false);
const loadingDetail = ref(false);
const item = ref({});
const detailData = ref({});
const isEdit = computed(() => !!item.value.ulid);

// Dropdown options
const warehouseOptions = ref([]);
const customerOptions = ref([]);
const defaultPaymentOptions = ref([]);
const paymentMethodOptions = ref([]);
const userOptions = ref([]);
const printerOptions = ref([]);
const loadingPrinters = ref(false);

// Cached list semua terminal aktif (untuk deteksi shared warehouse di form edit).
// Di-fetch sekali saat mount — tidak sensitif ke pagination/filter list utama.
const allActiveTerminals = ref([]);
async function loadAllActiveTerminals() {
    try {
        const res = await posTerminalsApi.getList();
        allActiveTerminals.value = res.data.data.terminals || [];
    } catch {
        allActiveTerminals.value = [];
    }
}

// Terminal aktif lain yang pakai warehouse yang sama dengan yang sedang diedit.
// Kalau ada → tampil banner warning tentang risiko oversell.
const sharedWarehouseTerminals = computed(() => {
    if (!item.value.warehouse_id) return [];
    return allActiveTerminals.value.filter((t) => t.warehouse_id === item.value.warehouse_id && t.id !== item.value.id);
});

async function loadPrinters() {
    loadingPrinters.value = true;
    try {
        const list = await legacyPrintService.getPrinters();
        printerOptions.value = list.map((p) => ({ name: p.name, id: p.id }));
    } catch {
        printerOptions.value = [];
    } finally {
        loadingPrinters.value = false;
    }
}

// ==================== DATA LOADING ====================

async function loadData() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || 'created_at',
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };

        if (searchQuery.value?.trim()) {
            params.search = searchQuery.value.trim();
        }
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }

        const response = await posTerminalsApi.getAll(params);
        if (response.data.success) {
            items.value = response.data.data.terminals || [];
            totalRecords.value = response.data.data.pagination?.total || 0;
        }
    } catch (error) {
        notify.loadListError('terminal');
    } finally {
        loading.value = false;
    }
}

async function loadDropdowns() {
    try {
        const [whRes, custRes, pmTunaiRes, pmAllRes] = await Promise.all([warehousesApi.getList({ is_saleable: 1 }), customersApi.getList({ jenis: 'walk_in' }), metodePembayaransApi.getList({ metode: 'tunai' }), metodePembayaransApi.getList()]);
        warehouseOptions.value = whRes.data.data.warehouses || [];
        customerOptions.value = custRes.data.data.customers || [];
        defaultPaymentOptions.value = pmTunaiRes.data.data.metode_pembayarans || [];
        paymentMethodOptions.value = pmAllRes.data.data.metode_pembayarans || [];
        await Promise.all([loadUsers(), loadAllActiveTerminals()]);
    } catch (error) {
        notify.loadDropdownError('data');
    }
}

// Dropdown "User yang Diizinkan" di form terminal hanya menampilkan user dgn
// permission pos.access. Saat edit, `includeIds` dipakai agar user yang sudah
// ter-assign ke terminal ini tetap muncul meskipun role-nya baru saja dicabut.
async function loadUsers(includeIds = []) {
    try {
        const res = await usersApi.getList({
            permission: 'pos.access',
            include_ids: includeIds
        });
        userOptions.value = res.data.data.users || [];
    } catch (error) {
        notify.loadDropdownError('user');
    }
}

// ==================== SEARCH & FILTER ====================

function doSearch() {
    lazyParams.value.first = 0;
    loadData();
}

function onStatusChange() {
    lazyParams.value.first = 0;
    loadData();
}

function resetFilters() {
    selectedStatus.value = null;
    searchQuery.value = '';
    lazyParams.value.first = 0;
    loadData();
}

// ==================== PAGINATION ====================

function onPageChange(event) {
    lazyParams.value.first = event.first;
    lazyParams.value.rows = event.rows;
    loadData();
}

// ==================== DIALOG MANAGEMENT ====================

const emptyForm = {
    kode_terminal: '',
    nama_terminal: '',
    warehouse_id: null,
    default_customer_id: null,
    default_metode_pembayaran_id: null,
    default_printer: '',
    auto_open_tray: false,
    // Auto-print flags — consumed by PosKasirPage to gate thermal print triggers
    auto_print_receipt: false,
    auto_print_retur: false,
    auto_print_kas: false,
    auto_print_report: false,
    auto_lock_minutes: null,
    // Paper config — consumed by getPrintOpts() (useReceiptEscPos)
    paper_width: 80,
    char_per_line: 48,
    paper_mode: 'normal',
    print_feed_before_cut: 4,
    izinkan_retur: true,
    durasi_retur: null,
    keterangan: '',
    status: 'active',
    user_ids: [],
    metode_pembayaran_ids: []
};

function openNew() {
    item.value = { ...emptyForm, user_ids: [], metode_pembayaran_ids: [] };
    submitted.value = false;
    itemDialog.value = true;
    loadUsers(); // reset dropdown ke filter default (pos.access only)
}

function hideDialog() {
    itemDialog.value = false;
    submitted.value = false;
}

async function editItem(data) {
    // Always fetch full data since card view only has counts
    try {
        const response = await posTerminalsApi.get(data.ulid);
        if (response.data.success) {
            const terminal = response.data.data.terminal;
            const userIds = terminal.users?.map((u) => u.id) || [];
            item.value = {
                ...terminal,
                warehouse_id: terminal.warehouse?.id || terminal.warehouse_id,
                default_customer_id: terminal.default_customer?.id || terminal.default_customer_id || null,
                default_metode_pembayaran_id: terminal.default_metode_pembayaran?.id || terminal.default_metode_pembayaran_id || null,
                user_ids: userIds,
                metode_pembayaran_ids: terminal.allowed_payment_methods?.map((m) => m.id) || []
            };
            submitted.value = false;
            detailDialog.value = false;
            itemDialog.value = true;
            // Reload user options include currently-assigned ones (biar tidak hilang
            // dari dropdown kalau ada user yang permissionnya baru dicabut).
            loadUsers(userIds);
        }
    } catch (error) {
        notify.loadDetailError('terminal');
    }
}

// ==================== AUTO-ASSIGN DEFAULT PAYMENT ====================

watch(
    () => item.value.default_metode_pembayaran_id,
    (newVal) => {
        if (newVal && item.value.metode_pembayaran_ids) {
            if (!item.value.metode_pembayaran_ids.includes(newVal)) {
                item.value.metode_pembayaran_ids = [...item.value.metode_pembayaran_ids, newVal];
            }
        }
    }
);

// ==================== VIEW DETAIL ====================

async function viewDetail(data) {
    detailDialog.value = true;
    loadingDetail.value = true;
    detailData.value = {};

    try {
        const response = await posTerminalsApi.get(data.ulid);
        if (response.data.success) {
            detailData.value = response.data.data.terminal;
        }
    } catch (error) {
        notify.loadDetailError('terminal');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

// ==================== SAVE ====================

async function saveItem() {
    submitted.value = true;

    // Validate required fields
    if (!item.value.kode_terminal?.trim() && !isEdit.value) return;
    if (!item.value.nama_terminal?.trim()) return;
    if (!item.value.warehouse_id) return;
    if (!item.value.default_customer_id) return;
    if (!item.value.default_metode_pembayaran_id) return;
    if (!item.value.metode_pembayaran_ids?.length) return;
    if (!item.value.user_ids?.length) return;

    saving.value = true;
    try {
        const data = {
            nama_terminal: item.value.nama_terminal?.trim(),
            warehouse_id: item.value.warehouse_id,
            default_customer_id: item.value.default_customer_id,
            default_metode_pembayaran_id: item.value.default_metode_pembayaran_id,
            default_printer: item.value.default_printer?.trim() || null,
            auto_open_tray: item.value.auto_open_tray,
            auto_print_receipt: item.value.auto_print_receipt,
            auto_print_retur: item.value.auto_print_retur,
            auto_print_kas: item.value.auto_print_kas,
            auto_print_report: item.value.auto_print_report,
            auto_lock_minutes: item.value.auto_lock_minutes || null,
            paper_width: Number(item.value.paper_width) || 80,
            char_per_line: Number(item.value.char_per_line) || 48,
            paper_mode: item.value.paper_mode || 'normal',
            print_feed_before_cut: Number(item.value.print_feed_before_cut) || 4,
            izinkan_retur: item.value.izinkan_retur,
            durasi_retur: item.value.izinkan_retur ? (item.value.durasi_retur ?? null) : null,
            keterangan: item.value.keterangan?.trim() || null,
            status: item.value.status,
            user_ids: item.value.user_ids || [],
            metode_pembayaran_ids: item.value.metode_pembayaran_ids || []
        };

        if (!isEdit.value) {
            data.kode_terminal = item.value.kode_terminal?.trim();
        }

        let response;
        if (isEdit.value) {
            response = await posTerminalsApi.update(item.value.ulid, data);
        } else {
            response = await posTerminalsApi.create(data);
        }

        if (response.data.success) {
            notify.success(response.data.message);
            itemDialog.value = false;
            item.value = {};
            await loadData();
        }
    } catch (error) {
        notify.saveError(error);
    } finally {
        saving.value = false;
    }
}

// ==================== TOGGLE STATUS ====================

function confirmToggleStatus(data) {
    const isActive = data.status === 'active';
    const action = isActive ? 'menonaktifkan' : 'mengaktifkan';

    confirm.require({
        message: `Apakah Anda yakin ingin ${action} terminal "${data.nama_terminal}"?`,
        header: isActive ? 'Konfirmasi Nonaktifkan' : 'Konfirmasi Aktifkan',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: { label: 'Batal', severity: 'secondary', outlined: true },
        acceptProps: { label: isActive ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan', severity: isActive ? 'warn' : 'success' },
        accept: () => toggleStatus(data)
    });
}

async function toggleStatus(data) {
    try {
        const response = await posTerminalsApi.toggleStatus(data.ulid);
        if (response.data.success) {
            notify.success(response.data.message);
            await loadData();
        }
    } catch (error) {
        notify.statusChangeError(error);
    }
}

// ==================== DELETE ====================

function confirmDelete(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin menghapus terminal "${data.nama_terminal}"? Data yang dihapus tidak dapat dikembalikan.`,
        header: 'Konfirmasi Hapus',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: { label: 'Batal', severity: 'secondary', outlined: true },
        acceptProps: { label: 'Ya, Hapus', severity: 'danger' },
        accept: () => deleteItem(data)
    });
}

async function deleteItem(data) {
    try {
        const response = await posTerminalsApi.delete(data.ulid);
        if (response.data.success) {
            notify.success(response.data.message);
            await loadData();
        }
    } catch (error) {
        notify.deleteError(error);
    }
}

// ==================== FORCE RELEASE ====================
// Flow: admin klik "Tutup Paksa" → load shift report preview (editable) →
// admin isi uang fisik + catatan → klik "Tutup Paksa" → forceRelease dengan payload

const forceReleaseTerminal = ref(null); // terminal yang sedang di-force-release
const forceReleaseProcessing = ref(false);
const forceReleaseSaldoFisik = ref(null);
const forceReleaseNotes = ref('');
const forceReleaseShiftClosed = ref(false);

async function confirmForceRelease(data) {
    if (!data.active_shift?.ulid) {
        notify.warn('Tidak ada shift aktif untuk ditutup');
        return;
    }
    forceReleaseTerminal.value = data;
    forceReleaseSaldoFisik.value = null;
    forceReleaseNotes.value = '';
    forceReleaseShiftClosed.value = false;
    // Load shift report preview (backend mengizinkan admin dengan perm terminal.force-release)
    await loadShiftReport(data.active_shift.ulid);
}

async function submitForceRelease() {
    if (!forceReleaseTerminal.value) return;
    if (forceReleaseSaldoFisik.value === null || forceReleaseSaldoFisik.value === '') {
        notify.warn('Uang Fisik di Laci wajib diisi');
        return;
    }
    const terminal = forceReleaseTerminal.value;
    const shiftUlid = terminal.active_shift?.ulid;
    const isOwnShift = terminal._isOwnShift === true;
    forceReleaseProcessing.value = true;
    try {
        const payload = { saldo_fisik: Number(forceReleaseSaldoFisik.value) };
        if (forceReleaseNotes.value) payload.closing_notes = forceReleaseNotes.value;

        // Own shift → endShift API (normal close)
        // Other's shift → forceRelease API (force close, sets ended_by_force=true)
        const response = isOwnShift ? await posTerminalsApi.endShift(terminal.ulid, payload) : await posTerminalsApi.forceRelease(terminal.ulid, payload);

        if (response.data.success) {
            notify.success(response.data.message);
            forceReleaseShiftClosed.value = true;
            if (shiftUlid) await loadShiftReport(shiftUlid);
            await loadData();
        }
    } catch (error) {
        notify.error(error?.response?.data?.message || 'Gagal menutup shift');
    } finally {
        forceReleaseProcessing.value = false;
    }
}

function closeForceReleaseDialog() {
    closeShiftReport();
    forceReleaseTerminal.value = null;
}

// ==================== SHIFT REPORT (for end shift / force close) ====================
// Using useShiftReport composable (imported above)

// ==================== SHIFT ====================

const shiftLoading = ref(null); // ulid of terminal currently processing shift

function isAssignedToTerminal(terminal) {
    return terminal.users?.some((u) => u.ulid === currentUserUlid.value);
}

function canStartShift(terminal) {
    return !terminal.active_user_id && terminal.status === 'active' && isAssignedToTerminal(terminal);
}

function canEndShift(terminal) {
    return terminal.active_user?.ulid === currentUserUlid.value;
}

function canForceReleaseTerminal(terminal) {
    return canForceRelease.value && terminal.active_user_id && terminal.active_user?.ulid !== currentUserUlid.value;
}

async function startShift(terminal) {
    shiftLoading.value = terminal.ulid;
    try {
        const response = await posTerminalsApi.startShift(terminal.ulid);
        if (response.data.success) {
            notify.success(response.data.message);
            // Redirect to POS Kasir
            router.push({ name: 'pos-kasir' });
        }
    } catch (error) {
        notify.error(error?.response?.data?.message || 'Gagal memulai shift');
    } finally {
        shiftLoading.value = null;
    }
}

function openKasir() {
    router.push({ name: 'pos-kasir' });
}

// "Selesai Shift" dari admin page — same flow as force-close:
// 1. Load shift report preview (editable REKONSILIASI)
// 2. Admin isi uang fisik
// 3. Submit endShift dengan payload
async function confirmEndShift(terminal) {
    if (!terminal.active_shift?.ulid) {
        notify.warn('Tidak ada shift aktif');
        return;
    }
    // Reuse the same forceRelease state/dialog — only difference is which API to call.
    // For same-user "Selesai Shift", we use endShift API (not forceRelease).
    forceReleaseTerminal.value = { ...terminal, _isOwnShift: true };
    forceReleaseSaldoFisik.value = null;
    forceReleaseNotes.value = '';
    forceReleaseShiftClosed.value = false;
    await loadShiftReport(terminal.active_shift.ulid);
}

// ==================== HELPERS ====================

function getStatusSeverity(status) {
    return status === 'active' ? 'success' : 'danger';
}

function getStatusLabel(status) {
    return status === 'active' ? 'Aktif' : 'Nonaktif';
}

function getDurasiReturLabel(durasi) {
    if (durasi === null || durasi === undefined) return 'Unlimited';
    if (durasi === 0) return 'Shift ini saja';
    return `${durasi} hari`;
}

function getTerminalMissingFields(terminal) {
    const missing = [];
    if (!terminal.warehouse_id) missing.push('Warehouse');
    if (!terminal.default_customer_id) missing.push('Default Customer');
    if (!terminal.default_metode_pembayaran_id) missing.push('Default Metode Pembayaran');
    if (!terminal.allowed_payment_methods_count) missing.push('Metode Pembayaran');
    if (!terminal.users_count) missing.push('User');
    // Print Service is OPTIONAL — POS works without it (PDF print fallback).
    // Don't block "Mulai Shift" just because the Python proxy isn't running.
    return missing;
}

function isTerminalComplete(terminal) {
    return getTerminalMissingFields(terminal).length === 0;
}

// ==================== LIFECYCLE ====================

onMounted(async () => {
    await Promise.all([loadData(), loadDropdowns()]);
    printAdapter.checkStatus();
});
</script>

<template>
    <div class="card">
        <!-- Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
            <h5 class="m-0 text-xl font-semibold">POS Terminal</h5>
            <Button v-if="canCreate" label="Terminal" icon="pi pi-plus" @click="openNew" />
        </div>

        <!-- Filters -->
        <div class="flex flex-col md:flex-row gap-3 mb-4">
            <div class="flex-1">
                <IconField>
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="searchQuery" placeholder="Cari terminal..." class="w-full" @keyup.enter="doSearch" />
                </IconField>
            </div>
            <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Status" class="w-full md:w-48" @change="onStatusChange" />
            <Button icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" v-tooltip.top="'Reset Filter'" aria-label="Reset Filter" />
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex justify-center py-12">
            <ProgressSpinner style="width: 50px; height: 50px" />
        </div>

        <!-- Empty state -->
        <div v-else-if="items.length === 0" class="flex flex-col items-center justify-center py-12 text-surface-500">
            <i class="pi pi-desktop text-4xl mb-3"></i>
            <p class="text-lg">Belum ada terminal</p>
        </div>

        <!-- Card Grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="terminal in items" :key="terminal.ulid" class="border border-surface-200 dark:border-surface-700 rounded-lg p-4 flex flex-col">
                <!-- Card Header -->
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center gap-2 cursor-pointer" @click="viewDetail(terminal)">
                        <i class="pi pi-desktop text-xl text-primary"></i>
                        <div>
                            <div class="font-bold text-sm">{{ terminal.kode_terminal }}</div>
                            <div class="font-medium">{{ terminal.nama_terminal }}</div>
                        </div>
                    </div>
                    <Tag :value="getStatusLabel(terminal.status)" :severity="getStatusSeverity(terminal.status)" />
                </div>

                <!-- Card Body -->
                <div class="flex flex-col gap-1.5 text-sm mb-3 flex-1">
                    <div class="flex items-center gap-2 text-surface-600 dark:text-surface-400">
                        <i class="pi pi-building text-xs"></i>
                        <span>{{ terminal.warehouse?.nama_warehouse || '-' }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-surface-600 dark:text-surface-400">
                        <i class="pi pi-credit-card text-xs"></i>
                        <span>{{ terminal.allowed_payment_methods_count || 0 }} metode pembayaran</span>
                    </div>
                    <div class="flex items-center gap-2 text-surface-600 dark:text-surface-400">
                        <i class="pi pi-users text-xs"></i>
                        <span>{{ terminal.users_count || 0 }} user</span>
                    </div>
                </div>

                <!-- Active Session Indicator -->
                <div class="mb-3">
                    <div v-if="terminal.active_user_id" class="flex flex-col gap-1 text-sm bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-400 rounded-md px-3 py-1.5">
                        <div class="flex items-center gap-2">
                            <i class="pi pi-user text-xs"></i>
                            <span>Digunakan: {{ terminal.active_user?.name || 'Unknown' }}</span>
                        </div>
                        <div v-if="terminal.active_shift?.started_at" class="flex items-center gap-2 text-xs opacity-80">
                            <i class="pi pi-clock text-xs"></i>
                            <span>Mulai: {{ formatDateTime(terminal.active_shift.started_at) }}</span>
                            <Tag
                                :value="'Aktif ' + Math.floor((Date.now() - new Date(terminal.active_shift.started_at).getTime()) / 3600000) + ' jam'"
                                :severity="
                                    Math.floor((Date.now() - new Date(terminal.active_shift.started_at).getTime()) / 3600000) >= 24
                                        ? 'danger'
                                        : Math.floor((Date.now() - new Date(terminal.active_shift.started_at).getTime()) / 3600000) >= 8
                                          ? 'warn'
                                          : 'success'
                                "
                                class="ml-1"
                            />
                        </div>
                    </div>
                    <div v-else-if="terminal.status === 'active'" class="flex items-center gap-2 text-sm bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 rounded-md px-3 py-1.5">
                        <i class="pi pi-check-circle text-xs"></i>
                        <span>Tersedia</span>
                    </div>
                </div>

                <!-- Incomplete Config Warning -->
                <div v-if="terminal.status === 'active' && !isTerminalComplete(terminal)" class="flex flex-col gap-1 text-sm bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 rounded-md px-3 py-1.5 mb-3">
                    <div class="flex items-center gap-2 font-medium">
                        <i class="pi pi-exclamation-triangle text-xs"></i>
                        <span>Konfigurasi belum lengkap</span>
                    </div>
                    <div class="text-xs opacity-80">Belum diisi: {{ getTerminalMissingFields(terminal).join(', ') }}</div>
                </div>

                <!-- Shift Actions -->
                <div v-if="terminal.status === 'active'" class="flex flex-col gap-2 mb-3">
                    <Button
                        v-if="canStartShift(terminal)"
                        label="Mulai Shift"
                        icon="pi pi-play"
                        severity="success"
                        size="small"
                        class="w-full"
                        :loading="shiftLoading === terminal.ulid"
                        :disabled="!isTerminalComplete(terminal)"
                        @click="startShift(terminal)"
                    />
                    <template v-if="canEndShift(terminal)">
                        <Button label="Buka Kasir" icon="pi pi-shopping-cart" size="small" class="w-full" @click="openKasir" />
                        <Button label="Selesai Shift" icon="pi pi-stop" severity="warn" size="small" class="w-full" outlined :loading="shiftLoading === terminal.ulid" @click="confirmEndShift(terminal)" />
                    </template>
                    <Button v-if="canForceReleaseTerminal(terminal)" label="Tutup Paksa" icon="pi pi-power-off" severity="danger" size="small" outlined @click="confirmForceRelease(terminal)" />
                </div>

                <!-- Card Footer -->
                <div class="flex items-center gap-2 pt-3 border-t border-surface-200 dark:border-surface-700">
                    <Button v-if="canEdit" icon="pi pi-pencil" severity="info" text rounded size="small" :disabled="!!terminal.active_user_id" @click="editItem(terminal)" v-tooltip.top="'Edit'" aria-label="Edit" />
                    <Button
                        v-if="canToggleStatus"
                        :icon="terminal.status === 'active' ? 'pi pi-times-circle' : 'pi pi-check-circle'"
                        :severity="terminal.status === 'active' ? 'warn' : 'success'"
                        text
                        rounded
                        size="small"
                        :disabled="!!terminal.active_user_id"
                        @click="confirmToggleStatus(terminal)"
                        v-tooltip.top="terminal.status === 'active' ? 'Nonaktifkan' : 'Aktifkan'"
                    />
                    <Button v-if="canDelete" icon="pi pi-trash" severity="danger" text rounded size="small" :disabled="!!terminal.active_user_id" @click="confirmDelete(terminal)" v-tooltip.top="'Hapus'" aria-label="Hapus" />
                    <div class="flex-1"></div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <Paginator v-if="totalRecords > 0" :rows="lazyParams.rows" :totalRecords="totalRecords" :first="lazyParams.first" :rowsPerPageOptions="[10, 25, 50]" @page="onPageChange" class="mt-4" />

        <!-- ==================== FORM DIALOG ==================== -->
        <Dialog v-model:visible="itemDialog" :style="{ width: '700px' }" :header="isEdit ? 'Edit Terminal' : 'Tambah Terminal'" :modal="true" :closable="!saving">
            <div class="grid grid-cols-2 gap-4">
                <!-- Section 1: Informasi Dasar -->
                <div class="col-span-2 border-b pb-2 mb-1">
                    <span class="font-semibold text-sm text-surface-500">Informasi Dasar</span>
                </div>

                <div>
                    <label class="block font-medium mb-2">
                        Kode Terminal <span class="text-red-500">*</span>
                        <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                    </label>
                    <InputText
                        v-model.trim="item.kode_terminal"
                        :invalid="submitted && !item.kode_terminal && !isEdit"
                        :disabled="isEdit"
                        maxlength="20"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan kode terminal"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !item.kode_terminal && !isEdit" class="text-red-500">Kode terminal wajib diisi</small>
                    <small v-else-if="!isEdit" class="text-surface-500">Kode tidak dapat diubah setelah disimpan</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Nama Terminal <span class="text-red-500">*</span></label>
                    <InputText
                        v-model.trim="item.nama_terminal"
                        :invalid="submitted && !item.nama_terminal"
                        maxlength="100"
                        :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                        fluid
                        placeholder="Masukkan nama terminal"
                        autocomplete="off"
                    />
                    <small v-if="submitted && !item.nama_terminal" class="text-red-500">Nama terminal wajib diisi</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Warehouse <span class="text-red-500">*</span></label>
                    <Select v-model="item.warehouse_id" :options="warehouseOptions" filter optionLabel="nama_warehouse" optionValue="id" placeholder="Pilih Warehouse" :invalid="submitted && !item.warehouse_id" fluid />
                    <small v-if="submitted && !item.warehouse_id" class="text-red-500">Warehouse wajib dipilih</small>
                    <small v-else class="text-surface-500">Warehouse tempat stok diambil saat transaksi POS (harus aktif dan saleable)</small>
                    <Message v-if="sharedWarehouseTerminals.length > 0" severity="warn" :closable="false" class="mt-2">
                        <small>
                            <i class="pi pi-exclamation-triangle mr-1"></i>
                            Warehouse ini juga dipakai oleh terminal:
                            <b>{{ sharedWarehouseTerminals.map((t) => t.nama_terminal).join(', ') }}</b
                            >. Pastikan <b>Mode Stok Negatif = block</b> di Pengaturan → Stok untuk hindari oversell saat 2 kasir transaksi bersamaan.
                        </small>
                    </Message>
                </div>

                <div>
                    <label class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="item.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        fluid
                    />
                </div>

                <!-- Section 2: Default Settings -->
                <div class="col-span-2 border-b pb-2 mt-2 mb-1">
                    <span class="font-semibold text-sm text-surface-500">Default Settings</span>
                </div>

                <div>
                    <label class="block font-medium mb-2">Default Customer <span class="text-red-500">*</span></label>
                    <Select v-model="item.default_customer_id" :options="customerOptions" filter optionLabel="nama" optionValue="id" placeholder="Pilih Customer" :invalid="submitted && !item.default_customer_id" fluid />
                    <small v-if="submitted && !item.default_customer_id" class="text-red-500">Default customer wajib dipilih</small>
                    <small v-else class="text-surface-500">Customer otomatis untuk transaksi walk-in (harus aktif dan tipe walk-in)</small>
                </div>

                <div>
                    <label class="block font-medium mb-2">Default Metode Pembayaran <span class="text-red-500">*</span></label>
                    <Select
                        v-model="item.default_metode_pembayaran_id"
                        :options="defaultPaymentOptions"
                        filter
                        optionLabel="nama_pembayaran"
                        optionValue="id"
                        placeholder="Pilih Metode"
                        :invalid="submitted && !item.default_metode_pembayaran_id"
                        fluid
                    />
                    <small v-if="submitted && !item.default_metode_pembayaran_id" class="text-red-500">Default metode pembayaran wajib dipilih</small>
                    <small v-else class="text-surface-500">Metode pembayaran yang dipilih otomatis saat transaksi (harus aktif dan tunai)</small>
                </div>

                <div class="col-span-2">
                    <label class="block font-medium mb-2">Printer Thermal</label>
                    <PrinterPickerPanel :terminal-ulid="item.ulid" class="mb-3" />
                    <div class="flex flex-wrap gap-2 items-end">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-sm text-muted-color mb-1">Legacy Print Service (opsional)</label>
                            <Select
                                v-model="item.default_printer"
                                :options="printerOptions"
                                filter
                                optionLabel="name"
                                optionValue="id"
                                placeholder="ID printer Windows/Network (opsional)"
                                showClear
                                fluid
                                :loading="loadingPrinters"
                            />
                        </div>
                        <Button icon="pi pi-refresh" severity="secondary" outlined @click="loadPrinters" :loading="loadingPrinters" v-tooltip.top="'Refresh legacy printers'" aria-label="Refresh legacy printers" />
                        <Button label="Test Print" icon="pi pi-print" severity="info" outlined :loading="testingPrint" @click="testThermalPrint" />
                    </div>
                    <small class="text-surface-500">Browser pairing disimpan di perangkat ini. Legacy `:5123` dipakai jika browser transport gagal dan ID printer diisi.</small>
                </div>

                <div class="col-span-2 flex items-center gap-3">
                    <ToggleSwitch v-model="item.auto_open_tray" />
                    <label class="font-medium">Auto Open Tray</label>
                </div>

                <!-- Auto Print — kontrol per-jenis-dokumen -->
                <div class="col-span-2 border-b pb-2 mt-2 mb-1">
                    <span class="font-semibold text-sm text-surface-500">Auto Print</span>
                </div>
                <div class="col-span-1 flex items-center gap-3">
                    <ToggleSwitch v-model="item.auto_print_receipt" />
                    <label class="font-medium">Struk Penjualan (auto saat checkout)</label>
                </div>
                <div class="col-span-2 text-sm text-muted-color">
                    Struk retur, kas, dan laporan shift hanya via tombol cetak manual (kebijakan browser thermal).
                </div>
                <div class="col-span-1 flex items-center gap-3 opacity-60 pointer-events-none" v-tooltip.top="'Hanya manual — tidak auto-print'">
                    <ToggleSwitch v-model="item.auto_print_retur" disabled />
                    <label class="font-medium">Struk Retur (manual)</label>
                </div>
                <div class="col-span-1 flex items-center gap-3 opacity-60 pointer-events-none" v-tooltip.top="'Hanya manual — tidak auto-print'">
                    <ToggleSwitch v-model="item.auto_print_kas" disabled />
                    <label class="font-medium">Struk Kas (manual)</label>
                </div>
                <div class="col-span-1 flex items-center gap-3 opacity-60 pointer-events-none" v-tooltip.top="'Hanya manual — tidak auto-print'">
                    <ToggleSwitch v-model="item.auto_print_report" disabled />
                    <label class="font-medium">Laporan Tutup Shift (manual)</label>
                </div>

                <!-- Keamanan -->
                <div class="col-span-2 border-b pb-2 mt-2 mb-1">
                    <span class="font-semibold text-sm text-surface-500">Keamanan</span>
                </div>
                <div class="col-span-2">
                    <label class="block font-medium mb-2">Auto Lock Setelah Idle (menit)</label>
                    <InputNumber v-model="item.auto_lock_minutes" :min="1" :max="120" showButtons fluid placeholder="Kosongkan untuk nonaktif" />
                    <small class="text-surface-500">Layar POS otomatis terkunci kalau kasir tidak aktif selama X menit. Kosongkan untuk nonaktifkan.</small>
                </div>

                <!-- Paper & Print Config — ukuran kertas + feed lines -->
                <div class="col-span-2 border-b pb-2 mt-2 mb-1">
                    <span class="font-semibold text-sm text-surface-500">Pengaturan Kertas & Print</span>
                </div>
                <div class="col-span-1">
                    <label class="block font-medium mb-2">Lebar Kertas (mm)</label>
                    <Select
                        v-model="item.paper_width"
                        :options="[
                            { label: '58 mm', value: 58 },
                            { label: '80 mm', value: 80 }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        fluid
                    />
                </div>
                <div class="col-span-1">
                    <label class="block font-medium mb-2">Karakter per Baris</label>
                    <InputNumber v-model="item.char_per_line" :min="20" :max="72" showButtons fluid />
                    <small class="text-surface-500">Umumnya: 58mm → 32, 80mm → 42/48</small>
                </div>
                <div class="col-span-1">
                    <label class="block font-medium mb-2">Mode Print</label>
                    <Select
                        v-model="item.paper_mode"
                        :options="[
                            { label: 'Normal', value: 'normal' },
                            { label: 'Compact', value: 'compact' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        fluid
                    />
                </div>
                <div class="col-span-1">
                    <label class="block font-medium mb-2">Feed Lines Sebelum Cut</label>
                    <InputNumber v-model="item.print_feed_before_cut" :min="0" :max="6" showButtons fluid />
                </div>

                <!-- Section 3: Konfigurasi Retur -->
                <div class="col-span-2 border-b pb-2 mt-2 mb-1">
                    <span class="font-semibold text-sm text-surface-500">Konfigurasi Retur</span>
                </div>

                <div class="col-span-2 flex items-center gap-3">
                    <ToggleSwitch v-model="item.izinkan_retur" />
                    <label class="font-medium">Izinkan Retur</label>
                </div>

                <div v-if="item.izinkan_retur" class="col-span-2">
                    <label class="block font-medium mb-2">Durasi Retur</label>
                    <InputNumber v-select-on-focus v-model="item.durasi_retur" :min="0" placeholder="Kosongkan untuk unlimited" showButtons fluid />
                    <small class="text-surface-500">0 = shift ini saja, 1+ = jumlah hari, kosong = unlimited</small>
                </div>

                <!-- Section 4: Metode Pembayaran yang Diizinkan -->
                <div class="col-span-2 border-b pb-2 mt-2 mb-1">
                    <span class="font-semibold text-sm text-surface-500">Metode Pembayaran yang Diizinkan</span>
                </div>

                <div class="col-span-2">
                    <label class="block font-medium mb-2">Metode Pembayaran <span class="text-red-500">*</span></label>
                    <MultiSelect
                        v-model="item.metode_pembayaran_ids"
                        :options="paymentMethodOptions"
                        filter
                        optionLabel="nama_pembayaran"
                        optionValue="id"
                        placeholder="Pilih metode pembayaran"
                        display="chip"
                        :invalid="submitted && !item.metode_pembayaran_ids?.length"
                        fluid
                    />
                    <small v-if="submitted && !item.metode_pembayaran_ids?.length" class="text-red-500">Minimal 1 metode pembayaran wajib dipilih</small>
                </div>

                <!-- Section 5: User yang Ditugaskan -->
                <div class="col-span-2 border-b pb-2 mt-2 mb-1">
                    <span class="font-semibold text-sm text-surface-500">User yang Ditugaskan</span>
                </div>

                <div class="col-span-2">
                    <label class="block font-medium mb-2">User <span class="text-red-500">*</span></label>
                    <MultiSelect v-model="item.user_ids" :options="userOptions" filter optionLabel="name" optionValue="id" placeholder="Pilih user" display="chip" :invalid="submitted && !item.user_ids?.length" fluid />
                    <small v-if="submitted && !item.user_ids?.length" class="text-red-500">Minimal 1 user wajib dipilih</small>
                </div>

                <!-- Section 6: Catatan -->
                <div class="col-span-2 border-b pb-2 mt-2 mb-1">
                    <span class="font-semibold text-sm text-surface-500">Catatan</span>
                </div>

                <div class="col-span-2">
                    <Textarea v-model="item.keterangan" rows="3" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" placeholder="Keterangan (opsional)" fluid autoResize />
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveItem" :loading="saving" />
            </template>
        </Dialog>

        <!-- ==================== DETAIL DIALOG ==================== -->
        <DetailDialog
            v-model:visible="detailDialog"
            title="Detail Terminal"
            width="600px"
            :loading="loadingDetail"
            :created-at="detailData.created_at"
            :created-by="detailData.created_by?.name"
            :updated-at="detailData.updated_at"
            :updated-by="detailData.updated_by?.name"
        >
            <template #content>
                <div class="flex flex-col gap-4">
                    <div class="grid grid-cols-2 gap-4">
                        <DetailItem label="Kode Terminal" :value="detailData.kode_terminal" />
                        <DetailItem label="Nama Terminal" :value="detailData.nama_terminal" />
                        <DetailItem label="Warehouse" :value="detailData.warehouse?.nama_warehouse" />
                        <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                        <DetailItem label="Default Customer" :value="detailData.default_customer?.nama || '-'" />
                        <DetailItem label="Default Metode Bayar" :value="detailData.default_metode_pembayaran?.nama_pembayaran || '-'" />
                        <DetailItem label="Default Printer" :value="detailData.default_printer || '-'" />
                        <DetailItem label="Auto Open Tray" :value="detailData.auto_open_tray ? 'Ya' : 'Tidak'" />
                        <DetailItem label="Auto Print Struk" :value="detailData.auto_print_receipt ? 'Ya' : 'Tidak'" />
                        <DetailItem label="Auto Print Retur" :value="detailData.auto_print_retur ? 'Ya' : 'Tidak'" />
                        <DetailItem label="Auto Print Kas" :value="detailData.auto_print_kas ? 'Ya' : 'Tidak'" />
                        <DetailItem label="Auto Print Report" :value="detailData.auto_print_report ? 'Ya' : 'Tidak'" />
                        <DetailItem label="Auto Lock" :value="detailData.auto_lock_minutes ? detailData.auto_lock_minutes + ' menit' : 'Nonaktif'" />
                        <DetailItem label="Lebar Kertas" :value="detailData.paper_width ? detailData.paper_width + ' mm' : '-'" />
                        <DetailItem label="Char per Baris" :value="detailData.char_per_line ?? '-'" />
                        <DetailItem label="Mode Print" :value="detailData.paper_mode || '-'" />
                        <DetailItem label="Feed Lines Sebelum Cut" :value="detailData.print_feed_before_cut ?? '-'" />
                        <DetailItem label="Izinkan Retur" :value="detailData.izinkan_retur ? 'Ya' : 'Tidak'" />
                        <DetailItem v-if="detailData.izinkan_retur" label="Durasi Retur" :value="getDurasiReturLabel(detailData.durasi_retur)" />
                        <DetailItem label="Sesi Aktif" :value="detailData.active_user?.name || 'Tidak ada'" />
                    </div>

                    <!-- Metode Pembayaran -->
                    <div>
                        <div class="font-semibold text-sm mb-2">Metode Pembayaran yang Diizinkan</div>
                        <div v-if="detailData.allowed_payment_methods?.length" class="flex flex-wrap gap-2">
                            <Tag v-for="pm in detailData.allowed_payment_methods" :key="pm.ulid" :value="pm.nama_pembayaran" severity="info" />
                        </div>
                        <div v-else class="text-surface-500 text-sm">Tidak ada metode pembayaran</div>
                    </div>

                    <!-- User -->
                    <div>
                        <div class="font-semibold text-sm mb-2">User yang Ditugaskan</div>
                        <div v-if="detailData.users?.length" class="flex flex-wrap gap-2">
                            <Tag v-for="u in detailData.users" :key="u.ulid" :value="u.name" severity="secondary" />
                        </div>
                        <div v-else class="text-surface-500 text-sm">Tidak ada user</div>
                    </div>

                    <!-- Keterangan -->
                    <div v-if="detailData.keterangan">
                        <div class="font-semibold text-sm mb-1">Keterangan</div>
                        <div class="text-sm text-surface-600 dark:text-surface-400">{{ detailData.keterangan }}</div>
                    </div>
                </div>
            </template>
        </DetailDialog>

        <!-- Shift Report Dialog (force close flow — editable REKONSILIASI) -->
        <ShiftReportDialog
            v-model:visible="shiftReportDialog"
            :data="shiftReportData"
            :loading="loadingShiftReport"
            :closable="false"
            :editable="!forceReleaseShiftClosed && !!forceReleaseTerminal"
            v-model:saldoFisik="forceReleaseSaldoFisik"
            v-model:closingNotes="forceReleaseNotes"
            @print="printShiftReport"
            @download="downloadShiftReportPdf"
            @close="closeForceReleaseDialog"
        >
            <template #footer>
                <!-- Pre-close: Tutup Paksa button — disabled until uang fisik filled -->
                <template v-if="forceReleaseTerminal && !forceReleaseShiftClosed">
                    <Button label="Batal" icon="pi pi-times" severity="secondary" @click="closeForceReleaseDialog" :disabled="forceReleaseProcessing" />
                    <Button
                        :label="forceReleaseTerminal?._isOwnShift ? 'Tutup Shift' : 'Tutup Paksa'"
                        :icon="forceReleaseTerminal?._isOwnShift ? 'pi pi-lock' : 'pi pi-power-off'"
                        :severity="forceReleaseTerminal?._isOwnShift ? 'warn' : 'danger'"
                        @click="submitForceRelease"
                        :loading="forceReleaseProcessing"
                        :disabled="forceReleaseSaldoFisik === null || forceReleaseSaldoFisik === ''"
                    />
                </template>
                <!-- Post-close: print/PDF/close -->
                <template v-else>
                    <Button label="Print" icon="pi pi-print" severity="secondary" @click="printShiftReport" />
                    <Button label="Download PDF" icon="pi pi-file-pdf" severity="secondary" @click="downloadShiftReportPdf" />
                    <Button label="Tutup" icon="pi pi-check" @click="closeForceReleaseDialog" />
                </template>
            </template>
        </ShiftReportDialog>
    </div>
</template>

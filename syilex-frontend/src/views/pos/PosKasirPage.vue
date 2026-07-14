<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { posApi, posTerminalsApi, customersApi, serialUnitsApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import CustomerFormDialog from '@/components/common/CustomerFormDialog.vue';
import { useSettingsStore } from '@/stores/settings';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { useShiftReport } from '@/composables/useShiftReport';
import { usePosCart } from '@/composables/usePosCart';
import { useSessionGuard } from '@/composables/useSessionGuard';
import { useReceiptPdf } from '@/composables/useReceiptPdf';
import { usePrintAdapter } from '@/composables/print/usePrintAdapter';
import { useReceiptEscPos } from '@/composables/useReceiptEscPos';
import { useConfirm } from 'primevue/useconfirm';
import { useLayout } from '@/layout/composables/layout';
import ShiftReportDialog from '@/components/pos/ShiftReportDialog.vue';

const router = useRouter();
const authStore = useAuthStore();
const settingsStore = useSettingsStore();
const { toggleDarkMode, isDarkTheme } = useLayout();
const confirm = useConfirm();
const notify = useNotification();
const { formatDiscLine, downloadReceiptPdf, printReceiptPdf, buildReturPolicyText } = useReceiptPdf();
const printAdapter = usePrintAdapter();
const escpos = useReceiptEscPos();

const canDirectThermal = () => printAdapter.supported.value || printAdapter.isAvailable.value;

async function thermalPrint(bytes, opts = {}) {
    const legacyId = terminalData.value?.default_printer?.trim() || undefined;
    const result = await printAdapter.printRaw(bytes, { ...opts, legacyPrinterId: legacyId });
    if (!result.success) {
        if (result.needPicker) {
            notify.warn('Printer belum dipasangkan. Pasangkan di Master Terminal atau gunakan PDF.');
        } else {
            notify.warn('Gagal print langsung: ' + (result.message || 'Unknown error'));
        }
    }
    return result;
}

// Print settings — derived from active terminal config (paper/chars/feed/mode)
// with sensible fallbacks. Consumed by all escpos.build*() calls.
const printOpts = computed(() => ({
    charWidth: terminalData.value?.char_per_line || 42,
    feedLines: terminalData.value?.print_feed_before_cut ?? 4,
    compact: terminalData.value?.paper_mode === 'compact',
    returPolicy: terminalData.value
        ? {
              izinkan_retur: terminalData.value.izinkan_retur,
              durasi_retur: terminalData.value.durasi_retur
          }
        : null,
    // Receipt footer text (from Global Settings → Toko → Footer Struk)
    footer: settingsStore.store.receiptFooter || null
}));

// ==================== FULLSCREEN ====================
const isFullscreen = ref(false);
const toggleFullscreen = () => {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen();
        isFullscreen.value = true;
    } else {
        document.exitFullscreen();
        isFullscreen.value = false;
    }
};
const {
    formatCurrency,
    formatDateTime,
    formatQty,
    formatPercent,
    shouldUppercase,
    currencySettings,
    getLocale,
    roundSales,
    getCurrencyMinFractionDigits,
    getCurrencyMaxFractionDigits,
    getQtyMinFractionDigits,
    getQtyMaxFractionDigits,
    getPercentMinFractionDigits,
    getPercentMaxFractionDigits
} = useFormatters();

// ==================== LIVE CLOCK ====================
const liveClock = ref('');
let clockInterval = null;

const updateClock = () => {
    const now = new Date();
    const fmt = settingsStore.regional.timeFormat || 'HH:mm';
    const h24 = now.getHours();
    const m = String(now.getMinutes()).padStart(2, '0');
    const s = String(now.getSeconds()).padStart(2, '0');
    if (fmt === 'hh:mm A') {
        const h12 = h24 % 12 || 12;
        const ampm = h24 >= 12 ? 'PM' : 'AM';
        liveClock.value = `${String(h12).padStart(2, '0')}:${m} ${ampm}`;
    } else if (fmt.includes('ss')) {
        liveClock.value = `${String(h24).padStart(2, '0')}:${m}:${s}`;
    } else {
        liveClock.value = `${String(h24).padStart(2, '0')}:${m}`;
    }
};

// ==================== PERMISSIONS ====================
// Manual discount (level 3) — gated by permission + promo settings.
// Admin can disable the whole feature via Settings → Promo:
//   promo.enabled=false → no discount UI at all
//   promo.allow_manual_discount=false → kasir allowed only promo-driven, no manual
const canDiscount = computed(() => {
    if (!authStore.can('pos.discount')) return false;
    const promo = settingsStore.promo;
    if (!promo.enabled) return false;
    if (!promo.allowManualDiscount) return false;
    return true;
});
const canVoid = computed(() => authStore.can('pos.void'));
const canRetur = computed(() => authStore.can('pos.retur'));
const canAddCustomer = computed(() => authStore.can('customer.create'));

// ==================== TERMINAL STATE ====================
const terminalLoading = ref(true);
const terminalData = ref(null);
const taxSettings = ref(null);
const negativeStockAllowed = ref(false);

// ==================== SCREEN LOCK ====================
const isLocked = ref(false);
const unlockCredential = ref('');
const unlockError = ref('');
const unlocking = ref(false);
const locking = ref(false);

// ==================== AUTO-LOCK (idle detection) ====================
let idleTimer = null;
let lastActivityTs = 0;

function resetIdleTimer() {
    const now = Date.now();
    // Throttle: skip kalau aktivitas terakhir < 30 detik lalu
    if (now - lastActivityTs < 30000) return;
    lastActivityTs = now;

    clearTimeout(idleTimer);
    const minutes = terminalData.value?.auto_lock_minutes;
    if (!minutes || isLocked.value) return;
    idleTimer = setTimeout(() => {
        if (!isLocked.value && cart.shiftId.value) {
            lockScreen();
        }
    }, minutes * 60000);
}

function startIdleTracking() {
    if (!terminalData.value?.auto_lock_minutes) return;
    window.addEventListener('mousemove', resetIdleTimer, { passive: true });
    window.addEventListener('keydown', resetIdleTimer, { passive: true });
    window.addEventListener('touchstart', resetIdleTimer, { passive: true });
    resetIdleTimer();
}

function stopIdleTracking() {
    clearTimeout(idleTimer);
    window.removeEventListener('mousemove', resetIdleTimer);
    window.removeEventListener('keydown', resetIdleTimer);
    window.removeEventListener('touchstart', resetIdleTimer);
}

// ==================== CART ====================
const cart = usePosCart();

// ==================== SESSION GUARD (shift duration + token expiry) ====================
const shiftStartedAtRef = ref(null);
const sessionGuard = useSessionGuard({ shiftStartedAt: shiftStartedAtRef });

// ==================== TABS ====================
const activeTab = ref('kasir');
const tabs = computed(() => {
    const t = [
        { key: 'kasir', label: 'Kasir', icon: 'pi pi-shopping-cart' },
        { key: 'kas', label: 'Kas', icon: 'pi pi-wallet' },
        { key: 'transaksi', label: 'Transaksi', icon: 'pi pi-list' },
        { key: 'held', label: `Held`, icon: 'pi pi-pause' }
    ];
    return t;
});

// ==================== LOAD TERMINAL ====================
async function loadTerminal() {
    terminalLoading.value = true;
    try {
        const res = await posApi.getActiveTerminal();
        const data = res.data.data;
        terminalData.value = data.terminal;
        taxSettings.value = data.tax_settings;
        negativeStockAllowed.value = data.negative_stock_allowed;

        cart.setTerminalContext({
            terminalUlid: data.terminal.ulid,
            terminalId: data.terminal.id,
            warehouseId: data.terminal.warehouse?.id,
            shiftId: data.terminal.active_shift?.id,
            negativeStockAllowed: data.negative_stock_allowed,
            discountMode: settingsStore.calculation.discountMode
        });

        // Set default customer
        if (data.terminal.default_customer) {
            cart.setCustomer(data.terminal.default_customer);
        }

        cart.refreshHeldCount();
        shiftStartedAtRef.value = data.terminal.active_shift?.started_at || null;

        // Restore cart from auto-save (e.g. after browser crash/refresh)
        if (cart.restoreCart()) {
            notify.info('Transaksi sebelumnya dipulihkan');
        }

        // Check if shift is locked
        if (data.terminal.active_shift?.is_locked) {
            isLocked.value = true;
        }

        // Check print service availability
        printAdapter.checkStatus();

        // Load initial products
        searchProducts('');

        // Parallel: initial promo load + setor awal
        const defaultCustomerUlid = data.terminal.default_customer?.ulid ?? null;
        await Promise.all([loadActivePromos(defaultCustomerUlid), !isLocked.value ? checkSetorAwal() : Promise.resolve()]);

        // Start polling after initial promo load completes
        startPromoPolling(defaultCustomerUlid);
        startIdleTracking();
    } catch (e) {
        const msg = e.response?.data?.message || 'Gagal memuat terminal aktif';
        notify.error(msg);
        // Redirect back - user doesn't have an active terminal
        router.push({ name: 'dashboard' });
    } finally {
        terminalLoading.value = false;
    }
}

// ==================== ACTIVE PROMOS ====================
// Preview-only: backend rebuilds at checkout (PromoService / CheckoutSalesAction).
// Gated by settings.promo.enabled so admin can kill the feature store-wide.
let promoInterval = null;
const PROMO_POLL_MS = 5 * 60 * 1000;

async function loadActivePromos(customerUlid = null) {
    if (!settingsStore.promo.enabled) {
        cart.setActivePromos([]);
        return;
    }
    try {
        const params = customerUlid ? { customer_ulid: customerUlid } : {};
        const res = await posApi.getActivePromos(params);
        if (res.data.success) {
            // Zombie detection: admin force-closed shift → shift_active === false
            // Strict === false so undefined (old backend) doesn't trigger
            if (res.data.data.shift_active === false) {
                stopPromoPolling();
                shiftKilledDialog.value = true;
                return;
            }
            cart.setActivePromos(res.data.data.promos ?? []);
        }
    } catch {
        // Silent — non-critical; checkout still rebuilds promos server-side
    }
}

function startPromoPolling(customerUlid = null) {
    stopPromoPolling();
    if (!settingsStore.promo.enabled) return;
    promoInterval = setInterval(() => loadActivePromos(customerUlid), PROMO_POLL_MS);
}

function stopPromoPolling() {
    if (promoInterval) {
        clearInterval(promoInterval);
        promoInterval = null;
    }
}

// ==================== SETOR AWAL ====================
const setorAwalDialog = ref(false);
const shiftKilledDialog = ref(false); // Shown when admin force-closes shift while POS is open
const setorAwalNominal = ref(0);
const savingSetorAwal = ref(false);
const hasSetorAwal = ref(false);

const checkSetorAwal = async () => {
    if (!cart.shiftId.value) return;
    try {
        const res = await posApi.getCashSummary({ shift_id: cart.shiftId.value });
        hasSetorAwal.value = res.data.data?.has_setor_awal === true;
        if (!hasSetorAwal.value) {
            setorAwalNominal.value = 0;
            setorAwalDialog.value = true;
        }
    } catch {
        // If failed, still allow POS access
        hasSetorAwal.value = true;
    }
};

const saveSetorAwal = async () => {
    if (setorAwalNominal.value < 0) {
        notify.warn('Nominal tidak boleh negatif');
        return;
    }
    savingSetorAwal.value = true;
    try {
        await posApi.createCashTransaction({
            terminal_id: cart.terminalId.value,
            shift_id: cart.shiftId.value,
            tipe: 'setor_awal',
            nominal: setorAwalNominal.value || 0,
            keterangan: 'Setor awal shift'
        });
        hasSetorAwal.value = true;
        setorAwalDialog.value = false;
        notify.success('Setor awal berhasil disimpan');
    } catch (e) {
        notify.apiError(e, 'Gagal menyimpan setor awal');
    } finally {
        savingSetorAwal.value = false;
    }
};

// ==================== SCREEN LOCK FUNCTIONS ====================
const lockScreen = async () => {
    if (!cart.shiftId.value) return;
    locking.value = true;
    try {
        await posApi.lockShift({ shift_id: cart.shiftId.value });
        isLocked.value = true;
        unlockCredential.value = '';
        unlockError.value = '';
    } catch (e) {
        notify.apiError(e, 'Gagal mengunci layar');
    } finally {
        locking.value = false;
    }
};

const unlockScreen = async () => {
    if (!cart.shiftId.value) return;
    if (!unlockCredential.value) {
        unlockError.value = 'Masukkan PIN atau password untuk membuka kunci';
        return;
    }
    unlocking.value = true;
    unlockError.value = '';
    try {
        await posApi.unlockShift({
            shift_id: cart.shiftId.value,
            credential: unlockCredential.value
        });
        isLocked.value = false;
        unlockCredential.value = '';
        unlockError.value = '';
        // Check setor awal after unlock
        if (!hasSetorAwal.value) {
            await checkSetorAwal();
        }
    } catch (e) {
        unlockError.value = e.response?.data?.message || 'PIN atau password salah';
    } finally {
        unlocking.value = false;
    }
};

const handleUnlockKeydown = (event) => {
    if (event.key === 'Enter') {
        unlockScreen();
    }
};

onMounted(() => {
    loadTerminal();
});

// ==================== PRODUCT SEARCH ====================
const productSearch = ref('');
const products = ref([]);
const loadingProducts = ref(false);
let searchTimeout = null;

const searchProducts = async (query) => {
    loadingProducts.value = true;
    try {
        const res = await posApi.searchProducts({
            warehouse_id: cart.warehouseId.value,
            search: (query || '').trim()
        });
        products.value = res.data.data?.products || [];
    } catch {
        products.value = [];
    } finally {
        loadingProducts.value = false;
    }
};

const onProductSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        searchProducts(productSearch.value);
    }, 300);
};

const onProductSearchEnter = async () => {
    clearTimeout(searchTimeout);
    const query = productSearch.value?.trim();
    if (!query) return;

    // Try barcode exact match first
    try {
        const res = await posApi.getProductByBarcode(query, {
            warehouse_id: cart.warehouseId.value
        });
        if (res.data.data?.product) {
            onProductClick(res.data.data.product);
            productSearch.value = '';
            products.value = [];
            return;
        }
    } catch {
        // Not a barcode, fall through
    }

    // Coba sebagai kode internal / nomor seri unit serial (scan pintar) — hanya bila Modul Elektronik aktif
    if (settingsStore.serialEnabled)
        try {
            const res = await serialUnitsApi.lookup({
                code: query,
                warehouse_id: cart.warehouseId.value
            });
            const d = res.data.data;
            // SN ambigu (>1 unit sellable ber-SN sama) → buka picker kandidat untuk dipilih kasir
            if (d?.ambiguous && d.candidates?.length) {
                openSnPicker(d.candidates[0].product, d.candidates);
                productSearch.value = '';
                products.value = [];
                return;
            }
            if (d?.unit) {
                handleSerialScan(d);
                productSearch.value = '';
                products.value = [];
                return;
            }
        } catch (e) {
            // 404 = kode tak terdaftar → lanjut cari produk biasa (jangan ganggu alur retail)
            if (e?.response?.status !== 404) {
                notify.apiError(e, 'Gagal lookup unit serial');
            }
        }

    // Normal search
    searchProducts(query);
};

// ==================== SCAN UNIT SERIAL ====================
const serialCardDialog = ref(false);
const serialCard = ref(null); // { unit, sellable, reason }

const handleSerialScan = (data) => {
    // Tampilkan kartu unit dulu (kasir verifikasi IMEI/grade/baterai), tambah via tombol.
    serialCard.value = data;
    serialCardDialog.value = true;
};

const addScannedSerialToCart = () => {
    const unit = serialCard.value?.unit;
    if (unit && cart.addSerialUnit(unit)) {
        productSearch.value = '';
        products.value = [];
    }
    serialCardDialog.value = false;
    serialCard.value = null;
};

// ── Pemilih unit serial saat klik produk (daftar SN tersedia + cari) ──
const snPickerDialog = ref(false);
const snPickerProduct = ref(null);
const snPickerUnits = ref([]);
const snPickerLoading = ref(false);
const snPickerSearch = ref('');

const openSnPicker = async (product, preloaded = null) => {
    snPickerProduct.value = product;
    snPickerSearch.value = '';
    snPickerDialog.value = true;
    // Mode kandidat (dari scan SN ambigu): pakai daftar yang sudah diberikan, tak perlu load ulang.
    if (preloaded) {
        snPickerUnits.value = preloaded;
        snPickerLoading.value = false;
        return;
    }
    snPickerUnits.value = [];
    snPickerLoading.value = true;
    try {
        const res = await serialUnitsApi.available({ product_id: product.ulid, warehouse_id: cart.warehouseId.value });
        snPickerUnits.value = res.data?.success ? res.data.data.items : [];
    } catch (e) {
        notify.apiError(e, 'Gagal memuat unit serial');
    } finally {
        snPickerLoading.value = false;
    }
};

// SN yang sudah ada di keranjang untuk produk ini (agar tak bisa dipilih dobel)
const snInCart = computed(() => {
    const line = cart.items.value.find((i) => i.is_serial && i.product_id === snPickerProduct.value?.id);
    return new Set(line?.serial_unit_ids || []);
});

const filteredPickerUnits = computed(() => {
    const q = snPickerSearch.value.trim().toLowerCase();
    return snPickerUnits.value
        .filter((u) => !snInCart.value.has(u.ulid))
        .filter(
            (u) =>
                !q ||
                String(u.serial_number).toLowerCase().includes(q) ||
                String(u.kode_internal || '')
                    .toLowerCase()
                    .includes(q)
        );
});

const pickSnUnit = (u) => {
    const p = snPickerProduct.value;
    if (!p) return;
    cart.addSerialUnit({
        ulid: u.ulid,
        serial_number: u.serial_number,
        kode_internal: u.kode_internal,
        harga_jual: u.harga_jual,
        grade: u.grade,
        battery_condition: u.battery_condition,
        battery_health: u.battery_health,
        account_status: u.account_status,
        catatan: u.catatan,
        // product.id diambil dari produk yang diklik (sudah visible) — bukan dari unit
        product: { id: p.id, ulid: p.ulid, kode_produk: p.kode_produk, nama_produk: p.nama_produk }
    });
};

// Format ringkas 1 unit serial untuk preview/nota di halaman POS
const serialLineText = (u) => {
    const parts = [];
    if (u.kode_internal) parts.push(u.kode_internal);
    parts.push(`SN ${u.serial_number}`);
    if (u.grade) parts.push(`Grade ${u.grade}`);
    if (u.battery_health != null && u.battery_health !== '') {
        parts.push(`🔋${u.battery_health}%${u.battery_condition ? ' ' + u.battery_condition : ''}`);
    }
    if (u.account_status) parts.push(u.account_status);
    if (u.catatan) parts.push(u.catatan);
    return parts.join(' · ');
};

// ==================== UNIT SELECTION POPUP ====================
const unitSelectDialog = ref(false);
const unitSelectProduct = ref(null);
const unitSelectOptions = ref([]);

const buildUnitOptions = (product) => {
    const units = [];
    const seen = new Set();
    for (let i = 1; i <= 4; i++) {
        const unit = product[`unit_${i}`];
        const konversi = product[`konversi_${i}`];
        const harga = product[`harga_${i}`];
        if (unit && konversi && harga && !seen.has(unit)) {
            seen.add(unit);
            units.push({ index: i - 1, unit, konversi, harga });
        }
    }
    return units;
};

const getBaseUnit = (product) => {
    // Base unit = smallest unit (highest index with konversi=1, or last defined unit)
    for (let i = 4; i >= 1; i--) {
        if (product[`unit_${i}`] && product[`konversi_${i}`] === 1) return product[`unit_${i}`];
    }
    // Fallback: last defined unit
    for (let i = 4; i >= 1; i--) {
        if (product[`unit_${i}`]) return product[`unit_${i}`];
    }
    return product.unit_1 || 'PCS';
};

const getBasePrice = (product) => {
    for (let i = 4; i >= 1; i--) {
        if (product[`unit_${i}`] && product[`konversi_${i}`] === 1 && product[`harga_${i}`]) return product[`harga_${i}`];
    }
    // Fallback: last defined unit's price
    for (let i = 4; i >= 1; i--) {
        if (product[`harga_${i}`]) return product[`harga_${i}`];
    }
    return product.harga_1 || 0;
};

const getProductUnitNames = (product) => {
    const names = [];
    const seen = new Set();
    for (let i = 1; i <= 4; i++) {
        const unit = product[`unit_${i}`];
        if (unit && product[`konversi_${i}`] && product[`harga_${i}`] && !seen.has(unit)) {
            seen.add(unit);
            names.push(unit);
        }
    }
    return names;
};

const onProductClick = (product) => {
    // Produk serial: buka pemilih unit (daftar SN tersedia + cari)
    if (product.is_serial) {
        openSnPicker(product);
        return;
    }
    const units = buildUnitOptions(product);
    if (units.length <= 1) {
        // Single unit, add directly
        cart.addItem(product, 0);
        return;
    }
    // Multiple units, show selection dialog
    unitSelectProduct.value = product;
    unitSelectOptions.value = units;
    unitSelectDialog.value = true;
};

const selectUnit = (unitIndex) => {
    if (unitSelectProduct.value) {
        cart.addItem(unitSelectProduct.value, unitIndex);
    }
    unitSelectDialog.value = false;
    unitSelectProduct.value = null;
};

// ==================== CUSTOMER DROPDOWN ====================
const customerOptions = ref([]);
const loadingCustomers = ref(false);

const searchCustomers = async (event) => {
    loadingCustomers.value = true;
    try {
        const res = await customersApi.getList({ search: event?.query || '' });
        customerOptions.value = res.data.data?.customers || [];
    } catch {
        customerOptions.value = [];
    } finally {
        loadingCustomers.value = false;
    }
};

const onCustomerSelect = (event) => {
    cart.setCustomer(event.value);
    const ulid = event.value?.ulid ?? null;
    loadActivePromos(ulid);
    startPromoPolling(ulid);
};

const onCustomerClear = () => {
    // Reset to default customer
    const defaultCustomer = terminalData.value?.default_customer ?? null;
    cart.setCustomer(defaultCustomer);
    const ulid = defaultCustomer?.ulid ?? null;
    loadActivePromos(ulid);
    startPromoPolling(ulid);
};

// Tambah customer baru langsung dari POS (reuse CustomerFormDialog) → set sbg customer keranjang
const customerDialog = ref(false);
const onNewCustomerSaved = (newCustomer) => {
    if (!newCustomer) return;
    cart.setCustomer(newCustomer);
    customerOptions.value = [newCustomer];
    const ulid = newCustomer?.ulid ?? null;
    loadActivePromos(ulid);
    startPromoPolling(ulid);
};

// ==================== PAYMENT DIALOG ====================
const paymentDialog = ref(false);
const paymentMethods = ref([]); // selected payment lines
const paymentProcessing = ref(false);

const allowedPaymentMethods = computed(() => {
    return terminalData.value?.allowed_payment_methods || [];
});

const openPaymentDialog = async () => {
    if (!cart.hasItems.value) {
        notify.warn('Keranjang kosong');
        return;
    }

    // Calculate totals first
    await cart.calculateTotals([]);

    // Initialize with default payment method
    const defaultMethod = terminalData.value?.default_metode_pembayaran;
    paymentMethods.value = [];
    if (defaultMethod) {
        addPaymentLine(defaultMethod);
    } else if (allowedPaymentMethods.value.length > 0) {
        addPaymentLine(allowedPaymentMethods.value[0]);
    }

    paymentDialog.value = true;
};

const togglePaymentLine = (method) => {
    const idx = paymentMethods.value.findIndex((p) => p.metode_pembayaran_id === method.id);
    if (idx >= 0) {
        removePaymentLine(idx);
    } else {
        addPaymentLine(method);
    }
};

const addPaymentLine = (method) => {
    // Don't add if already exists
    if (paymentMethods.value.some((p) => p.metode_pembayaran_id === method.id)) return;

    paymentMethods.value.push({
        metode_pembayaran_id: method.id,
        method, // full object for display
        nominal: 0,
        reference: ''
    });
    // Auto-fill yang baru ditambah = sisa yang belum terbayar + fee
    autoFillLastPayment();
};

const autoFillLastPayment = () => {
    if (paymentMethods.value.length === 0) return;
    const gt = Number(cart.totals.value?.grand_total ?? 0);
    const last = paymentMethods.value[paymentMethods.value.length - 1];
    // Sum nominal semua KECUALI yang terakhir
    const othersNominal = paymentMethods.value.slice(0, -1).reduce((sum, p) => sum + (p.nominal || 0), 0);
    const remaining = Math.max(0, gt - othersNominal);
    last.nominal = grossUpNominal(remaining, last.method);
};

const removePaymentLine = (index) => {
    paymentMethods.value.splice(index, 1);
    // Auto-fill yang terakhir = sisa
    if (paymentMethods.value.length > 0) {
        autoFillLastPayment();
    }
};

const calculateFee = (nominal, method) => {
    if (!method) return 0;
    if (method.biaya_tambahan_tipe === 'percent') {
        return Math.round((nominal * Number(method.biaya_tambahan_nilai || 0)) / 100);
    }
    if (method.biaya_tambahan_tipe === 'nominal') {
        return Number(method.biaya_tambahan_nilai) || 0;
    }
    return 0;
};

// Hitung nominal gross (termasuk fee) supaya net setelah fee menutup `remaining`.
// Percent: nominal = remaining / (1 - rate). Nominal tetap: nominal = remaining + fee.
const grossUpNominal = (remaining, method) => {
    const r = Math.max(0, Number(remaining) || 0);
    if (!method) return r;
    if (method.biaya_tambahan_tipe === 'percent') {
        const rate = Number(method.biaya_tambahan_nilai || 0) / 100;
        if (rate <= 0 || rate >= 1) return r;
        return Math.round(r / (1 - rate));
    }
    if (method.biaya_tambahan_tipe === 'nominal') {
        return r + (Number(method.biaya_tambahan_nilai) || 0);
    }
    return r;
};

/**
 * Generate pay recommendation amounts for cash payment.
 * From grand total, generate rounded-up amounts in common denominations.
 */
const getPayRecommendations = (grandTotal) => {
    const gt = Number(grandTotal) || 0;
    if (gt <= 0) return [];

    const denominations = [1000, 2000, 5000, 10000, 20000, 50000, 100000];
    const recommendations = new Set();

    // "Uang Pas" (exact amount) always first
    recommendations.add(gt);

    // Round up to nearest denominations
    for (const d of denominations) {
        const rounded = Math.ceil(gt / d) * d;
        if (rounded > gt && rounded <= gt * 3) {
            recommendations.add(rounded);
        }
        // Also add the next step up
        const next = rounded + d;
        if (next > gt && next <= gt * 3) {
            recommendations.add(next);
        }
    }

    // Sort and take top 6 (including uang pas)
    const sorted = [...recommendations].sort((a, b) => a - b);
    return sorted.slice(0, 7);
};

// Per-line rekomendasi: berdasarkan SISA yang harus dibayar oleh line itu
const getLineRecommendations = (pmIndex) => {
    const gt = Number(cart.totals.value?.grand_total ?? 0);
    const othersNominal = paymentMethods.value.filter((_, i) => i !== pmIndex).reduce((sum, p) => sum + (p.nominal || 0), 0);
    const remaining = Math.max(0, gt - othersNominal);
    const pm = paymentMethods.value[pmIndex];
    return getPayRecommendations(grossUpNominal(remaining, pm?.method));
};

const setPayNominal = (pmIndex, amount) => {
    paymentMethods.value[pmIndex].nominal = amount;
};

const getMethodIcon = (method) => {
    return method?.metode === 'tunai' ? 'pi pi-money-bill' : 'pi pi-credit-card';
};

const totalBiayaPembayaran = computed(() => {
    return paymentMethods.value.reduce((sum, p) => sum + calculateFee(p.nominal || 0, p.method), 0);
});

const totalYangHarusDibayar = computed(() => {
    return Number(cart.totals.value?.grand_total ?? 0) + totalBiayaPembayaran.value;
});

const totalBayar = computed(() => {
    return paymentMethods.value.reduce((sum, p) => sum + (p.nominal || 0), 0);
});

const sisaBayar = computed(() => {
    return Math.max(0, totalYangHarusDibayar.value - totalBayar.value);
});

const kembalian = computed(() => {
    const lebih = totalBayar.value - totalYangHarusDibayar.value;
    // Only cash methods give change
    const hasCash = paymentMethods.value.some((p) => p.method?.metode === 'tunai');
    return hasCash && lebih > 0 ? lebih : 0;
});

const canProcessPayment = computed(() => {
    if (paymentMethods.value.length === 0) return false;
    const isFree = totalYangHarusDibayar.value === 0;
    for (const p of paymentMethods.value) {
        // Nominal 0 sah kalau transaksi gratis (grand total = 0)
        if (p.nominal < 0) return false;
        if (p.nominal === 0 && !isFree) return false;
    }
    return totalBayar.value >= totalYangHarusDibayar.value;
});

const processPayment = async () => {
    if (!canProcessPayment.value) return;
    paymentProcessing.value = true;

    const payments = paymentMethods.value.map((p) => ({
        metode_pembayaran_id: p.metode_pembayaran_id,
        nominal: p.nominal,
        biaya_tambahan: calculateFee(p.nominal || 0, p.method),
        reference: p.reference || null
    }));

    const sales = await cart.checkout(payments);
    paymentProcessing.value = false;

    if (sales) {
        paymentDialog.value = false;
        lastSales.value = sales;
        isAfterCheckout.value = true;
        receiptDialog.value = true;

        // Direct thermal print after checkout — ONLY auto_print_receipt
        if (terminalData.value?.auto_print_receipt && canDirectThermal()) {
            tryDirectPrint(sales.ulid);
        }

        // Reset customer to default
        if (terminalData.value?.default_customer) {
            cart.setCustomer(terminalData.value.default_customer);
        }
        // Refresh product list to update stock display
        searchProducts(productSearch.value || '');
    }
};

// ==================== RECEIPT DIALOG ====================
const receiptDialog = ref(false);
const lastSales = ref(null);
const receiptData = ref(null);
const loadingReceipt = ref(false);
const isAfterCheckout = ref(false); // Flag to show "Transaksi Baru" only after checkout

watch(lastSales, async (sales) => {
    if (!sales?.ulid) return;
    loadingReceipt.value = true;
    try {
        const res = await posApi.getSales(sales.ulid);
        receiptData.value = res.data.data?.sales;
    } catch {
        notify.error('Gagal memuat data struk');
    } finally {
        loadingReceipt.value = false;
    }
});

watch(receiptDialog, async (open) => {
    if (open && canDirectThermal()) {
        try {
            await printAdapter.reconnect();
        } catch {
            /* picker shown on print if needed */
        }
    }
});

const printReceipt = () => {
    if (!receiptData.value) return;
    if (canDirectThermal()) {
        tryDirectPrint(receiptData.value.ulid);
    } else {
        printReceiptPdf(receiptData.value, {
            returPolicy: printOpts.value.returPolicy
        });
    }
};

// Show receipt dialog for a given sales ulid (view mode, not after checkout)
const showReceipt = (ulid) => {
    lastSales.value = { ulid };
    isAfterCheckout.value = false;
    receiptDialog.value = true;
};

// ─── Struk Online URL ───
const getReceiptUrl = (ulid) => {
    const baseUrl = (settingsStore.store.url || '').replace(/\/+$/, '') || window.location.origin;
    return `${baseUrl}/struk-online/${ulid}`;
};

// ─── WhatsApp ───
const waDialog = ref(false);
const waPhone = ref('');

const openWhatsApp = () => {
    if (!receiptData.value) return;
    // Auto-fill from customer phone
    const phone = receiptData.value.customer?.telepon || '';
    waPhone.value = phone.startsWith('0') ? '62' + phone.slice(1) : phone;
    waDialog.value = true;
};

const sendWhatsApp = () => {
    if (!waPhone.value || !receiptData.value) return;
    const url = getReceiptUrl(receiptData.value.ulid);
    const storeName = settingsStore.store.name || 'POSIP';
    const msg = `Terima kasih telah berbelanja di ${storeName}.\nBerikut struk belanja Anda:\n${url}`;
    const phone = waPhone.value.replace(/\D/g, '');
    window.open(`https://wa.me/${phone}?text=${encodeURIComponent(msg)}`, '_blank');
    waDialog.value = false;
};

// ─── Direct Thermal Print (via Print Service) ───
async function tryDirectPrint(salesUlid) {
    try {
        await printAdapter.reconnect();
        const res = await posApi.getSales(salesUlid);
        const salesData = res.data.data?.sales;
        if (!salesData) return;

        const openDrawer = terminalData.value?.auto_open_tray || false;
        const bytes = escpos.buildReceipt(salesData, { ...printOpts.value, openDrawer });
        await thermalPrint(bytes);
    } catch {
        notify.warn('Gagal mencetak struk thermal — gunakan PDF');
    }
}

// ─── PDF Download (delegated to useReceiptPdf composable) ───
const downloadPdf = () =>
    downloadReceiptPdf(receiptData.value, {
        returPolicy: printOpts.value.returPolicy
    });

// Retur policy text for the on-screen receipt preview. Uses same builder as PDF/thermal
// so preview, PDF, and thermal all display the SAME sentence.
const returPolicyText = computed(() => {
    if (!receiptData.value?.tanggal) return '';
    return buildReturPolicyText(printOpts.value.returPolicy, receiptData.value.tanggal);
});

const newTransaction = () => {
    receiptDialog.value = false;
    lastSales.value = null;
    receiptData.value = null;
    productSearch.value = '';
    nextTick(() => {
        // Focus search
    });
};

// ==================== HEADER DISCOUNT DIALOG ====================
// ==================== DISC NOTA 3 (MANUAL) ====================
const discountDialog = ref(false);
const discountForm = ref({ tipe: 'percent', nilai: 0 });

const openDiscountDialog = () => {
    const d3 = cart.discounts.value[2];
    discountForm.value = {
        tipe: d3.tipe !== 'none' ? d3.tipe : 'percent',
        nilai: d3.nilai || 0
    };
    discountDialog.value = true;
};

const applyDiscount = () => {
    let { tipe, nilai } = discountForm.value;
    const promo = settingsStore.promo;

    // Enforce max caps from Settings. 0/null means "no cap" per SettingService
    // defaults. Using Math.min clamps server-side equivalently.
    if (tipe === 'percent' && promo.maxManualDiscountPercent) {
        nilai = Math.min(Number(nilai), Number(promo.maxManualDiscountPercent));
    }
    if (tipe === 'nominal' && promo.maxManualDiscountNominal) {
        nilai = Math.min(Number(nilai), Number(promo.maxManualDiscountNominal));
    }

    cart.setDiscount(3, tipe, nilai);
    discountDialog.value = false;
};

const removeDiscount = () => {
    cart.setDiscount(3, 'none', 0);
    discountDialog.value = false;
};

// ==================== BIAYA KIRIM & LAIN ====================
const biayaDialog = ref(false);
const biayaForm = ref({
    kirim_tipe: 'nominal',
    kirim_nilai: 0,
    lain_tipe: 'nominal',
    lain_nilai: 0
});

const openBiayaDialog = () => {
    biayaForm.value = {
        kirim_tipe: cart.biayaKirim.value.tipe !== 'none' ? cart.biayaKirim.value.tipe : 'nominal',
        kirim_nilai: cart.biayaKirim.value.nilai || 0,
        lain_tipe: cart.biayaLain.value.tipe !== 'none' ? cart.biayaLain.value.tipe : 'nominal',
        lain_nilai: cart.biayaLain.value.nilai || 0
    };
    biayaDialog.value = true;
};

const applyBiaya = () => {
    cart.setBiayaKirim(biayaForm.value.kirim_nilai > 0 ? biayaForm.value.kirim_tipe : 'none', biayaForm.value.kirim_nilai);
    cart.setBiayaLain(biayaForm.value.lain_nilai > 0 ? biayaForm.value.lain_tipe : 'none', biayaForm.value.lain_nilai);
    biayaDialog.value = false;
};

const clearBiaya = () => {
    cart.clearBiaya();
    biayaDialog.value = false;
};

// ==================== LINE DISCOUNT ====================
const lineDiscountDialog = ref(false);
const lineDiscountItem = ref(null);
const lineDiscountTipe = ref('percent');
const lineDiscountValue = ref(0);

const openLineDiscount = (item) => {
    lineDiscountItem.value = item;
    lineDiscountTipe.value = item.diskon_5_tipe === 'nominal' ? 'nominal' : 'percent';
    lineDiscountValue.value = item.diskon_5_nilai || 0;
    lineDiscountDialog.value = true;
};

const applyLineDiscount = () => {
    if (lineDiscountItem.value) {
        cart.setLineDiscount(lineDiscountItem.value.id, lineDiscountTipe.value, lineDiscountValue.value);
    }
    lineDiscountDialog.value = false;
};

/**
 * Build discount breakdown string for display
 * e.g. "5,00%+Rp1.000+500+Rp1.600"
 */
const getDiscountBreakdown = (item) => {
    const parts = [];
    for (let i = 1; i <= 5; i++) {
        const tipe = item[`diskon_${i}_tipe`];
        const nilai = item[`diskon_${i}_nilai`];
        if (tipe === 'none' || !nilai) continue;
        if (tipe === 'percent') {
            parts.push(`${formatPercent(nilai)}`);
        } else {
            parts.push(formatCurrency(nilai));
        }
    }
    return parts;
};

const hasAnyDiscount = (item) => {
    for (let i = 1; i <= 5; i++) {
        if (item[`diskon_${i}_tipe`] !== 'none' && item[`diskon_${i}_nilai`] > 0) return true;
    }
    return false;
};

// ==================== HOLD ====================
const onHold = () => {
    cart.holdCart();
};

const onResume = (holdIndex) => {
    if (cart.hasItems.value) {
        confirm.require({
            message: 'Keranjang saat ini akan ditimpa. Lanjutkan?',
            header: 'Konfirmasi',
            icon: 'pi pi-exclamation-triangle',
            acceptLabel: 'Ya',
            rejectLabel: 'Batal',
            accept: () => {
                cart.resumeHold(holdIndex);
                activeTab.value = 'kasir';
            }
        });
    } else {
        cart.resumeHold(holdIndex);
        activeTab.value = 'kasir';
    }
};

const onDeleteHold = (holdIndex) => {
    confirm.require({
        message: 'Hapus transaksi ditahan ini?',
        header: 'Konfirmasi',
        icon: 'pi pi-trash',
        acceptLabel: 'Hapus',
        rejectLabel: 'Batal',
        acceptClass: 'p-button-danger',
        accept: () => {
            cart.deleteHold(holdIndex);
        }
    });
};

// Load data when switching tabs
watch(activeTab, (tab) => {
    if (tab === 'transaksi') {
        transaksiSessionType.value = 'current';
        loadTransaksiList();
    }
    if (tab === 'kas') {
        loadCashTransactions();
        loadCashSummary();
    }
});

// ==================== KAS (CASH TRANSACTIONS) ====================
const kasForm = ref({ tipe: 'setor_awal', nominal: 0, keterangan: '' });
const kasSaving = ref(false);
const lastKasData = ref(null);

const printLastKas = async () => {
    if (!lastKasData.value || !canDirectThermal()) return;
    const bytes = escpos.buildCashReceipt(lastKasData.value, printOpts.value);
    await thermalPrint(bytes);
};
const kasTunai = ref([]);
const kasNonTunai = ref([]);
const kasSubtotalTunai = ref(0);
const kasSubtotalNonTunai = ref(0);
const kasSummary = ref(null);
const loadingKas = ref(false);
const loadingKasSummary = ref(false);

const kasHasSetorAwal = computed(() => {
    // Lock setor awal radio only when nominal > 0
    if (kasSummary.value) {
        return (kasSummary.value.setor_awal || 0) > 0;
    }
    // Before summary loaded: check from initial checkSetorAwal + entered nominal
    return hasSetorAwal.value && (setorAwalNominal.value || 0) > 0;
});

const loadCashTransactions = async () => {
    if (!cart.shiftId.value) return;
    loadingKas.value = true;
    try {
        const res = await posApi.getCashTransactions({ shift_id: cart.shiftId.value });
        const data = res.data.data;
        kasTunai.value = data?.tunai || [];
        kasNonTunai.value = data?.non_tunai || [];
        kasSubtotalTunai.value = data?.subtotal_tunai || 0;
        kasSubtotalNonTunai.value = data?.subtotal_non_tunai || 0;
    } catch {
        notify.loadListError('kas');
    } finally {
        loadingKas.value = false;
    }
};

const loadCashSummary = async () => {
    if (!cart.shiftId.value) return;
    loadingKasSummary.value = true;
    try {
        const res = await posApi.getCashSummary({ shift_id: cart.shiftId.value });
        kasSummary.value = res.data.data;
    } catch {
        // silent
    } finally {
        loadingKasSummary.value = false;
    }
};

const saveCashTransaction = async () => {
    if (kasForm.value.nominal <= 0) {
        notify.warn('Nominal harus lebih dari 0');
        return;
    }
    if (kasForm.value.tipe === 'kas_keluar' && !kasForm.value.keterangan?.trim()) {
        notify.warn('Keterangan wajib diisi untuk kas keluar');
        return;
    }
    kasSaving.value = true;
    try {
        const savedTipe = kasForm.value.tipe;
        const savedNominal = kasForm.value.nominal;
        const savedKeterangan = kasForm.value.keterangan?.trim() || null;
        await posApi.createCashTransaction({
            terminal_id: cart.terminalId.value,
            shift_id: cart.shiftId.value,
            tipe: savedTipe,
            nominal: savedNominal,
            keterangan: savedKeterangan
        });
        notify.success('Transaksi kas berhasil disimpan');

        // Save last kas data for manual reprint
        lastKasData.value = {
            tipe: savedTipe,
            nominal: savedNominal,
            keterangan: savedKeterangan,
            terminal: terminalData.value.kode_terminal || '-',
            kasir: authStore.user?.name || '-',
            date: new Date().toLocaleString('id-ID')
        };

        kasForm.value = { tipe: 'kas_masuk', nominal: 0, keterangan: '' };
        loadCashTransactions();
        loadCashSummary();
    } catch (e) {
        notify.apiError(e, 'Gagal menyimpan transaksi kas');
    } finally {
        kasSaving.value = false;
    }
};

const getTipeLabel = (tipe) => {
    const labels = {
        setor_awal: 'Setor Awal',
        kas_masuk: 'Kas Masuk',
        kas_keluar: 'Kas Keluar',
        penjualan: 'Penjualan'
    };
    return labels[tipe] || tipe;
};

const getTipeSeverity = (tipe) => {
    const severities = {
        setor_awal: 'info',
        kas_masuk: 'success',
        kas_keluar: 'danger',
        penjualan: 'success'
    };
    return severities[tipe] || 'secondary';
};

// ==================== RETUR (shared state for transaksi tab) ====================
const returSalesDetail = ref(null);
const loadingReturDetail = ref(false);
const returItems = ref([]);
const returRefundMethod = ref('cash');
const returNotes = ref('');
const processingRetur = ref(false);

const getMaxReturQty = (item) => {
    // Max qty is always in base unit (PCS)
    return item.returnable_base || 0;
};

// Get nilai retur for an item (qty * harga_per_base)
const getNilaiRetur = (item) => {
    return (item.qty || 0) * (item.harga_per_base || 0);
};

const returSubtotal = computed(() => {
    return returItems.value.reduce((sum, i) => sum + getNilaiRetur(i), 0);
});

const returPembulatan = computed(() => {
    const rounded = roundSales(returSubtotal.value);
    return rounded - returSubtotal.value;
});

const returGrandTotal = computed(() => {
    return returSubtotal.value + returPembulatan.value;
});

const returHasItems = computed(() => {
    return returItems.value.some((i) => i.qty > 0);
});

// ==================== TRANSAKSI (GABUNGAN RIWAYAT + RETUR) ====================
const transaksiSessionType = ref('current'); // 'current' or 'previous'
const transaksiSearch = ref('');
const transaksiList = ref([]);
const loadingTransaksi = ref(false);
const transaksiRightPanel = ref('none'); // 'none', 'detail', 'retur'
const selectedTransaksi = ref(null);

let transaksiSearchDebounce = null;

const loadTransaksiList = async () => {
    loadingTransaksi.value = true;
    try {
        const params = {
            shift_id: cart.shiftId.value,
            terminal_id: cart.terminalId.value,
            session_type: transaksiSessionType.value,
            include_voided: true // Include voided transactions for history view
        };
        if (transaksiSearch.value?.trim()) {
            params.search = transaksiSearch.value.trim();
        }
        const res = await posApi.searchSalesForReturn(params);
        transaksiList.value = res.data.data?.sales || [];
    } catch (e) {
        if (e.response?.status !== 422) {
            // Ignore durasi_retur error
            notify.apiError(e, 'Gagal memuat transaksi');
        }
        transaksiList.value = [];
    } finally {
        loadingTransaksi.value = false;
    }
};

const switchTransaksiSession = (type) => {
    if (type === 'previous' && terminalData.value?.durasi_retur === 0) {
        notify.warn('Retur dari sesi sebelumnya tidak diizinkan untuk terminal ini');
        return;
    }
    transaksiSessionType.value = type;
    transaksiRightPanel.value = 'none';
    selectedTransaksi.value = null;
    loadTransaksiList();
};

// Watcher: Dynamic search untuk transaksi
watch(transaksiSearch, () => {
    if (transaksiSearchDebounce) clearTimeout(transaksiSearchDebounce);
    transaksiSearchDebounce = setTimeout(() => {
        loadTransaksiList();
    }, 300);
});

const openTransaksiDetail = async (sales) => {
    selectedTransaksi.value = sales;
    transaksiRightPanel.value = 'detail';
    // Load full detail for receipt
    loadingReturDetail.value = true;
    try {
        const res = await posApi.getSales(sales.ulid);
        returSalesDetail.value = res.data.data?.sales;
    } catch {
        notify.loadDetailError('transaksi');
    } finally {
        loadingReturDetail.value = false;
    }
};

const openTransaksiRetur = async (sales) => {
    if (!canRetur.value) {
        notify.warn('Anda tidak memiliki akses untuk retur');
        return;
    }
    if (sales.retur_status === 'full') {
        notify.warn('Transaksi sudah full retur');
        return;
    }
    selectedTransaksi.value = sales;
    transaksiRightPanel.value = 'retur';
    // Load detail for retur form
    loadingReturDetail.value = true;
    try {
        const res = await posApi.getSalesForReturn(sales.ulid);
        returSalesDetail.value = res.data.data?.sales;
        returItems.value = (res.data.data?.sales?.details || []).map((d) => ({
            sales_detail_id: d.id,
            product_id: d.product_id || d.product?.id,
            product: d.product,
            unit_beli: d.unit,
            qty_beli: d.qty,
            qty_base_beli: d.qty_base,
            total_pembelian: d.total_pembelian || 0,
            total_returned_base: d.total_returned_base || 0,
            returnable_base: d.returnable_base || 0,
            harga_per_base: d.harga_per_base || 0,
            qty: 0,
            // Serial: unit yang masih terjual (kandidat retur) + SN yang dipilih kasir
            is_serial: !!d.product?.is_serial,
            returnable_units: d.returnable_units || [],
            serial_unit_ids: []
        }));
        returRefundMethod.value = 'cash';
        returNotes.value = '';
    } catch {
        notify.loadDetailError('transaksi');
    } finally {
        loadingReturDetail.value = false;
    }
};

// Toggle pilih SN untuk diretur (serial) — qty mengikuti jumlah SN dipilih
const toggleReturUnit = (item, ulid) => {
    const idx = item.serial_unit_ids.indexOf(ulid);
    if (idx >= 0) item.serial_unit_ids.splice(idx, 1);
    else item.serial_unit_ids.push(ulid);
    item.qty = item.serial_unit_ids.length;
};

const processReturFromTransaksi = async () => {
    const itemsToReturn = returItems.value.filter((i) => i.qty > 0);
    if (itemsToReturn.length === 0) {
        notify.warn('Tidak ada item yang diretur');
        return;
    }
    processingRetur.value = true;
    try {
        const returRes = await posApi.processReturn({
            sales_id: returSalesDetail.value.id,
            terminal_id: cart.terminalId.value,
            shift_id: cart.shiftId.value,
            warehouse_id: cart.warehouseId.value,
            refund_method: returRefundMethod.value,
            notes: returNotes.value?.trim() || null,
            items: itemsToReturn.map((i) => ({
                sales_detail_id: i.sales_detail_id,
                product_id: i.product_id,
                qty: i.qty,
                harga_per_base: i.harga_per_base,
                serial_unit_ids: i.is_serial ? i.serial_unit_ids : null
            }))
        });
        notify.success('Retur berhasil diproses');
        // Reset and reload
        transaksiRightPanel.value = 'none';
        selectedTransaksi.value = null;
        returSalesDetail.value = null;
        returItems.value = [];
        loadTransaksiList();
        searchProducts(productSearch.value || '');
    } catch (e) {
        notify.apiError(e, 'Gagal memproses retur');
    } finally {
        processingRetur.value = false;
    }
};

// ==================== VOID ====================
const voidDialog = ref(false);
const voidSalesUlid = ref(null);
const voidReason = ref('');
const voidProcessing = ref(false);

const openVoidDialog = (salesUlid) => {
    voidSalesUlid.value = salesUlid;
    voidReason.value = '';
    voidDialog.value = true;
};

const processVoid = async () => {
    if (!voidReason.value?.trim()) {
        notify.warn('Alasan void wajib diisi');
        return;
    }
    voidProcessing.value = true;
    try {
        await posApi.voidSales(voidSalesUlid.value, { reason: voidReason.value.trim() });
        notify.success('Transaksi berhasil divoid');
        voidDialog.value = false;
        loadTransaksiList();
        // Refresh product list to update stock display
        searchProducts(productSearch.value || '');
    } catch (e) {
        notify.apiError(e, 'Gagal void transaksi');
    } finally {
        voidProcessing.value = false;
    }
};

// ==================== VIEW SALES DETAIL ====================
const salesDetailDialog = ref(false);
const salesDetailData = ref(null);
const loadingSalesDetail = ref(false);

// ==================== END SHIFT ====================
// Using useShiftReport composable
const { shiftReportDialog, shiftReportData, loadingShiftReport, loadShiftReport: baseLoadShiftReport, printShiftReport: browserPrintShiftReport, downloadShiftReportPdf, closeShiftReport } = useShiftReport();

// Override printShiftReport: use direct thermal print when available, fallback to browser
const printShiftReport = async () => {
    if (canDirectThermal() && shiftReportData.value) {
        await printAdapter.reconnect();
        const bytes = escpos.buildShiftReport(shiftReportData.value, printOpts.value);
        const result = await thermalPrint(bytes);
        if (result.success) return;
    }
    browserPrintShiftReport();
};

const endingShift = ref(false);
const shiftClosed = ref(false); // Track if shift has been closed (to show print buttons)

const openEndShift = async () => {
    // Warn if held transactions exist
    if (cart.hasHeldTransactions.value) {
        confirm.require({
            message: `Masih ada ${cart.heldCount.value} transaksi ditahan. Lanjutkan tutup shift?`,
            header: 'Peringatan',
            icon: 'pi pi-exclamation-triangle',
            acceptLabel: 'Lanjutkan',
            rejectLabel: 'Batal',
            accept: () => doLoadShiftReport()
        });
    } else {
        doLoadShiftReport();
    }
};

const doLoadShiftReport = async () => {
    if (!terminalData.value?.active_shift?.ulid) return;
    shiftClosed.value = false; // Reset state when opening
    // Reset reconcile input — kasir harus isi fresh tiap buka dialog
    reconcileSaldoFisik.value = null;
    reconcileNotes.value = '';
    await baseLoadShiftReport(terminalData.value.active_shift.ulid);
};

// Reconcile state — bound ke input uang fisik di dalam ShiftReportDialog (editable mode).
// Reset tiap kali buka dialog laporan shift (lihat showShiftReport fn).
const reconcileSaldoFisik = ref(null);
const reconcileNotes = ref('');

const endShift = async () => {
    // Validasi: uang fisik wajib diisi
    if (reconcileSaldoFisik.value === null || reconcileSaldoFisik.value === '') {
        notify.warn('Uang Fisik di Laci wajib diisi');
        return;
    }
    endingShift.value = true;
    try {
        const payload = {
            saldo_fisik: Number(reconcileSaldoFisik.value)
        };
        if (reconcileNotes.value) payload.closing_notes = reconcileNotes.value;

        await posTerminalsApi.endShift(terminalData.value.ulid, payload);
        notify.success('Shift berhasil ditutup');
        // Update shift data in-place with persisted values from payload
        if (shiftReportData.value?.shift) {
            shiftReportData.value.shift.ended_at = new Date().toISOString();
            shiftReportData.value.shift.saldo_fisik = payload.saldo_fisik;
            shiftReportData.value.shift.saldo_system = Number(shiftReportData.value.kas?.saldo || 0);
            shiftReportData.value.shift.selisih = payload.saldo_fisik - Number(shiftReportData.value.kas?.saldo || 0);
            shiftReportData.value.shift.closing_notes = payload.closing_notes ?? null;
        }
        shiftClosed.value = true; // Enable print buttons, don't redirect yet
    } catch (e) {
        notify.apiError(e, 'Gagal menutup shift');
    } finally {
        endingShift.value = false;
    }
};

const finishAndRedirect = () => {
    closeShiftReport();
    router.push({ name: 'dashboard' });
};

// ==================== KEYBOARD SHORTCUTS ====================
const productSearchRef = ref(null);

const focusProductSearch = () => {
    activeTab.value = 'kasir';
    nextTick(() => {
        const el = productSearchRef.value?.$el || productSearchRef.value;
        if (el?.tagName === 'INPUT') {
            el.focus();
        } else {
            el?.querySelector('input')?.focus();
        }
    });
};

const shortcutHelpDialog = ref(false);

const onKeydown = (e) => {
    // ── Dialog-specific Enter handlers ──
    // When a dialog is open, Enter triggers its primary action.
    // Check dialogs FIRST so F-keys don't fire underneath.
    if (e.key === 'Enter' && !e.ctrlKey && !e.altKey) {
        if (paymentDialog.value && canProcessPayment.value && !paymentProcessing.value) {
            e.preventDefault();
            processPayment();
            return;
        }
        if (receiptDialog.value) {
            e.preventDefault();
            if (isAfterCheckout.value) newTransaction();
            else receiptDialog.value = false;
            return;
        }
        if (discountDialog.value) {
            e.preventDefault();
            applyDiscount();
            return;
        }
        if (biayaDialog.value) {
            e.preventDefault();
            applyBiaya();
            return;
        }
        if (lineDiscountDialog.value) {
            e.preventDefault();
            applyLineDiscount();
            return;
        }
        if (voidDialog.value && voidReason.value?.trim() && !voidProcessing.value) {
            e.preventDefault();
            processVoid();
            return;
        }
    }

    // ── F1 = Focus product search ──
    if (e.key === 'F1') {
        e.preventDefault();
        focusProductSearch();
    }
    // ── F2 = Disc Nota (manual) ──
    if (e.key === 'F2') {
        e.preventDefault();
        if (activeTab.value === 'kasir' && cart.hasItems.value && !paymentDialog.value) {
            openDiscountDialog();
        }
    }
    // ── F4 = Biaya Tambahan ──
    if (e.key === 'F4') {
        e.preventDefault();
        if (activeTab.value === 'kasir' && cart.hasItems.value && !paymentDialog.value) {
            openBiayaDialog();
        }
    }
    // ── F8 = Transaksi Baru (saat receipt dialog open) ──
    if (e.key === 'F8') {
        e.preventDefault();
        if (receiptDialog.value) {
            if (isAfterCheckout.value) newTransaction();
            else receiptDialog.value = false;
        }
    }
    // ── F9 = Hold ──
    if (e.key === 'F9') {
        e.preventDefault();
        if (activeTab.value === 'kasir' && cart.hasItems.value) {
            onHold();
        }
    }
    // ── F11 = Fullscreen toggle ──
    if (e.key === 'F11') {
        e.preventDefault();
        toggleFullscreen();
    }
    // ── F12 = Bayar ──
    if (e.key === 'F12') {
        e.preventDefault();
        if (activeTab.value === 'kasir' && cart.hasItems.value) {
            openPaymentDialog();
        }
    }
    // ── Delete = Hapus semua item (with confirm) ──
    if (e.key === 'Delete' && !e.ctrlKey && !e.altKey) {
        if (activeTab.value === 'kasir' && cart.hasItems.value && !paymentDialog.value && !receiptDialog.value) {
            e.preventDefault();
            clearAll();
        }
    }
    // ── Alt+1/2/3/4 = Tab switching ──
    if (e.altKey && !e.ctrlKey) {
        const tabMap = { 1: 'kasir', 2: 'kas', 3: 'transaksi', 4: 'held' };
        if (tabMap[e.key]) {
            e.preventDefault();
            activeTab.value = tabMap[e.key];
        }
    }
    // ── Ctrl+/ or ? = Open shortcut help ──
    if ((e.ctrlKey && e.key === '/') || (e.shiftKey && e.key === '?')) {
        e.preventDefault();
        shortcutHelpDialog.value = true;
    }
};

// Warn kasir if they try to close/reload with items still in cart
const onBeforeUnload = (e) => {
    if (cart.hasItems.value) {
        e.preventDefault();
        e.returnValue = '';
    }
};

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('beforeunload', onBeforeUnload);
    updateClock();
    clockInterval = setInterval(updateClock, 1000);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('beforeunload', onBeforeUnload);
    clearInterval(clockInterval);
    stopPromoPolling();
    stopIdleTracking();
});

// ==================== CLEAR ALL ====================
const clearAll = () => {
    confirm.require({
        message: 'Hapus semua item di keranjang?',
        header: 'Konfirmasi',
        icon: 'pi pi-trash',
        acceptLabel: 'Hapus Semua',
        rejectLabel: 'Batal',
        acceptClass: 'p-button-danger',
        accept: () => {
            cart.clearCart();
            cart.clearHeaderDiscount();
        }
    });
};
</script>

<template>
    <!-- Loading state -->
    <div v-if="terminalLoading" class="flex items-center justify-center h-screen bg-surface-50 dark:bg-surface-900">
        <div class="text-center">
            <i class="pi pi-spin pi-spinner text-4xl text-primary mb-4"></i>
            <p class="text-surface-600 dark:text-surface-400">Memuat terminal...</p>
        </div>
    </div>

    <!-- Main POS Layout -->
    <div v-else class="flex flex-col h-screen bg-surface-50 dark:bg-surface-900">
        <!-- Top Bar -->
        <div class="flex items-center justify-between px-4 py-2 bg-surface-0 dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700 shrink-0">
            <div class="flex items-center gap-3">
                <Button icon="pi pi-arrow-left" text rounded size="small" @click="router.push({ name: 'dashboard' })" v-tooltip.bottom="'Kembali'" aria-label="Kembali ke dashboard" />
                <img :src="settingsStore.storeLogo || '/logo.svg'" alt="Logo" class="h-8" />
                <span class="font-bold text-lg text-primary">{{ settingsStore.storeName }}</span>
                <Tag severity="info" :value="`Terminal: ${terminalData?.kode_terminal || '-'}`" />
                <Tag severity="secondary" :value="`Gudang: ${terminalData?.warehouse?.nama_warehouse || '-'}`" />
                <Tag severity="contrast" :value="liveClock" icon="pi pi-clock" />
                <Tag
                    :severity="printAdapter.supported.value ? 'success' : 'warn'"
                    :value="printAdapter.printerLabel.value ? `Printer: ${printAdapter.printerLabel.value}` : printAdapter.supported.value ? 'Printer: Siap' : 'Printer: PDF'"
                    icon="pi pi-print"
                />
            </div>
            <div class="flex items-center gap-2">
                <Tag severity="secondary" :value="`Kasir: ${authStore.user?.name || '-'}`" icon="pi pi-user" />
                <Button :icon="isDarkTheme ? 'pi pi-moon' : 'pi pi-sun'" text rounded size="small" @click="toggleDarkMode" v-tooltip.bottom="'Mode Gelap/Terang'" aria-label="Mode Gelap/Terang" />
                <Button :icon="isFullscreen ? 'pi pi-window-minimize' : 'pi pi-window-maximize'" text rounded size="small" @click="toggleFullscreen" v-tooltip.bottom="'Fullscreen'" aria-label="Fullscreen" />
                <Button icon="pi pi-question-circle" text rounded size="small" v-tooltip.bottom="'Shortcut (F2)'" @click="shortcutHelpDialog = true" aria-label="Bantuan shortcut keyboard" />
                <Button icon="pi pi-lock" severity="warn" size="small" outlined v-tooltip.bottom="'Kunci Layar'" @click="lockScreen" :loading="locking" aria-label="Kunci Layar" />
                <Button label="Selesai Shift" icon="pi pi-sign-out" severity="danger" size="small" outlined @click="openEndShift" />
            </div>
        </div>

        <!-- Tab Bar -->
        <div class="flex items-center gap-1 px-4 py-2 bg-surface-0 dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700 shrink-0 overflow-x-auto">
            <Button
                v-for="tab in tabs"
                :key="tab.key"
                :label="tab.key === 'held' ? `Held: ${cart.heldCount.value}` : tab.label"
                :icon="tab.icon"
                :severity="activeTab === tab.key ? undefined : 'secondary'"
                :outlined="activeTab !== tab.key"
                size="small"
                @click="activeTab = tab.key"
            />
        </div>

        <!-- Content Area -->
        <div class="flex-1 overflow-hidden">
            <!-- ==================== TAB: KASIR ==================== -->
            <div v-show="activeTab === 'kasir'" class="flex h-full">
                <!-- Left Panel: Products -->
                <div class="w-1/2 border-r border-surface-200 dark:border-surface-700 flex flex-col p-4 overflow-hidden">
                    <!-- Search -->
                    <div class="mb-3 flex gap-2">
                        <IconField class="flex-1">
                            <InputIcon class="pi pi-search" />
                            <InputText
                                ref="productSearchRef"
                                v-model="productSearch"
                                placeholder="Cari produk / scan (F1)"
                                class="w-full"
                                :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                                @input="onProductSearch"
                                @keydown.enter="onProductSearchEnter"
                            />
                        </IconField>
                        <Button icon="pi pi-refresh" severity="secondary" outlined @click="searchProducts(productSearch || '')" :loading="loadingProducts" aria-label="Refresh produk" />
                    </div>

                    <!-- Product Grid -->
                    <div class="flex-1 overflow-y-auto">
                        <div v-if="loadingProducts" class="flex items-center justify-center py-8">
                            <i class="pi pi-spin pi-spinner text-2xl"></i>
                        </div>
                        <div v-else-if="products.length === 0 && productSearch" class="text-center py-8 text-surface-500">Produk tidak ditemukan</div>
                        <div v-else-if="products.length === 0" class="text-center py-8 text-surface-500">Tidak ada produk tersedia</div>
                        <div v-else class="grid grid-cols-2 lg:grid-cols-3 gap-2">
                            <div
                                v-for="product in products"
                                :key="product.id"
                                class="border border-surface-200 dark:border-surface-700 rounded-lg p-3 cursor-pointer hover:bg-primary/5 transition-colors"
                                :class="{ 'opacity-50': product.stok <= 0 && !negativeStockAllowed }"
                                @click="onProductClick(product)"
                            >
                                <div class="font-medium text-sm truncate" :title="product.nama_produk">{{ product.nama_produk }}</div>
                                <div class="text-xs text-surface-500 mt-1">{{ product.kode_produk }}</div>
                                <div class="flex items-center justify-between mt-2">
                                    <span class="text-sm font-semibold text-primary">{{ formatCurrency(getBasePrice(product)) }}/{{ getBaseUnit(product) }}</span>
                                    <Tag :severity="product.stok > 0 ? 'success' : 'danger'" :value="`Stok: ${formatQty(product.stok)} ${getBaseUnit(product)}`" class="text-xs" />
                                </div>
                                <div v-if="getProductUnitNames(product).length > 1" class="text-xs text-surface-500 mt-1">
                                    {{ getProductUnitNames(product).join(' · ') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Panel: Cart -->
                <div class="w-1/2 flex flex-col p-4 overflow-hidden">
                    <!-- Cart Header -->
                    <div class="flex items-center justify-between mb-3">
                        <span class="font-bold text-lg">KERANJANG</span>
                        <div class="flex items-center gap-2">
                            <AutoComplete
                                v-model="cart.customer.value"
                                :suggestions="customerOptions"
                                optionLabel="nama"
                                placeholder="Customer..."
                                :loading="loadingCustomers"
                                @complete="searchCustomers"
                                @item-select="onCustomerSelect"
                                @clear="onCustomerClear"
                                dropdown
                                class="w-48"
                                size="small"
                            />
                            <Button v-if="canAddCustomer" icon="pi pi-user-plus" severity="secondary" outlined size="small" @click="customerDialog = true" v-tooltip.top="'Tambah customer baru'" aria-label="Tambah customer baru" />
                        </div>

                        <!-- Tambah customer baru dari POS (reuse, DRY) -->
                        <CustomerFormDialog v-model:visible="customerDialog" @saved="onNewCustomerSaved" />
                    </div>

                    <!-- Cart Items -->
                    <div class="flex-1 overflow-y-auto mb-3">
                        <div v-if="!cart.hasItems.value" class="flex items-center justify-center h-full text-surface-400">
                            <div class="text-center">
                                <i class="pi pi-shopping-cart text-4xl mb-2"></i>
                                <p>Keranjang kosong</p>
                            </div>
                        </div>
                        <div v-else class="space-y-0">
                            <!-- Cart Header -->
                            <table class="w-full text-[11px]">
                                <colgroup>
                                    <col style="width: 16px" />
                                    <col />
                                    <col style="width: 80px" />
                                    <col style="width: 110px" />
                                    <col style="width: 110px" />
                                    <col style="width: 110px" />
                                    <col style="width: 24px" />
                                </colgroup>
                                <thead>
                                    <tr class="border-b border-surface-200 dark:border-surface-700 font-semibold text-surface-500">
                                        <th class="text-left py-1.5">#</th>
                                        <th class="text-left py-1.5">Produk</th>
                                        <th class="text-left py-1.5">Qty</th>
                                        <th class="text-left py-1.5">Harga</th>
                                        <th class="text-left py-1.5">Diskon</th>
                                        <th class="text-left py-1.5">Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="(item, idx) in cart.items.value" :key="item.id">
                                        <tr class="border-b border-surface-100 dark:border-surface-700">
                                            <td class="py-1 text-surface-500">{{ idx + 1 }}</td>
                                            <td class="py-1 pr-3">
                                                <div class="font-medium truncate" :title="`${item.product.nama_produk} (${item.unit}-${item.konversi})`">
                                                    {{ item.product.nama_produk }} <span class="text-surface-500 font-normal text-[10px]">({{ item.unit }}-{{ item.konversi }})</span>
                                                </div>
                                                <div v-if="item.promo_name" class="inline-flex items-center gap-1 mt-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded px-1.5 py-0.5 leading-tight">
                                                    <i class="pi pi-tag text-[9px]"></i>
                                                    <span class="text-[9px] font-medium truncate max-w-[120px]">{{ item.promo_name }}</span>
                                                </div>
                                                <!-- Serial: chip SN unit yang dijual (klik × untuk lepas) -->
                                                <div v-if="item.is_serial" class="flex flex-wrap gap-1 mt-0.5">
                                                    <span
                                                        v-for="u in item.serial_units"
                                                        :key="u.ulid"
                                                        class="inline-flex items-center gap-1 bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 rounded px-1.5 py-0.5 leading-tight font-mono text-[9px]"
                                                    >
                                                        {{ u.kode_internal || u.serial_number }}
                                                        <span v-if="u.kode_internal && u.serial_number" class="opacity-70">· SN {{ u.serial_number }}</span>
                                                        <span v-if="u.grade" class="opacity-70">· {{ u.grade }}</span>
                                                        <span v-if="u.battery_health != null" class="opacity-70">· 🔋{{ u.battery_health }}%</span>
                                                        <i class="pi pi-times cursor-pointer text-red-500 text-[8px]" @click="cart.removeSerialUnit(item.id, u.ulid)" v-tooltip.top="'Lepas SN'"></i>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="py-1">
                                                <!-- Serial: qty mengikuti jumlah SN (read-only) -->
                                                <div v-if="item.is_serial" class="text-center text-xs font-semibold" v-tooltip.top="'Qty = jumlah SN. Scan/lepas SN untuk ubah.'">{{ item.qty }}</div>
                                                <div v-else class="flex items-center gap-0">
                                                    <Button icon="pi pi-minus" text rounded size="small" class="!w-5 !h-5" @click="cart.updateQty(item.id, item.qty - 1)" />
                                                    <InputNumber
                                                        v-select-on-focus
                                                        :modelValue="item.qty"
                                                        @update:modelValue="(val) => cart.updateQty(item.id, val)"
                                                        :min="1"
                                                        :max="cart.getMaxQty(item)"
                                                        :locale="getLocale"
                                                        :minFractionDigits="getQtyMinFractionDigits"
                                                        :maxFractionDigits="getQtyMaxFractionDigits"
                                                        size="small"
                                                        inputClass="w-7 text-center text-xs !py-0.5 !px-0"
                                                    />
                                                    <Button icon="pi pi-plus" text rounded size="small" class="!w-5 !h-5" @click="cart.updateQty(item.id, item.qty + 1)" :disabled="cart.getMaxQty(item) !== null && item.qty >= cart.getMaxQty(item)" />
                                                </div>
                                            </td>
                                            <td class="py-1 whitespace-nowrap">{{ formatCurrency(item.harga_satuan) }}</td>
                                            <td class="py-1 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <span v-if="item.diskon_nominal > 0" class="text-red-500">-{{ formatCurrency(item.diskon_nominal) }}</span>
                                                    <span v-else class="text-surface-400">-</span>
                                                    <Button v-if="canDiscount" icon="pi pi-tag" text rounded size="small" class="!w-4 !h-4 ml-0.5" @click="openLineDiscount(item)" v-tooltip.top="'Diskon Manual'" aria-label="Beri diskon item" />
                                                    <Button
                                                        v-if="canDiscount && hasAnyDiscount(item)"
                                                        icon="pi pi-times"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        class="!w-4 !h-4"
                                                        @click="cart.clearLineDiscountAll(item.id)"
                                                        v-tooltip.top="'Hapus Semua Diskon Item'"
                                                        aria-label="Hapus semua diskon item"
                                                    />
                                                    <Button
                                                        v-if="canDiscount"
                                                        icon="pi pi-refresh"
                                                        text
                                                        rounded
                                                        size="small"
                                                        class="!w-4 !h-4"
                                                        @click="cart.regenerateLineDiscount(item.id)"
                                                        v-tooltip.top="'Regenerate Diskon Otomatis'"
                                                        aria-label="Regenerate diskon item"
                                                    />
                                                </div>
                                            </td>
                                            <td class="py-1 font-semibold whitespace-nowrap">{{ formatCurrency(item.jumlah) }}</td>
                                            <td class="py-1">
                                                <Button icon="pi pi-trash" text rounded severity="danger" size="small" class="!w-4 !h-4" @click="cart.removeItem(item.id)" />
                                            </td>
                                        </tr>
                                        <tr v-if="hasAnyDiscount(item)">
                                            <td></td>
                                            <td colspan="6" class="pb-1 text-[10px] text-surface-500">
                                                {{ getDiscountBreakdown(item).join(' + ') }} = <span class="text-red-500 font-medium">-{{ formatCurrency(item.diskon_nominal) }}</span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div class="border-t border-surface-200 dark:border-surface-700 pt-3 space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Subtotal</span>
                            <span class="font-medium">{{ formatCurrency(cart.subtotal.value) }}</span>
                        </div>
                        <!-- Disc Nota 1 (auto — dari tipe customer) -->
                        <div v-if="cart.totals.value?.diskon_nota_1_hasil > 0" class="flex justify-between text-red-500">
                            <span>
                                Disc Nota 1 {{ cart.discounts.value[0].tipe === 'percent' ? `(${cart.discounts.value[0].nilai}%)` : '' }}
                                <Button icon="pi pi-times" text rounded severity="danger" class="!w-4 !h-4 !p-0 ml-1" @click="cart.clearNotaSlot(1)" v-tooltip.top="'Hapus Disc Nota 1 (override)'" aria-label="Hapus disc nota 1" />
                            </span>
                            <span>-{{ formatCurrency(cart.totals.value.diskon_nota_1_hasil) }}</span>
                        </div>
                        <!-- Disc Nota 2 (auto — dari kategori customer) -->
                        <div v-if="cart.totals.value?.diskon_nota_2_hasil > 0" class="flex justify-between text-red-500">
                            <span>
                                Disc Nota 2 {{ cart.discounts.value[1].tipe === 'percent' ? `(${cart.discounts.value[1].nilai}%)` : '' }}
                                <Button icon="pi pi-times" text rounded severity="danger" class="!w-4 !h-4 !p-0 ml-1" @click="cart.clearNotaSlot(2)" v-tooltip.top="'Hapus Disc Nota 2 (override)'" aria-label="Hapus disc nota 2" />
                            </span>
                            <span>-{{ formatCurrency(cart.totals.value.diskon_nota_2_hasil) }}</span>
                        </div>
                        <!-- Disc Nota 3 (manual) -->
                        <div v-if="cart.discounts.value[2].tipe !== 'none' && cart.discounts.value[2].nilai > 0" class="flex justify-between text-red-500">
                            <span>
                                Disc Nota 3 {{ cart.discounts.value[2].tipe === 'percent' ? `(${cart.discounts.value[2].nilai}%)` : '' }}
                                <Button icon="pi pi-times" text rounded severity="danger" class="!w-4 !h-4 !p-0 ml-1" @click="cart.setDiscount(3, 'none', 0)" />
                            </span>
                            <span>-{{ formatCurrency(cart.totals.value?.diskon_nota_3_hasil || 0) }}</span>
                        </div>
                        <!-- Total after discount -->
                        <div v-if="cart.totals.value?.total_diskon > 0" class="flex justify-between font-medium">
                            <span>Total</span>
                            <span>{{ formatCurrency(cart.totals.value.total_setelah_diskon) }}</span>
                        </div>
                        <!-- Biaya Kirim -->
                        <div v-if="cart.biayaKirim.value.tipe !== 'none' && cart.biayaKirim.value.nilai > 0" class="flex justify-between text-blue-600">
                            <span>
                                Biaya Kirim {{ cart.biayaKirim.value.tipe === 'percent' ? `(${cart.biayaKirim.value.nilai}%)` : '' }}
                                <Button icon="pi pi-times" text rounded severity="danger" class="!w-4 !h-4 !p-0 ml-1" @click="cart.setBiayaKirim('none', 0)" />
                            </span>
                            <span>+{{ formatCurrency(cart.totals.value?.biaya_kirim_hasil || 0) }}</span>
                        </div>
                        <!-- Biaya Lain-Lain -->
                        <div v-if="cart.biayaLain.value.tipe !== 'none' && cart.biayaLain.value.nilai > 0" class="flex justify-between text-blue-600">
                            <span>
                                Biaya Lain {{ cart.biayaLain.value.tipe === 'percent' ? `(${cart.biayaLain.value.nilai}%)` : '' }}
                                <Button icon="pi pi-times" text rounded severity="danger" class="!w-4 !h-4 !p-0 ml-1" @click="cart.setBiayaLain('none', 0)" />
                            </span>
                            <span>+{{ formatCurrency(cart.totals.value?.biaya_lain_hasil || 0) }}</span>
                        </div>
                        <!-- DPP -->
                        <div v-if="cart.totals.value" class="flex justify-between text-surface-500">
                            <span>DPP</span>
                            <span>{{ formatCurrency(cart.totals.value.dpp) }}</span>
                        </div>
                        <!-- Pajak -->
                        <div v-if="cart.totals.value" class="flex justify-between text-surface-500">
                            <span>{{ cart.totals.value.pajak_nama }} {{ cart.totals.value.pajak_persen }}%</span>
                            <span>{{ formatCurrency(cart.totals.value.pajak_nominal) }}</span>
                        </div>
                        <!-- Pembulatan -->
                        <div v-if="cart.totals.value?.pembulatan" class="flex justify-between text-surface-500">
                            <span>Pembulatan</span>
                            <span>{{ formatCurrency(cart.totals.value.pembulatan) }}</span>
                        </div>
                        <!-- Grand Total -->
                        <div class="flex justify-between font-bold text-lg border-t border-surface-300 dark:border-surface-600 pt-2 mt-2">
                            <span>GRAND TOTAL</span>
                            <span class="text-primary">{{ formatCurrency(cart.totals.value?.grand_total ?? cart.subtotal.value) }}</span>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex items-center gap-2 mt-3 shrink-0">
                        <Button label="Hold (F9)" icon="pi pi-pause" severity="secondary" outlined size="small" @click="onHold" :disabled="!cart.hasItems.value" />
                        <Button v-if="canDiscount" label="Disc Nota (F2)" icon="pi pi-percentage" severity="secondary" outlined size="small" @click="openDiscountDialog" :disabled="!cart.hasItems.value" />
                        <Button
                            v-if="canDiscount"
                            icon="pi pi-refresh"
                            severity="secondary"
                            outlined
                            size="small"
                            @click="cart.regenerateNotaDiscount"
                            :disabled="!cart.hasItems.value"
                            v-tooltip.top="'Regenerate Disc Nota dari Customer'"
                            aria-label="Regenerate disc nota"
                        />
                        <Button label="Biaya (F4)" icon="pi pi-plus-circle" severity="secondary" outlined size="small" @click="openBiayaDialog" :disabled="!cart.hasItems.value" />
                        <Button label="Hapus Semua (Del)" icon="pi pi-trash" severity="danger" outlined size="small" @click="clearAll" :disabled="!cart.hasItems.value" />
                        <Button label="BAYAR (F12)" icon="pi pi-money-bill" class="flex-1" @click="openPaymentDialog" :disabled="!cart.hasItems.value" />
                    </div>
                </div>
            </div>

            <!-- ==================== TAB: KAS ==================== -->
            <div v-show="activeTab === 'kas'" class="flex h-full">
                <!-- Left: Form -->
                <div class="w-1/2 border-r border-surface-200 dark:border-surface-700 flex flex-col p-4 overflow-hidden">
                    <span class="font-bold text-lg mb-4">TRANSAKSI KAS</span>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium mb-2">Tipe</label>
                            <div class="flex flex-col gap-2">
                                <div class="flex items-center gap-2">
                                    <RadioButton v-model="kasForm.tipe" inputId="kas_setor" value="setor_awal" :disabled="kasHasSetorAwal" />
                                    <label for="kas_setor" :class="{ 'text-surface-400': kasHasSetorAwal }">Setor Awal{{ kasHasSetorAwal ? ' (sudah ada)' : '' }}</label>
                                </div>
                                <div class="flex items-center gap-2">
                                    <RadioButton v-model="kasForm.tipe" inputId="kas_masuk" value="kas_masuk" />
                                    <label for="kas_masuk">Kas Masuk</label>
                                </div>
                                <div class="flex items-center gap-2">
                                    <RadioButton v-model="kasForm.tipe" inputId="kas_keluar" value="kas_keluar" />
                                    <label for="kas_keluar">Kas Keluar</label>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1">Nominal</label>
                            <InputNumber
                                v-select-on-focus
                                v-model="kasForm.nominal"
                                :min="0"
                                :locale="getLocale"
                                :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                :minFractionDigits="getCurrencyMinFractionDigits"
                                :maxFractionDigits="getCurrencyMaxFractionDigits"
                                class="w-full"
                                inputClass="w-full"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1">Keterangan <span v-if="kasForm.tipe === 'kas_keluar'" class="text-red-500">*</span></label>
                            <Textarea v-model="kasForm.keterangan" rows="3" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" placeholder="Keterangan transaksi kas..." />
                        </div>

                        <Button label="SIMPAN" icon="pi pi-save" class="w-full" @click="saveCashTransaction" :loading="kasSaving" />
                        <Button v-if="lastKasData" label="Print Ulang Kas Terakhir" icon="pi pi-print" severity="secondary" outlined size="small" class="w-full mt-2" @click="printLastKas" />
                    </div>
                </div>

                <!-- Right: History (Tunai + Non-Tunai) + Summary -->
                <div class="w-1/2 flex flex-col p-4 overflow-hidden">
                    <span class="font-bold text-lg mb-2">RIWAYAT TRANSAKSI SHIFT INI</span>

                    <div class="flex-1 overflow-y-auto mb-3 space-y-4">
                        <!-- TUNAI Section -->
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <i class="pi pi-wallet text-green-600"></i>
                                <span class="font-semibold text-green-700 dark:text-green-400">TUNAI</span>
                            </div>
                            <DataTable :value="kasTunai" :loading="loadingKas" stripedRows size="small" class="text-sm">
                                <Column header="#" style="width: 35px">
                                    <template #body="{ index }">{{ index + 1 }}</template>
                                </Column>
                                <Column header="Tipe" style="width: 90px">
                                    <template #body="{ data }">
                                        <Tag :severity="getTipeSeverity(data.tipe)" :value="getTipeLabel(data.tipe)" />
                                    </template>
                                </Column>
                                <Column header="Nominal" style="width: 110px; text-align: right">
                                    <template #body="{ data }">
                                        <span :class="data.tipe === 'kas_keluar' ? 'text-red-500' : 'text-green-600'"> {{ data.tipe === 'kas_keluar' ? '-' : '+' }}{{ formatCurrency(data.nominal) }} </span>
                                    </template>
                                </Column>
                                <Column header="Keterangan">
                                    <template #body="{ data }">{{ data.keterangan || '-' }}</template>
                                </Column>
                                <template #empty><span class="text-surface-400">Belum ada transaksi tunai</span></template>
                            </DataTable>
                            <div class="text-right text-sm font-semibold mt-1 text-green-700 dark:text-green-400">Subtotal Tunai: {{ formatCurrency(kasSubtotalTunai) }}</div>
                        </div>

                        <!-- NON-TUNAI Section -->
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <i class="pi pi-credit-card text-blue-600"></i>
                                <span class="font-semibold text-blue-700 dark:text-blue-400">NON-TUNAI</span>
                            </div>
                            <DataTable :value="kasNonTunai" :loading="loadingKas" stripedRows size="small" class="text-sm">
                                <Column header="#" style="width: 35px">
                                    <template #body="{ index }">{{ index + 1 }}</template>
                                </Column>
                                <Column header="Metode" style="width: 100px">
                                    <template #body="{ data }">
                                        <Tag severity="info" :value="data.metode" />
                                    </template>
                                </Column>
                                <Column header="Nominal" style="width: 110px; text-align: right">
                                    <template #body="{ data }">
                                        <span class="text-blue-600">{{ formatCurrency(data.nominal) }}</span>
                                    </template>
                                </Column>
                                <Column header="Keterangan">
                                    <template #body="{ data }">{{ data.keterangan || '-' }}</template>
                                </Column>
                                <template #empty><span class="text-surface-400">Belum ada transaksi non-tunai</span></template>
                            </DataTable>
                            <div class="text-right text-sm font-semibold mt-1 text-blue-700 dark:text-blue-400">Subtotal Non-Tunai: {{ formatCurrency(kasSubtotalNonTunai) }}</div>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div v-if="kasSummary" class="border-t-2 border-surface-300 dark:border-surface-600 pt-3 space-y-1 text-sm">
                        <div class="font-bold mb-2">RINGKASAN KAS (Uang Fisik di Laci):</div>
                        <div class="flex justify-between">
                            <span>Setor Awal</span><span>{{ formatCurrency(kasSummary.setor_awal || 0) }}</span>
                        </div>
                        <div class="flex justify-between text-green-600">
                            <span>Penjualan Tunai</span><span>+{{ formatCurrency(kasSummary.penjualan_tunai || 0) }}</span>
                        </div>
                        <div class="flex justify-between text-green-600">
                            <span>Kas Masuk</span><span>+{{ formatCurrency(kasSummary.kas_masuk || 0) }}</span>
                        </div>
                        <div class="flex justify-between text-red-500">
                            <span>Kas Keluar</span><span>-{{ formatCurrency(kasSummary.kas_keluar || 0) }}</span>
                        </div>
                        <div class="flex justify-between text-red-500">
                            <span>Refund Retur (Cash)</span><span>-{{ formatCurrency(kasSummary.refund_tunai || 0) }}</span>
                        </div>
                        <div class="flex justify-between font-bold border-t border-surface-300 dark:border-surface-600 pt-2 mt-2">
                            <span>SALDO KAS</span><span class="text-primary text-lg">{{ formatCurrency(kasSummary.saldo || 0) }}</span>
                        </div>
                        <div class="flex justify-between text-surface-500 mt-2 pt-2 border-t border-surface-200 dark:border-surface-700">
                            <span>Total Non-Tunai</span><span class="text-blue-600">{{ formatCurrency(kasSummary.penjualan_non_tunai || 0) }}</span>
                        </div>
                        <div class="flex justify-between font-semibold">
                            <span>Total Penjualan Shift</span><span>{{ formatCurrency(kasSummary.total_penjualan || 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== TAB: TRANSAKSI ==================== -->
            <div v-show="activeTab === 'transaksi'" class="flex h-full">
                <!-- Left Panel: List Transaksi -->
                <div class="w-1/2 border-r border-surface-200 dark:border-surface-700 flex flex-col p-4 overflow-hidden">
                    <!-- Header with filter buttons -->
                    <div class="flex items-center justify-between mb-3">
                        <span class="font-bold text-lg">TRANSAKSI</span>
                        <div class="flex gap-1">
                            <Button label="Shift Ini" size="small" :outlined="transaksiSessionType !== 'current'" @click="switchTransaksiSession('current')" />
                            <Button label="Sebelumnya" size="small" :outlined="transaksiSessionType !== 'previous'" :disabled="terminalData?.durasi_retur === 0" @click="switchTransaksiSession('previous')" />
                        </div>
                    </div>

                    <!-- Info durasi retur -->
                    <div v-if="transaksiSessionType === 'previous' && terminalData?.durasi_retur > 0" class="mb-2 text-xs text-surface-500">
                        <i class="pi pi-info-circle mr-1"></i>Hanya transaksi dalam {{ terminalData.durasi_retur }} hari terakhir
                    </div>

                    <!-- Search -->
                    <div class="mb-3">
                        <IconField>
                            <InputIcon class="pi pi-search" />
                            <InputText v-model="transaksiSearch" placeholder="Cari no. invoice..." class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                        </IconField>
                    </div>

                    <!-- List -->
                    <div class="flex-1 overflow-y-auto">
                        <div v-if="loadingTransaksi" class="text-center py-8"><i class="pi pi-spin pi-spinner text-2xl"></i></div>
                        <div v-else-if="transaksiList.length === 0" class="text-center py-8 text-surface-500">Tidak ada transaksi</div>
                        <div v-else class="space-y-2">
                            <div v-for="s in transaksiList" :key="s.ulid" class="border border-surface-200 dark:border-surface-700 rounded-lg p-3 transition-colors" :class="{ 'border-primary bg-primary/5': selectedTransaksi?.ulid === s.ulid }">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-medium">{{ s.nomor_dokumen }}</span>
                                    <div class="flex items-center gap-1">
                                        <span v-if="s.status === 'voided'" class="text-xs px-2 py-0.5 rounded-full bg-gray-500 text-white">Void</span>
                                        <span v-else-if="s.retur_status === 'full'" class="text-xs px-2 py-0.5 rounded-full bg-red-500 text-white">Full Retur</span>
                                        <span v-else-if="s.retur_status === 'partial'" class="text-xs px-2 py-0.5 rounded-full bg-orange-500 text-white">Sebagian</span>
                                    </div>
                                </div>
                                <div class="flex justify-between text-sm text-surface-500">
                                    <span>{{ formatDateTime(s.tanggal) }}</span>
                                    <span>{{ formatCurrency(s.grand_total) }}</span>
                                </div>
                                <div class="flex justify-between text-sm text-surface-500">
                                    <span>{{ s.customer?.nama || 'Walk-in' }}</span>
                                    <span v-if="s.total_nominal_retur > 0" class="text-red-500">Retur: {{ formatCurrency(s.total_nominal_retur) }}</span>
                                </div>
                                <div v-if="transaksiSessionType === 'previous'" class="text-xs text-surface-400">Kasir: {{ s.shift?.user?.name || '-' }}</div>

                                <!-- Action buttons -->
                                <div class="flex items-center gap-1 mt-2 pt-2 border-t border-surface-100 dark:border-surface-700">
                                    <Button icon="pi pi-eye" text rounded size="small" @click="openTransaksiDetail(s)" v-tooltip.top="'Detail'" aria-label="Lihat detail transaksi" />
                                    <Button icon="pi pi-print" text rounded size="small" @click="showReceipt(s.ulid)" v-tooltip.top="'Cetak Struk'" aria-label="Cetak struk" />
                                    <a :href="getReceiptUrl(s.ulid)" target="_blank" v-tooltip.top="'Struk Online'" aria-label="Buka struk online">
                                        <Button icon="pi pi-link" text rounded size="small" />
                                    </a>
                                    <Button
                                        v-if="canVoid && s.status === 'completed' && s.retur_status === 'none'"
                                        icon="pi pi-trash"
                                        text
                                        rounded
                                        severity="danger"
                                        size="small"
                                        @click="openVoidDialog(s.ulid)"
                                        v-tooltip.top="'Void'"
                                        aria-label="Void transaksi"
                                    />
                                    <Button
                                        v-if="canRetur && s.status === 'completed' && s.retur_status !== 'full'"
                                        icon="pi pi-replay"
                                        text
                                        rounded
                                        severity="warn"
                                        size="small"
                                        @click="openTransaksiRetur(s)"
                                        v-tooltip.top="'Retur'"
                                        aria-label="Retur transaksi"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Panel: Detail or Retur Form -->
                <div class="w-1/2 flex flex-col p-4 overflow-hidden">
                    <!-- No selection -->
                    <div v-if="transaksiRightPanel === 'none'" class="flex-1 flex items-center justify-center text-surface-400">
                        <div class="text-center">
                            <i class="pi pi-inbox text-4xl mb-2"></i>
                            <p>Pilih transaksi di sebelah kiri</p>
                        </div>
                    </div>

                    <!-- Loading -->
                    <div v-else-if="loadingReturDetail" class="flex-1 flex items-center justify-center">
                        <i class="pi pi-spin pi-spinner text-2xl"></i>
                    </div>

                    <!-- Detail View -->
                    <template v-else-if="transaksiRightPanel === 'detail' && returSalesDetail">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-bold text-lg">DETAIL TRANSAKSI</span>
                            <Button
                                icon="pi pi-times"
                                text
                                rounded
                                size="small"
                                @click="
                                    transaksiRightPanel = 'none';
                                    selectedTransaksi = null;
                                "
                            />
                        </div>
                        <div class="text-sm space-y-1 mb-3">
                            <div>
                                Invoice: <strong>{{ returSalesDetail.nomor_dokumen }}</strong>
                            </div>
                            <div>Customer: {{ returSalesDetail.customer?.nama || 'Walk-in' }}</div>
                            <div>Tanggal: {{ formatDateTime(returSalesDetail.tanggal) }}</div>
                            <div>
                                Total: <strong>{{ formatCurrency(returSalesDetail.grand_total) }}</strong>
                            </div>
                        </div>
                        <div class="flex-1 overflow-y-auto mb-3">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-surface-200 dark:border-surface-700">
                                        <th class="text-left py-1">Produk</th>
                                        <th class="text-right py-1">Qty</th>
                                        <th class="text-right py-1">Harga</th>
                                        <th class="text-right py-1">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="d in returSalesDetail.details" :key="d.id" class="border-b border-surface-100 dark:border-surface-700">
                                        <td class="py-2">
                                            <div class="font-medium">{{ d.product?.nama_produk }}</div>
                                            <div class="text-xs text-surface-500">{{ d.product?.kode_produk }}</div>
                                            <div v-if="d.serial_units?.length" class="mt-0.5 space-y-0.5">
                                                <div v-for="(u, i) in d.serial_units" :key="i" class="text-[11px] text-surface-500 font-mono">{{ serialLineText(u) }}</div>
                                            </div>
                                        </td>
                                        <td class="py-2 text-right whitespace-nowrap">{{ formatQty(d.qty) }} {{ d.unit }}</td>
                                        <td class="py-2 text-right whitespace-nowrap">{{ formatCurrency(d.harga_satuan) }}</td>
                                        <td class="py-2 text-right whitespace-nowrap">{{ formatCurrency(d.jumlah) }}</td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- Return History -->
                            <div v-if="returSalesDetail.returns?.length > 0" class="mt-4 border-t border-surface-200 dark:border-surface-700 pt-3">
                                <div class="font-medium mb-2 flex items-center gap-2 text-sm">
                                    <i class="pi pi-replay text-orange-500"></i>
                                    Riwayat Retur ({{ returSalesDetail.returns.length }})
                                </div>
                                <div v-for="ret in returSalesDetail.returns" :key="ret.id" class="mb-2 p-2 bg-orange-50 dark:bg-orange-950/20 rounded text-xs">
                                    <div class="flex justify-between mb-1">
                                        <span class="font-medium">{{ ret.nomor_dokumen }}</span>
                                        <Tag severity="success" value="Tunai" size="small" />
                                    </div>
                                    <div class="text-surface-500 mb-1">{{ formatDateTime(ret.tanggal) }} oleh {{ ret.created_by?.name || '-' }}</div>
                                    <div class="space-y-0.5">
                                        <div v-for="d in ret.details" :key="d.id">
                                            <div class="flex justify-between">
                                                <span>{{ d.product?.nama_produk }} x {{ formatQty(d.qty) }}</span>
                                                <span class="text-orange-600">@ {{ formatCurrency(d.harga_satuan) }}</span>
                                            </div>
                                            <div v-if="d.serial_units?.length" class="pl-2 text-[10px] text-surface-500 font-mono">
                                                <div v-for="(u, i) in d.serial_units" :key="i">{{ serialLineText(u) }}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-if="Number(ret.pembulatan)" class="flex justify-between mt-1">
                                        <span>Pembulatan</span>
                                        <span class="text-orange-600">{{ formatCurrency(ret.pembulatan) }}</span>
                                    </div>
                                    <div class="flex justify-between font-medium mt-1 pt-1 border-t border-orange-200 dark:border-orange-800">
                                        <span>Total Retur</span>
                                        <span class="text-orange-600">{{ formatCurrency(ret.grand_total) }}</span>
                                    </div>
                                </div>

                                <!-- Ringkasan Retur -->
                                <div class="mt-2 p-2 bg-surface-100 dark:bg-surface-800 rounded text-xs">
                                    <div class="font-semibold mb-1">RINGKASAN</div>
                                    <div class="flex justify-between">
                                        <span>Pembayaran Asli</span><span>{{ formatCurrency(returSalesDetail.grand_total) }}</span>
                                    </div>
                                    <div v-if="Number(returSalesDetail.biaya_kirim_hasil) > 0 || Number(returSalesDetail.biaya_lain_hasil) > 0" class="mt-1">
                                        <div class="text-surface-500">Tidak Termasuk Retur:</div>
                                        <div v-if="Number(returSalesDetail.biaya_kirim_hasil) > 0" class="flex justify-between pl-2">
                                            <span>Biaya Kirim</span><span>{{ formatCurrency(returSalesDetail.biaya_kirim_hasil) }}</span>
                                        </div>
                                        <div v-if="Number(returSalesDetail.biaya_lain_hasil) > 0" class="flex justify-between pl-2">
                                            <span>Biaya Lain</span><span>{{ formatCurrency(returSalesDetail.biaya_lain_hasil) }}</span>
                                        </div>
                                    </div>
                                    <div class="flex justify-between mt-1">
                                        <span>Total Retur (Tunai)</span><span class="text-orange-600">{{ formatCurrency(returSalesDetail.returns.reduce((s, r) => s + Number(r.grand_total), 0)) }}</span>
                                    </div>
                                    <div class="flex justify-between font-bold mt-1 pt-1 border-t border-surface-300 dark:border-surface-600">
                                        <span>NILAI BERSIH</span>
                                        <span class="text-primary">{{ formatCurrency(Number(returSalesDetail.grand_total) - returSalesDetail.returns.reduce((s, r) => s + Number(r.grand_total), 0)) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button icon="pi pi-print" label="Cetak Struk" outlined @click="showReceipt(returSalesDetail.ulid)" />
                            <a :href="getReceiptUrl(returSalesDetail.ulid)" target="_blank">
                                <Button icon="pi pi-link" label="Struk Online" outlined />
                            </a>
                            <Button v-if="canRetur && returSalesDetail.status === 'completed' && selectedTransaksi?.retur_status !== 'full'" icon="pi pi-replay" label="Retur" severity="warn" @click="openTransaksiRetur(selectedTransaksi)" />
                        </div>
                    </template>

                    <!-- Retur Form -->
                    <template v-else-if="transaksiRightPanel === 'retur' && returSalesDetail">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-bold text-lg">FORM RETUR</span>
                            <Button
                                icon="pi pi-times"
                                text
                                rounded
                                size="small"
                                @click="
                                    transaksiRightPanel = 'none';
                                    selectedTransaksi = null;
                                "
                            />
                        </div>
                        <div class="text-sm space-y-1 mb-3">
                            <div>
                                Invoice: <strong>{{ returSalesDetail.nomor_dokumen }}</strong>
                            </div>
                            <div>Customer: {{ returSalesDetail.customer?.nama || 'Walk-in' }}</div>
                            <div>Tgl Transaksi: {{ formatDateTime(returSalesDetail.tanggal) }}</div>
                        </div>

                        <div class="flex-1 overflow-x-auto overflow-y-auto mb-3">
                            <table class="w-full text-sm min-w-[700px]">
                                <thead>
                                    <tr class="border-b border-surface-200 dark:border-surface-700">
                                        <th class="text-left py-1">Produk</th>
                                        <th class="text-right py-1">Beli</th>
                                        <th class="text-right py-1">Total Beli</th>
                                        <th class="text-right py-1">Sudah Retur</th>
                                        <th class="text-right py-1">Max</th>
                                        <th class="text-center py-1">Qty Retur</th>
                                        <th class="text-right py-1">Nilai Retur</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in returItems" :key="item.sales_detail_id" class="border-b border-surface-100 dark:border-surface-700">
                                        <td class="py-2">
                                            <div class="font-medium">{{ item.product?.nama_produk }}</div>
                                            <div class="text-xs text-surface-500">{{ item.product?.kode_produk }}</div>
                                            <!-- Serial: pilih SN yang dikembalikan -->
                                            <div v-if="item.is_serial" class="mt-1 space-y-1">
                                                <label v-for="u in item.returnable_units" :key="u.ulid" class="flex items-center gap-1.5 text-[11px] cursor-pointer">
                                                    <Checkbox :modelValue="item.serial_unit_ids.includes(u.ulid)" :binary="true" @update:modelValue="() => toggleReturUnit(item, u.ulid)" />
                                                    <span class="font-mono">{{ u.kode_internal || u.serial_number }}</span>
                                                    <span v-if="u.kode_internal && u.serial_number" class="text-surface-500 font-mono">· SN {{ u.serial_number }}</span>
                                                    <span v-if="u.grade" class="text-surface-500">· {{ u.grade }}</span>
                                                    <span v-if="u.battery_health != null" class="text-surface-500">· 🔋{{ u.battery_health }}%</span>
                                                </label>
                                                <div v-if="!item.returnable_units.length" class="text-[11px] text-surface-400">Tidak ada unit yang bisa diretur.</div>
                                            </div>
                                        </td>
                                        <td class="py-2 text-right whitespace-nowrap">{{ formatQty(item.qty_beli) }} {{ item.unit_beli }}</td>
                                        <td class="py-2 text-right whitespace-nowrap">{{ formatCurrency(item.total_pembelian) }}</td>
                                        <td class="py-2 text-right whitespace-nowrap">{{ formatQty(item.total_returned_base) }} PCS</td>
                                        <td class="py-2 text-right whitespace-nowrap">{{ formatQty(getMaxReturQty(item)) }} PCS</td>
                                        <td class="py-2 text-center">
                                            <div v-if="item.is_serial" class="font-semibold" v-tooltip.top="'Qty = jumlah SN dipilih'">{{ item.qty }}</div>
                                            <InputNumber
                                                v-else
                                                v-select-on-focus
                                                v-model="item.qty"
                                                :min="0"
                                                :max="getMaxReturQty(item)"
                                                :locale="getLocale"
                                                :minFractionDigits="getQtyMinFractionDigits"
                                                :maxFractionDigits="getQtyMaxFractionDigits"
                                                size="small"
                                                inputClass="w-20 text-center"
                                            />
                                        </td>
                                        <td class="py-2 text-right whitespace-nowrap font-medium">{{ formatCurrency(getNilaiRetur(item)) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="border-t border-surface-200 dark:border-surface-700 pt-3 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span>Total Nilai Retur</span><span>{{ formatCurrency(returSubtotal) }}</span>
                            </div>
                            <div class="flex justify-between" v-if="returPembulatan !== 0">
                                <span>Pembulatan</span><span>{{ formatCurrency(returPembulatan) }}</span>
                            </div>
                            <div class="flex justify-between font-bold text-base">
                                <span>Grand Total</span><span>{{ formatCurrency(returGrandTotal) }}</span>
                            </div>

                            <div class="flex items-center gap-2 text-surface-500">
                                <i class="pi pi-money-bill"></i>
                                <span>Refund akan dibayar tunai</span>
                            </div>

                            <div>
                                <label class="block text-sm font-medium mb-1">Catatan</label>
                                <Textarea v-model="returNotes" rows="2" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                            </div>

                            <Button label="PROSES RETUR" icon="pi pi-check" class="w-full" @click="processReturFromTransaksi" :loading="processingRetur" :disabled="!returHasItems" />
                        </div>
                    </template>
                </div>
            </div>

            <!-- ==================== TAB: HELD ==================== -->
            <div v-show="activeTab === 'held'" class="flex flex-col h-full p-4 overflow-hidden">
                <div class="flex items-center justify-between mb-3">
                    <span class="font-bold text-lg">TRANSAKSI DITAHAN ({{ cart.heldCount.value }})</span>
                </div>

                <div class="flex-1 overflow-y-auto">
                    <div v-if="cart.heldCount.value === 0" class="flex items-center justify-center h-full text-surface-400">
                        <div class="text-center">
                            <i class="pi pi-pause text-4xl mb-2"></i>
                            <p>Tidak ada transaksi ditahan</p>
                        </div>
                    </div>
                    <div v-else class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                        <div v-for="held in cart.getHeldTransactions()" :key="held._holdIndex" class="border border-surface-200 dark:border-surface-700 rounded-lg p-4">
                            <div class="font-bold mb-1">Hold #{{ held._holdIndex + 1 }}</div>
                            <div class="text-sm text-surface-500">Customer: {{ held.customer?.nama || 'Walk-in' }}</div>
                            <div class="text-sm text-surface-500">{{ held.item_count }} item — {{ formatCurrency(held.total) }}</div>
                            <div class="text-xs text-surface-400 mt-1">Ditahan: {{ formatDateTime(held.held_at) }}</div>
                            <div class="flex gap-2 mt-3">
                                <Button label="Lanjutkan" icon="pi pi-play" size="small" @click="onResume(held._holdIndex)" />
                                <Button label="Hapus" icon="pi pi-trash" severity="danger" outlined size="small" @click="onDeleteHold(held._holdIndex)" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-sm text-surface-400 mt-2">Maks {{ cart.HOLD_MAX }} transaksi ditahan per terminal</div>
            </div>
        </div>
    </div>

    <!-- ==================== DIALOGS ==================== -->
    <ConfirmDialog />

    <!-- Setor Awal Dialog (blocking) -->
    <Dialog v-model:visible="setorAwalDialog" header="Setor Awal" modal :closable="false" :style="{ width: '400px' }">
        <div class="space-y-4">
            <div class="text-center mb-4">
                <i class="pi pi-wallet text-4xl text-primary mb-2"></i>
                <p class="text-surface-600 dark:text-surface-400">Masukkan jumlah kas awal untuk memulai shift</p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Nominal Setor Awal</label>
                <InputNumber
                    v-select-on-focus
                    v-model="setorAwalNominal"
                    :min="0"
                    :locale="getLocale"
                    :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                    :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                    :minFractionDigits="getCurrencyMinFractionDigits"
                    :maxFractionDigits="getCurrencyMaxFractionDigits"
                    class="w-full"
                    inputClass="w-full text-center text-xl font-bold"
                    autofocus
                />
            </div>
        </div>
        <template #footer>
            <Button label="Simpan & Mulai" icon="pi pi-check" class="w-full" @click="saveSetorAwal" :loading="savingSetorAwal" />
        </template>
    </Dialog>

    <!-- Shift Force-Closed by Admin (blocking — zombie detection) -->
    <Dialog v-model:visible="shiftKilledDialog" header="Shift Ditutup" modal :closable="false" :style="{ width: '400px' }">
        <div class="text-center space-y-4 py-4">
            <i class="pi pi-exclamation-triangle text-5xl text-orange-500"></i>
            <p class="text-lg font-semibold">Shift Anda telah ditutup oleh administrator.</p>
            <p class="text-sm text-surface-500">Semua transaksi tidak dapat diproses. Silakan kembali ke dashboard.</p>
        </div>
        <template #footer>
            <Button label="Kembali ke Dashboard" icon="pi pi-arrow-left" class="w-full" @click="router.push({ name: 'dashboard' })" />
        </template>
    </Dialog>

    <!-- Session Guard Dialog (shift overtime + token expiring) -->
    <Dialog v-model:visible="sessionGuard.showGuardDialog.value" header="Konfirmasi Shift" modal :closable="false" :style="{ width: '420px' }">
        <div class="text-center space-y-3 py-4">
            <i class="pi pi-clock text-5xl text-orange-500"></i>
            <p v-if="sessionGuard.shiftOvertime.value" class="text-lg font-semibold">Shift sudah aktif {{ Math.floor(sessionGuard.shiftDurationHours.value) }} jam.</p>
            <p v-if="sessionGuard.sessionExpiring.value" class="text-base text-surface-500">Sesi akan berakhir dalam {{ sessionGuard.minutesUntilExpiry.value }} menit.</p>
            <p v-if="!sessionGuard.sessionExpiring.value && sessionGuard.shiftOvertime.value" class="text-sm text-surface-400">Apakah Anda ingin melanjutkan shift?</p>
        </div>
        <template #footer>
            <Button
                :label="sessionGuard.sessionExpiring.value ? 'Lanjutkan & Perpanjang Sesi' : 'Lanjutkan Shift'"
                icon="pi pi-refresh"
                class="flex-1"
                :loading="sessionGuard.refreshing.value"
                @click="sessionGuard.sessionExpiring.value ? sessionGuard.refresh() : sessionGuard.dismiss()"
            />
            <Button
                label="Tutup Shift"
                icon="pi pi-lock"
                severity="warn"
                @click="
                    sessionGuard.dismiss();
                    showShiftReport();
                "
            />
        </template>
    </Dialog>

    <!-- Unit Selection Dialog -->
    <Dialog v-model:visible="unitSelectDialog" header="Pilih Satuan" modal :style="{ width: '450px' }">
        <div v-if="unitSelectProduct" class="space-y-3">
            <div class="font-medium text-lg">{{ unitSelectProduct.nama_produk }}</div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-surface-500">{{ unitSelectProduct.kode_produk }}</span>
                <Tag :severity="unitSelectProduct.stok > 0 ? 'success' : 'danger'" :value="`Stok: ${formatQty(unitSelectProduct.stok)} ${getBaseUnit(unitSelectProduct)}`" class="text-xs" />
            </div>
            <div class="mt-3 space-y-2">
                <div v-for="u in unitSelectOptions" :key="u.index" class="p-3 border border-surface-200 dark:border-surface-700 rounded-lg cursor-pointer hover:bg-primary/10 hover:border-primary transition-colors" @click="selectUnit(u.index)">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="font-semibold">{{ u.unit }}</div>
                            <div class="text-xs text-surface-500">1 {{ u.unit }} = {{ u.konversi }} {{ getBaseUnit(unitSelectProduct) }}</div>
                            <div class="text-xs text-surface-500">Est. stok: {{ Math.floor((unitSelectProduct.stok || 0) / u.konversi) }} {{ u.unit }}</div>
                        </div>
                        <div class="font-bold text-primary">{{ formatCurrency(u.harga) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </Dialog>

    <!-- Serial Unit Card (hasil scan SN) -->
    <Dialog v-model:visible="serialCardDialog" header="Unit Serial" modal :style="{ width: '430px' }">
        <div v-if="serialCard" class="space-y-3">
            <div v-if="!serialCard.sellable" class="flex items-center gap-2 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-300 rounded p-2 text-sm">
                <i class="pi pi-exclamation-triangle"></i> {{ serialCard.reason || 'Unit tidak bisa dijual.' }}
            </div>

            <div>
                <div class="font-semibold text-lg leading-tight">{{ serialCard.unit.product?.nama_produk }}</div>
                <div class="text-xs text-surface-500">{{ serialCard.unit.product?.kode_produk }}</div>
            </div>

            <div class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                <div v-if="serialCard.unit.kode_internal" class="col-span-2">
                    <span class="text-surface-500 text-xs">Kode Internal</span>
                    <div class="font-mono font-medium">{{ serialCard.unit.kode_internal }}</div>
                </div>
                <div class="col-span-2">
                    <span class="text-surface-500 text-xs">Nomor Seri / IMEI</span>
                    <div class="font-mono font-medium">{{ serialCard.unit.serial_number }}</div>
                </div>
                <div>
                    <span class="text-surface-500 text-xs">Status</span>
                    <div><Tag :severity="serialCard.unit.status === 'tersedia' ? 'success' : 'warn'" :value="serialCard.unit.status" /></div>
                </div>
                <div>
                    <span class="text-surface-500 text-xs">Gudang</span>
                    <div class="font-medium">{{ serialCard.unit.warehouse?.nama_warehouse || '-' }}</div>
                </div>
                <div v-if="serialCard.unit.grade">
                    <span class="text-surface-500 text-xs">Grade</span>
                    <div class="font-medium">{{ serialCard.unit.grade }}</div>
                </div>
                <div v-if="serialCard.unit.battery_health != null">
                    <span class="text-surface-500 text-xs">Baterai</span>
                    <div class="font-medium">
                        🔋 {{ serialCard.unit.battery_health }}%<span v-if="serialCard.unit.battery_condition"> · {{ serialCard.unit.battery_condition }}</span>
                    </div>
                </div>
                <div v-if="serialCard.unit.account_status" class="col-span-2">
                    <span class="text-surface-500 text-xs">Status Akun</span>
                    <div class="font-medium">{{ serialCard.unit.account_status }}</div>
                </div>
                <div v-if="serialCard.unit.catatan" class="col-span-2">
                    <span class="text-surface-500 text-xs">Catatan</span>
                    <div>{{ serialCard.unit.catatan }}</div>
                </div>
                <div class="col-span-2">
                    <span class="text-surface-500 text-xs">Harga Jual</span>
                    <div class="font-bold text-primary">{{ serialCard.unit.harga_jual != null ? formatCurrency(serialCard.unit.harga_jual) : '— (atur harga manual di keranjang)' }}</div>
                </div>
                <div v-if="serialCard.unit.cost_per_unit != null" class="col-span-2">
                    <span class="text-surface-500 text-xs">Modal/HPP</span>
                    <div>{{ formatCurrency(serialCard.unit.cost_per_unit) }}</div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <Button label="Tutup" severity="secondary" outlined @click="serialCardDialog = false" />
                <Button v-if="serialCard.sellable" label="Tambah ke Keranjang" icon="pi pi-cart-plus" @click="addScannedSerialToCart" autofocus />
            </div>
        </div>
    </Dialog>

    <!-- Pemilih Unit Serial (klik produk serial) -->
    <Dialog v-model:visible="snPickerDialog" modal :style="{ width: '540px' }" :header="`Pilih Unit — ${snPickerProduct?.nama_produk || ''}`">
        <div class="space-y-3">
            <IconField iconPosition="left">
                <InputIcon class="pi pi-search" />
                <InputText v-model="snPickerSearch" placeholder="Cari kode internal / nomor seri…" class="w-full" autofocus />
            </IconField>

            <div v-if="snPickerLoading" class="text-center text-surface-500 py-6"><i class="pi pi-spin pi-spinner mr-1"></i> Memuat unit…</div>
            <div v-else-if="!filteredPickerUnits.length" class="text-center text-surface-500 py-6">Tidak ada unit tersedia di gudang ini.</div>
            <div v-else class="max-h-80 overflow-auto divide-y divide-surface-100 dark:divide-surface-700">
                <div v-for="u in filteredPickerUnits" :key="u.ulid" class="flex items-center justify-between gap-2 py-2">
                    <div class="min-w-0">
                        <div class="font-mono font-medium text-sm">{{ u.kode_internal || u.serial_number }}</div>
                        <div class="text-xs text-surface-500 truncate">
                            <span class="font-mono">SN {{ u.serial_number }}</span>
                            <span v-if="u.grade"> · Grade {{ u.grade }}</span>
                            <span v-if="u.battery_health != null"> · 🔋 {{ u.battery_health }}%</span>
                            <span v-if="u.account_status"> · {{ u.account_status }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="font-semibold text-primary text-sm whitespace-nowrap">{{ u.harga_jual != null ? formatCurrency(u.harga_jual) : '—' }}</span>
                        <Button label="Tambah" icon="pi pi-plus" size="small" @click="pickSnUnit(u)" />
                    </div>
                </div>
            </div>
        </div>
        <template #footer>
            <Button label="Selesai" @click="snPickerDialog = false" />
        </template>
    </Dialog>

    <!-- Payment Dialog -->
    <Dialog v-model:visible="paymentDialog" modal :style="{ width: '700px' }" :closable="!paymentProcessing" :header="false" class="!p-0">
        <template #header>
            <span class="font-bold text-lg">PEMBAYARAN</span>
        </template>

        <div v-if="cart.totals.value" class="flex flex-col" style="max-height: calc(90vh - 140px)">
            <!-- Grand Total (full width, top) -->
            <div class="text-center py-3 bg-surface-50 dark:bg-surface-800 rounded-lg mx-1 mb-3 shrink-0">
                <div class="text-surface-500 text-sm">Grand Total</div>
                <div class="text-3xl font-bold text-primary">{{ formatCurrency(cart.totals.value.grand_total) }}</div>
            </div>

            <!-- 2-column layout -->
            <div class="flex gap-4 flex-1 min-h-0 mx-1">
                <!-- LEFT: Payment Method Cards -->
                <div class="w-48 shrink-0 overflow-y-auto">
                    <div class="text-xs font-medium text-surface-500 mb-2">Pilih Metode:</div>
                    <div class="grid grid-cols-2 gap-2">
                        <div
                            v-for="method in allowedPaymentMethods"
                            :key="method.id"
                            class="flex flex-col items-center justify-center h-20 rounded-lg border-2 cursor-pointer transition-all hover:shadow-md"
                            :class="paymentMethods.some((p) => p.metode_pembayaran_id === method.id) ? 'border-primary bg-primary/10' : 'border-surface-200 dark:border-surface-700 hover:border-primary/50'"
                            @click="togglePaymentLine(method)"
                        >
                            <img v-if="method.logo_url" :src="method.logo_url" :alt="method.nama_pembayaran" class="w-8 h-8 object-contain mb-1" />
                            <i v-else :class="getMethodIcon(method)" class="text-xl mb-1" :style="{ color: paymentMethods.some((p) => p.metode_pembayaran_id === method.id) ? 'var(--p-primary-color)' : undefined }"></i>
                            <span class="text-[10px] text-center leading-tight font-medium px-1">{{ method.nama_pembayaran }}</span>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Payment Lines Detail -->
                <div class="flex-1 overflow-y-auto border-l border-surface-200 dark:border-surface-700 pl-4">
                    <div class="text-xs font-medium text-surface-500 mb-2">Detail Pembayaran:</div>

                    <div v-if="paymentMethods.length > 0" class="space-y-3">
                        <div v-for="(pm, idx) in paymentMethods" :key="pm.metode_pembayaran_id" class="p-3 border border-surface-200 dark:border-surface-700 rounded-lg">
                            <!-- Header -->
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <img v-if="pm.method?.logo_url" :src="pm.method.logo_url" :alt="pm.method.nama_pembayaran" class="w-5 h-5 object-contain" />
                                    <i v-else :class="getMethodIcon(pm.method)" class="text-base"></i>
                                    <span class="font-bold text-sm">{{ pm.method?.nama_pembayaran }}</span>
                                </div>
                                <Button icon="pi pi-times" text rounded severity="danger" size="small" class="!w-5 !h-5" @click="removePaymentLine(idx)" />
                            </div>

                            <!-- Nominal -->
                            <div class="mb-2">
                                <InputNumber
                                    v-select-on-focus
                                    v-model="pm.nominal"
                                    :locale="getLocale"
                                    :prefix="currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                                    :suffix="currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                                    :minFractionDigits="getCurrencyMinFractionDigits"
                                    :maxFractionDigits="getCurrencyMaxFractionDigits"
                                    class="w-full"
                                    inputClass="w-full text-right font-bold text-lg"
                                />
                            </div>

                            <!-- Pay Recommendations (tunai only, per-line sisa) -->
                            <div v-if="pm.method?.metode === 'tunai' && getLineRecommendations(idx).length > 0" class="mb-2">
                                <div class="flex flex-wrap gap-1">
                                    <Button
                                        v-for="rec in getLineRecommendations(idx)"
                                        :key="rec"
                                        :label="rec === Number(cart.totals.value?.grand_total) ? 'Uang Pas' : formatCurrency(rec)"
                                        size="small"
                                        :severity="pm.nominal === rec ? undefined : 'secondary'"
                                        :outlined="pm.nominal !== rec"
                                        @click="setPayNominal(idx, rec)"
                                        class="text-xs"
                                    />
                                </div>
                            </div>

                            <!-- Reference (non-tunai only) -->
                            <div v-if="pm.method?.metode !== 'tunai'" class="mb-2">
                                <label class="block text-xs text-surface-500 mb-1">No. Referensi</label>
                                <InputText v-select-on-focus v-model="pm.reference" placeholder="No. Referensi / Approval Code" size="small" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
                            </div>

                            <!-- QR Code (non-tunai, if available) -->
                            <div v-if="pm.method?.metode !== 'tunai' && pm.method?.qr_code_url" class="mb-2 flex justify-center">
                                <img :src="pm.method.qr_code_url" alt="QR Code" class="w-36 h-36 object-contain border border-surface-200 dark:border-surface-700 rounded-lg p-2 bg-white" />
                            </div>

                            <!-- Fee -->
                            <div v-if="calculateFee(pm.nominal || 0, pm.method) > 0" class="text-xs text-surface-500">
                                Biaya: {{ formatCurrency(calculateFee(pm.nominal || 0, pm.method)) }}
                                <span v-if="pm.method?.biaya_tambahan_tipe === 'percent'">({{ pm.method.biaya_tambahan_nilai }}%)</span>
                            </div>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-else class="flex items-center justify-center h-32 text-surface-400 text-sm">
                        <div class="text-center">
                            <i class="pi pi-arrow-left text-2xl mb-2 block"></i>
                            Pilih metode pembayaran
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Summary (sticky, full width) -->
            <div class="border-t border-surface-200 dark:border-surface-700 pt-3 mt-3 shrink-0 mx-1">
                <div class="flex items-center justify-between text-sm mb-3">
                    <div class="text-center flex-1">
                        <div class="text-surface-500">Total Bayar</div>
                        <div class="font-bold text-base">{{ formatCurrency(totalBayar) }}</div>
                    </div>
                    <div v-if="totalBiayaPembayaran > 0" class="text-center flex-1">
                        <div class="text-surface-500">Biaya</div>
                        <div class="font-bold text-base text-orange-500">+{{ formatCurrency(totalBiayaPembayaran) }}</div>
                    </div>
                    <div v-if="sisaBayar > 0" class="text-center flex-1">
                        <div class="text-red-500">Sisa</div>
                        <div class="font-bold text-base text-red-500">{{ formatCurrency(sisaBayar) }}</div>
                    </div>
                    <div v-if="kembalian > 0" class="text-center flex-1">
                        <div class="text-green-600">Kembali</div>
                        <div class="font-bold text-base text-green-600">{{ formatCurrency(kembalian) }}</div>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <Button label="Batal" severity="secondary" @click="paymentDialog = false" :disabled="paymentProcessing" />
                    <Button label="PROSES PEMBAYARAN" icon="pi pi-check" @click="processPayment" :loading="paymentProcessing" :disabled="!canProcessPayment" />
                </div>
            </div>
        </div>
    </Dialog>

    <!-- Receipt Dialog (2 Column Layout) -->
    <Dialog v-model:visible="receiptDialog" header="STRUK" modal :style="{ width: '700px' }" :closable="!loadingReceipt">
        <div v-if="loadingReceipt" class="text-center py-8">
            <i class="pi pi-spin pi-spinner text-2xl"></i>
        </div>
        <div v-else-if="receiptData" class="flex gap-4" style="min-height: 300px">
            <!-- Left Column: Detail Struk (40%) - Scrollable -->
            <div class="w-2/5 overflow-y-auto pr-2 border-r border-surface-200 dark:border-surface-700" style="max-height: calc(80vh - 150px)">
                <div class="font-mono text-sm print-area text-surface-900 dark:text-surface-100">
                    <!-- Store Header -->
                    <div class="text-center mb-2">
                        <div class="font-bold text-base">{{ settingsStore.store.name }}</div>
                        <div v-if="settingsStore.store.address" class="text-xs">{{ settingsStore.store.address }}</div>
                        <div v-if="settingsStore.store.phone" class="text-xs">Telp: {{ settingsStore.store.phone }}</div>
                        <div v-if="settingsStore.store.npwp" class="text-xs">NPWP: {{ settingsStore.store.npwp }}</div>
                    </div>
                    <hr class="border-dashed my-1" />

                    <!-- Transaction Info (2 columns) -->
                    <div class="grid grid-cols-2 gap-x-2 gap-y-0.5 mb-1 text-xs">
                        <div>No: {{ receiptData.nomor_dokumen }}</div>
                        <div>Kasir: {{ receiptData.created_by?.name || '' }}</div>
                        <div>Tgl: {{ formatDateTime(receiptData.tanggal) }}</div>
                        <div>Cust: {{ receiptData.customer?.nama || 'Walk-in' }}</div>
                    </div>
                    <hr class="border-dashed my-1" />

                    <!-- Items -->
                    <div v-for="detail in receiptData.details" :key="detail.id" class="mb-1">
                        <div>{{ detail.product?.nama_produk }}</div>
                        <div v-if="detail.serial_units?.length" class="pl-2 text-[11px] text-surface-500">
                            <div v-for="(u, i) in detail.serial_units" :key="i" class="font-mono">{{ serialLineText(u) }}</div>
                        </div>
                        <div class="flex justify-between pl-2">
                            <span>{{ formatQty(detail.qty) }} {{ detail.unit }} x {{ formatCurrency(detail.harga_satuan) }}</span>
                            <span>{{ formatCurrency(Number(detail.qty) * Number(detail.harga_satuan)) }}</span>
                        </div>
                        <div v-if="Number(detail.diskon_total) > 0" class="flex justify-between pl-2 text-xs">
                            <span>{{ formatDiscLine(detail) }}</span>
                            <span>-{{ formatCurrency(detail.diskon_total) }}</span>
                        </div>
                    </div>
                    <hr class="border-dashed my-1" />

                    <!-- Summary -->
                    <div class="space-y-0.5">
                        <div class="flex justify-between">
                            <span>Subtotal</span><span>{{ formatCurrency(receiptData.subtotal) }}</span>
                        </div>
                        <div v-if="Number(receiptData.diskon_nota_1_hasil) > 0" class="flex justify-between">
                            <span>{{ receiptData.diskon_nota_1_label || 'Disc 1' }} ({{ receiptData.diskon_nota_1_tipe === 'percent' ? formatPercent(receiptData.diskon_nota_1_nilai) : formatCurrency(receiptData.diskon_nota_1_nilai) }})</span>
                            <span>-{{ formatCurrency(receiptData.diskon_nota_1_hasil) }}</span>
                        </div>
                        <div v-if="Number(receiptData.diskon_nota_2_hasil) > 0" class="flex justify-between">
                            <span>{{ receiptData.diskon_nota_2_label || 'Disc 2' }} ({{ receiptData.diskon_nota_2_tipe === 'percent' ? formatPercent(receiptData.diskon_nota_2_nilai) : formatCurrency(receiptData.diskon_nota_2_nilai) }})</span>
                            <span>-{{ formatCurrency(receiptData.diskon_nota_2_hasil) }}</span>
                        </div>
                        <div v-if="Number(receiptData.diskon_nota_3_hasil) > 0" class="flex justify-between">
                            <span>{{ receiptData.diskon_nota_3_label || 'Disc Manual' }} ({{ receiptData.diskon_nota_3_tipe === 'percent' ? formatPercent(receiptData.diskon_nota_3_nilai) : formatCurrency(receiptData.diskon_nota_3_nilai) }})</span>
                            <span>-{{ formatCurrency(receiptData.diskon_nota_3_hasil) }}</span>
                        </div>
                        <div v-if="Number(receiptData.total_diskon) > 0" class="flex justify-between">
                            <span>Total</span><span>{{ formatCurrency(receiptData.total_setelah_diskon) }}</span>
                        </div>
                        <div v-if="Number(receiptData.biaya_kirim_hasil) > 0" class="flex justify-between">
                            <span>Biaya Kirim ({{ receiptData.biaya_kirim_tipe === 'percent' ? formatPercent(receiptData.biaya_kirim_nilai) : formatCurrency(receiptData.biaya_kirim_nilai) }})</span>
                            <span>{{ formatCurrency(receiptData.biaya_kirim_hasil) }}</span>
                        </div>
                        <div v-if="Number(receiptData.biaya_lain_hasil) > 0" class="flex justify-between">
                            <span>Biaya Lain ({{ receiptData.biaya_lain_tipe === 'percent' ? formatPercent(receiptData.biaya_lain_nilai) : formatCurrency(receiptData.biaya_lain_nilai) }})</span>
                            <span>{{ formatCurrency(receiptData.biaya_lain_hasil) }}</span>
                        </div>
                        <div v-if="Number(receiptData.pajak_nominal) > 0" class="flex justify-between">
                            <span>DPP</span><span>{{ formatCurrency(receiptData.dpp) }}</span>
                        </div>
                        <div v-if="Number(receiptData.pajak_nominal) > 0" class="flex justify-between">
                            <span>{{ receiptData.pajak_nama }} {{ receiptData.pajak_persen }}%</span>
                            <span>{{ formatCurrency(receiptData.pajak_nominal) }}</span>
                        </div>
                        <div v-if="Number(receiptData.pembulatan)" class="flex justify-between">
                            <span>Pembulatan</span><span>{{ formatCurrency(receiptData.pembulatan) }}</span>
                        </div>
                    </div>
                    <hr class="border-dashed my-1" />

                    <!-- Grand Total -->
                    <div class="flex justify-between font-bold text-base my-1">
                        <span>GRAND TOTAL</span>
                        <span>{{ formatCurrency(receiptData.grand_total) }}</span>
                    </div>
                    <hr class="border-dashed my-1" />

                    <!-- Retur Policy (pakai builder yang sama dengan PDF/thermal) -->
                    <div v-if="returPolicyText" class="text-center text-xs text-surface-400 mt-2 italic">
                        {{ returPolicyText }}
                    </div>

                    <!-- Footer (multi-line support) -->
                    <div class="text-center mt-2">
                        <div v-for="(line, i) in (settingsStore.store.receiptFooter || 'Terima Kasih!').split('\n')" :key="'f' + i">{{ line }}</div>
                    </div>

                    <!-- Notes -->
                    <div v-if="receiptData.notes" class="text-xs text-surface-500 mt-1 text-center">{{ receiptData.notes }}</div>

                    <!-- Return History -->
                    <template v-if="receiptData.returns?.length > 0">
                        <hr class="border-dashed my-2" />
                        <div class="text-xs">
                            <div class="font-bold mb-1 flex items-center gap-1">
                                <i class="pi pi-replay text-orange-500 text-xs"></i>
                                RIWAYAT RETUR ({{ receiptData.returns.length }})
                            </div>
                            <div v-for="ret in receiptData.returns" :key="ret.id" class="mb-2 p-1.5 bg-orange-50 dark:bg-orange-950/30 rounded">
                                <div class="flex justify-between mb-0.5">
                                    <span class="font-medium">{{ ret.nomor_dokumen }}</span>
                                    <span class="text-green-600">Tunai</span>
                                </div>
                                <div class="text-surface-500 mb-0.5">{{ formatDateTime(ret.tanggal) }}</div>
                                <div v-for="d in ret.details" :key="d.id">
                                    <div class="flex justify-between pl-1">
                                        <span>{{ d.product?.nama_produk }} x {{ formatQty(d.qty) }}</span>
                                        <span class="text-orange-600">@ {{ formatCurrency(d.harga_satuan) }}</span>
                                    </div>
                                    <div v-if="d.serial_units?.length" class="pl-2 text-[10px] text-surface-500 font-mono">
                                        <div v-for="(u, i) in d.serial_units" :key="i">{{ serialLineText(u) }}</div>
                                    </div>
                                </div>
                                <div v-if="Number(ret.pembulatan)" class="flex justify-between pl-1">
                                    <span>Pembulatan</span>
                                    <span class="text-orange-600">{{ formatCurrency(ret.pembulatan) }}</span>
                                </div>
                                <div class="flex justify-between font-medium mt-0.5 pt-0.5 border-t border-orange-200 dark:border-orange-800">
                                    <span>Total</span>
                                    <span class="text-orange-600">{{ formatCurrency(ret.grand_total) }}</span>
                                </div>
                            </div>

                            <!-- Ringkasan -->
                            <div class="p-1.5 bg-surface-100 dark:bg-surface-800 rounded">
                                <div class="font-bold mb-0.5">RINGKASAN</div>
                                <div class="flex justify-between">
                                    <span>Pembayaran Asli</span><span>{{ formatCurrency(receiptData.grand_total) }}</span>
                                </div>
                                <div v-if="Number(receiptData.biaya_kirim_hasil) > 0 || Number(receiptData.biaya_lain_hasil) > 0" class="mt-0.5">
                                    <div class="text-surface-500">Tidak Termasuk Retur:</div>
                                    <div v-if="Number(receiptData.biaya_kirim_hasil) > 0" class="flex justify-between pl-1">
                                        <span>Biaya Kirim</span><span>{{ formatCurrency(receiptData.biaya_kirim_hasil) }}</span>
                                    </div>
                                    <div v-if="Number(receiptData.biaya_lain_hasil) > 0" class="flex justify-between pl-1">
                                        <span>Biaya Lain</span><span>{{ formatCurrency(receiptData.biaya_lain_hasil) }}</span>
                                    </div>
                                </div>
                                <div class="flex justify-between mt-0.5">
                                    <span>Total Semua Retur</span><span class="text-orange-600">{{ formatCurrency(receiptData.returns.reduce((s, r) => s + Number(r.grand_total), 0)) }}</span>
                                </div>
                                <div class="flex justify-between text-xs pl-1">
                                    <span class="text-surface-500">Refund Tunai</span><span>{{ formatCurrency(receiptData.returns.reduce((s, r) => s + Number(r.grand_total), 0)) }}</span>
                                </div>
                                <div class="flex justify-between font-bold mt-0.5 pt-0.5 border-t border-surface-300 dark:border-surface-600">
                                    <span>NILAI BERSIH</span>
                                    <span class="text-primary">{{ formatCurrency(Number(receiptData.grand_total) - receiptData.returns.reduce((s, r) => s + Number(r.grand_total), 0)) }}</span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Right Column: Payment Info (60%) -->
            <div class="w-3/5 flex flex-col">
                <!-- Payment Methods -->
                <div class="mb-4">
                    <div class="text-sm font-semibold text-surface-600 dark:text-surface-400 mb-2">METODE PEMBAYARAN</div>
                    <div class="space-y-2">
                        <div v-for="payment in receiptData.payments" :key="payment.id" class="bg-surface-50 dark:bg-surface-800 rounded-lg p-3">
                            <div class="flex justify-between items-center">
                                <span class="font-medium">{{ payment.metode_pembayaran?.nama_pembayaran }}</span>
                                <span class="font-semibold">{{ formatCurrency(payment.nominal) }}</span>
                            </div>
                            <div v-if="payment.reference" class="text-xs text-surface-500 mt-1">Ref: {{ payment.reference }}</div>
                            <div v-if="Number(payment.biaya_tambahan) > 0" class="text-xs text-surface-500">Biaya: {{ formatCurrency(payment.biaya_tambahan) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Total Bayar & Kembalian -->
                <div class="flex-1">
                    <div class="bg-primary/10 rounded-lg p-4 mb-3">
                        <div class="text-sm text-surface-600 dark:text-surface-400 mb-1">TOTAL BAYAR</div>
                        <div class="text-2xl font-bold text-primary">{{ formatCurrency(receiptData.total_bayar) }}</div>
                    </div>
                    <div v-if="Number(receiptData.kembalian) > 0" class="bg-green-500/10 rounded-lg p-4">
                        <div class="text-sm text-surface-600 dark:text-surface-400 mb-1">KEMBALIAN</div>
                        <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(receiptData.kembalian) }}</div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
                    <div class="flex gap-2 mb-3">
                        <Button icon="pi pi-whatsapp" severity="success" outlined class="flex-1" @click="openWhatsApp" label="WhatsApp" />
                        <Button icon="pi pi-file-pdf" severity="warn" outlined class="flex-1" @click="downloadPdf" label="PDF" />
                    </div>
                    <Button label="Print" icon="pi pi-print" severity="secondary" class="w-full mb-2" @click="printReceipt" />
                    <Button v-if="isAfterCheckout" label="Transaksi Baru" icon="pi pi-plus" class="w-full" @click="newTransaction" />
                    <Button v-else label="Tutup" severity="secondary" class="w-full" @click="receiptDialog = false" />
                </div>
            </div>
        </div>
    </Dialog>

    <!-- WhatsApp Dialog -->
    <Dialog v-model:visible="waDialog" header="Kirim Struk via WhatsApp" modal :style="{ width: '380px' }">
        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium mb-1">Nomor HP</label>
                <InputText v-select-on-focus v-model="waPhone" placeholder="628xxxxxxxxxx" class="w-full" />
                <small class="text-muted-color">Format: 628xxx (tanpa +)</small>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Preview Pesan</label>
                <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-lg text-sm text-surface-600 dark:text-surface-300">
                    Terima kasih telah berbelanja di {{ settingsStore.store.name }}.<br />
                    Berikut struk belanja Anda:<br />
                    <span class="text-primary break-all">{{ receiptData ? getReceiptUrl(receiptData.ulid) : '' }}</span>
                </div>
            </div>
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" @click="waDialog = false" />
            <Button label="Kirim" icon="pi pi-send" @click="sendWhatsApp" :disabled="!waPhone" />
        </template>
    </Dialog>

    <!-- Disc Nota 3 (Manual) Dialog -->
    <Dialog v-model:visible="discountDialog" header="Disc Nota 3 (Manual)" modal :style="{ width: '350px' }">
        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium mb-1">Tipe Diskon</label>
                <SelectButton
                    v-model="discountForm.tipe"
                    :options="[
                        { label: 'Persen (%)', value: 'percent' },
                        { label: `Nominal (${currencySettings.symbol})`, value: 'nominal' }
                    ]"
                    optionLabel="label"
                    optionValue="value"
                />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Nilai</label>
                <InputNumber
                    v-select-on-focus
                    v-model="discountForm.nilai"
                    :min="0"
                    :max="discountForm.tipe === 'percent' ? 100 : undefined"
                    :locale="getLocale"
                    :prefix="discountForm.tipe === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                    :suffix="discountForm.tipe === 'percent' ? '%' : discountForm.tipe === 'nominal' && currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                    :minFractionDigits="discountForm.tipe === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                    :maxFractionDigits="discountForm.tipe === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                    class="w-full"
                />
            </div>
        </div>
        <template #footer>
            <Button label="Hapus Diskon" severity="danger" text @click="removeDiscount" />
            <Button label="Terapkan" icon="pi pi-check" @click="applyDiscount" />
        </template>
    </Dialog>

    <!-- Biaya Kirim & Lain Dialog -->
    <Dialog v-model:visible="biayaDialog" header="Biaya Tambahan" modal :style="{ width: '400px' }">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-bold mb-2">Biaya Kirim</label>
                <div class="space-y-2">
                    <SelectButton
                        v-model="biayaForm.kirim_tipe"
                        :options="[
                            { label: `Nominal (${currencySettings.symbol})`, value: 'nominal' },
                            { label: 'Persen (%)', value: 'percent' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                    />
                    <InputNumber
                        v-select-on-focus
                        v-model="biayaForm.kirim_nilai"
                        :min="0"
                        :max="biayaForm.kirim_tipe === 'percent' ? 100 : undefined"
                        :locale="getLocale"
                        :prefix="biayaForm.kirim_tipe === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                        :suffix="biayaForm.kirim_tipe === 'percent' ? '%' : biayaForm.kirim_tipe === 'nominal' && currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                        :minFractionDigits="biayaForm.kirim_tipe === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                        :maxFractionDigits="biayaForm.kirim_tipe === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                        class="w-full"
                    />
                </div>
            </div>
            <div>
                <label class="block text-sm font-bold mb-2">Biaya Lain-Lain</label>
                <div class="space-y-2">
                    <SelectButton
                        v-model="biayaForm.lain_tipe"
                        :options="[
                            { label: `Nominal (${currencySettings.symbol})`, value: 'nominal' },
                            { label: 'Persen (%)', value: 'percent' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                    />
                    <InputNumber
                        v-select-on-focus
                        v-model="biayaForm.lain_nilai"
                        :min="0"
                        :max="biayaForm.lain_tipe === 'percent' ? 100 : undefined"
                        :locale="getLocale"
                        :prefix="biayaForm.lain_tipe === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                        :suffix="biayaForm.lain_tipe === 'percent' ? '%' : biayaForm.lain_tipe === 'nominal' && currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                        :minFractionDigits="biayaForm.lain_tipe === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                        :maxFractionDigits="biayaForm.lain_tipe === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                        class="w-full"
                    />
                </div>
            </div>
        </div>
        <template #footer>
            <Button label="Hapus Semua Biaya" severity="danger" text @click="clearBiaya" />
            <Button label="Terapkan" icon="pi pi-check" @click="applyBiaya" />
        </template>
    </Dialog>

    <!-- Line Discount Dialog -->
    <Dialog v-model:visible="lineDiscountDialog" header="Diskon Item" modal :style="{ width: '350px' }">
        <div v-if="lineDiscountItem" class="space-y-3">
            <div class="text-sm text-surface-500">{{ lineDiscountItem.product.nama_produk }}</div>
            <div>
                <label class="block text-sm font-medium mb-1">Tipe Diskon</label>
                <SelectButton
                    v-model="lineDiscountTipe"
                    :options="[
                        { label: 'Persen (%)', value: 'percent' },
                        { label: `Nominal (${currencySettings.symbol})`, value: 'nominal' }
                    ]"
                    optionLabel="label"
                    optionValue="value"
                    class="w-full"
                    @change="lineDiscountValue = 0"
                />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Nilai Diskon</label>
                <InputNumber
                    v-select-on-focus
                    v-model="lineDiscountValue"
                    :min="0"
                    :max="lineDiscountTipe === 'percent' ? 100 : undefined"
                    :locale="getLocale"
                    :prefix="lineDiscountTipe === 'nominal' && currencySettings.position === 'before' ? currencySettings.symbol + ' ' : ''"
                    :suffix="lineDiscountTipe === 'percent' ? '%' : lineDiscountTipe === 'nominal' && currencySettings.position === 'after' ? ' ' + currencySettings.symbol : ''"
                    :minFractionDigits="lineDiscountTipe === 'percent' ? getPercentMinFractionDigits : getCurrencyMinFractionDigits"
                    :maxFractionDigits="lineDiscountTipe === 'percent' ? getPercentMaxFractionDigits : getCurrencyMaxFractionDigits"
                    class="w-full"
                />
            </div>
        </div>
        <template #footer>
            <Button label="Terapkan" icon="pi pi-check" @click="applyLineDiscount" />
        </template>
    </Dialog>

    <!-- Void Dialog -->
    <Dialog v-model:visible="voidDialog" header="Void Transaksi" modal :style="{ width: '400px' }" :closable="!voidProcessing">
        <div>
            <label class="block text-sm font-medium mb-1">Alasan Void</label>
            <Textarea v-model="voidReason" rows="3" class="w-full" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" placeholder="Masukkan alasan void..." />
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" @click="voidDialog = false" :disabled="voidProcessing" />
            <Button label="Void" icon="pi pi-trash" severity="danger" @click="processVoid" :loading="voidProcessing" :disabled="!voidReason?.trim()" />
        </template>
    </Dialog>

    <!-- Sales Detail Dialog -->
    <Dialog v-model:visible="salesDetailDialog" header="Detail Transaksi" modal :style="{ width: '550px' }">
        <div v-if="loadingSalesDetail" class="text-center py-8">
            <i class="pi pi-spin pi-spinner text-2xl"></i>
        </div>
        <div v-else-if="salesDetailData" class="space-y-3 text-sm">
            <!-- Header Info -->
            <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                <div><span class="text-surface-500">No. Invoice:</span> {{ salesDetailData.nomor_dokumen }}</div>
                <div><span class="text-surface-500">Status:</span> <Tag :severity="salesDetailData.status === 'completed' ? 'success' : 'danger'" :value="salesDetailData.status === 'completed' ? 'Selesai' : 'Void'" /></div>
                <div><span class="text-surface-500">Tanggal:</span> {{ formatDateTime(salesDetailData.tanggal) }}</div>
                <div><span class="text-surface-500">Customer:</span> {{ salesDetailData.customer?.nama || 'Walk-in' }}</div>
                <div><span class="text-surface-500">Kasir:</span> {{ salesDetailData.created_by?.name || '-' }}</div>
                <div>
                    <span class="text-surface-500">Struk:</span>
                    <a :href="getReceiptUrl(salesDetailData.ulid)" target="_blank" class="text-primary hover:underline ml-1">Buka</a>
                </div>
            </div>

            <!-- Items Table -->
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-surface-200 dark:border-surface-700">
                        <th class="text-left py-1">Produk</th>
                        <th class="text-right py-1">Qty</th>
                        <th class="text-right py-1">Harga</th>
                        <th class="text-right py-1">Disc</th>
                        <th class="text-right py-1">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in salesDetailData.details" :key="d.id" class="border-b border-surface-100 dark:border-surface-700">
                        <td class="py-1">
                            <div>{{ d.product?.nama_produk }}</div>
                            <div v-if="d.serial_units?.length" class="text-[11px] text-surface-500 font-mono">
                                <div v-for="(u, i) in d.serial_units" :key="i">{{ serialLineText(u) }}</div>
                            </div>
                            <div v-if="Number(d.diskon_total) > 0" class="text-xs text-surface-400">{{ formatDiscLine(d) }}</div>
                        </td>
                        <td class="py-1 text-right whitespace-nowrap">{{ formatQty(d.qty) }} {{ d.unit }}</td>
                        <td class="py-1 text-right">{{ formatCurrency(d.harga_satuan) }}</td>
                        <td class="py-1 text-right text-red-500">{{ Number(d.diskon_total) > 0 ? '-' + formatCurrency(d.diskon_total) : '-' }}</td>
                        <td class="py-1 text-right">{{ formatCurrency(d.jumlah) }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- Summary -->
            <div class="border-t border-surface-200 dark:border-surface-700 pt-2 space-y-1">
                <div class="flex justify-between">
                    <span>Subtotal</span><span>{{ formatCurrency(salesDetailData.subtotal) }}</span>
                </div>
                <div v-if="Number(salesDetailData.diskon_nota_1_hasil) > 0" class="flex justify-between text-red-500">
                    <span>{{ salesDetailData.diskon_nota_1_label || 'Disc 1' }} ({{ salesDetailData.diskon_nota_1_tipe === 'percent' ? formatPercent(salesDetailData.diskon_nota_1_nilai) : formatCurrency(salesDetailData.diskon_nota_1_nilai) }})</span>
                    <span>-{{ formatCurrency(salesDetailData.diskon_nota_1_hasil) }}</span>
                </div>
                <div v-if="Number(salesDetailData.diskon_nota_2_hasil) > 0" class="flex justify-between text-red-500">
                    <span>{{ salesDetailData.diskon_nota_2_label || 'Disc 2' }} ({{ salesDetailData.diskon_nota_2_tipe === 'percent' ? formatPercent(salesDetailData.diskon_nota_2_nilai) : formatCurrency(salesDetailData.diskon_nota_2_nilai) }})</span>
                    <span>-{{ formatCurrency(salesDetailData.diskon_nota_2_hasil) }}</span>
                </div>
                <div v-if="Number(salesDetailData.diskon_nota_3_hasil) > 0" class="flex justify-between text-red-500">
                    <span
                        >{{ salesDetailData.diskon_nota_3_label || 'Disc Manual' }} ({{
                            salesDetailData.diskon_nota_3_tipe === 'percent' ? formatPercent(salesDetailData.diskon_nota_3_nilai) : formatCurrency(salesDetailData.diskon_nota_3_nilai)
                        }})</span
                    >
                    <span>-{{ formatCurrency(salesDetailData.diskon_nota_3_hasil) }}</span>
                </div>
                <div v-if="Number(salesDetailData.total_diskon) > 0" class="flex justify-between">
                    <span>Total Setelah Diskon</span><span>{{ formatCurrency(salesDetailData.total_setelah_diskon) }}</span>
                </div>
                <div v-if="Number(salesDetailData.biaya_kirim_hasil) > 0" class="flex justify-between">
                    <span>Biaya Kirim ({{ salesDetailData.biaya_kirim_tipe === 'percent' ? formatPercent(salesDetailData.biaya_kirim_nilai) : formatCurrency(salesDetailData.biaya_kirim_nilai) }})</span>
                    <span>{{ formatCurrency(salesDetailData.biaya_kirim_hasil) }}</span>
                </div>
                <div v-if="Number(salesDetailData.biaya_lain_hasil) > 0" class="flex justify-between">
                    <span>Biaya Lain ({{ salesDetailData.biaya_lain_tipe === 'percent' ? formatPercent(salesDetailData.biaya_lain_nilai) : formatCurrency(salesDetailData.biaya_lain_nilai) }})</span>
                    <span>{{ formatCurrency(salesDetailData.biaya_lain_hasil) }}</span>
                </div>
                <div v-if="Number(salesDetailData.pajak_nominal) > 0" class="flex justify-between">
                    <span>DPP</span><span>{{ formatCurrency(salesDetailData.dpp) }}</span>
                </div>
                <div v-if="Number(salesDetailData.pajak_nominal) > 0" class="flex justify-between">
                    <span>{{ salesDetailData.pajak_nama }} {{ salesDetailData.pajak_persen }}%</span>
                    <span>{{ formatCurrency(salesDetailData.pajak_nominal) }}</span>
                </div>
                <div v-if="Number(salesDetailData.pembulatan)" class="flex justify-between">
                    <span>Pembulatan</span><span>{{ formatCurrency(salesDetailData.pembulatan) }}</span>
                </div>
                <div class="flex justify-between font-bold text-base border-t border-surface-200 dark:border-surface-700 pt-1 mt-1">
                    <span>Grand Total</span><span>{{ formatCurrency(salesDetailData.grand_total) }}</span>
                </div>
            </div>

            <!-- Payments -->
            <div class="border-t border-surface-200 dark:border-surface-700 pt-2">
                <div class="font-medium mb-1">Pembayaran:</div>
                <div v-for="p in salesDetailData.payments" :key="p.id">
                    <div class="flex justify-between">
                        <span>{{ p.metode_pembayaran?.nama_pembayaran }}</span>
                        <span>{{ formatCurrency(p.nominal) }}</span>
                    </div>
                    <div v-if="p.reference" class="text-xs text-surface-400 pl-2">Ref: {{ p.reference }}</div>
                    <div v-if="Number(p.biaya_tambahan) > 0" class="text-xs text-surface-400 pl-2">Biaya: {{ formatCurrency(p.biaya_tambahan) }}</div>
                </div>
                <div class="flex justify-between font-medium mt-1">
                    <span>Total Bayar</span><span>{{ formatCurrency(salesDetailData.total_bayar) }}</span>
                </div>
                <div v-if="Number(salesDetailData.kembalian) > 0" class="flex justify-between font-medium">
                    <span>Kembali</span>
                    <span>{{ formatCurrency(salesDetailData.kembalian) }}</span>
                </div>
            </div>

            <!-- Notes -->
            <div v-if="salesDetailData.notes" class="border-t border-surface-200 dark:border-surface-700 pt-2"><span class="text-surface-500">Catatan:</span> {{ salesDetailData.notes }}</div>

            <!-- Void Info -->
            <div v-if="salesDetailData.status === 'voided'" class="border-t border-surface-200 dark:border-surface-700 pt-2 bg-red-50 dark:bg-red-950/20 -mx-4 px-4 py-2 rounded">
                <div class="font-medium text-red-600 mb-1">Informasi Void</div>
                <div class="text-red-500">Void oleh: {{ salesDetailData.voided_by?.name || '-' }}</div>
                <div class="text-red-500">Alasan: {{ salesDetailData.void_reason }}</div>
                <div class="text-red-500">Waktu: {{ formatDateTime(salesDetailData.voided_at) }}</div>
            </div>

            <!-- Return History -->
            <div v-if="salesDetailData.returns?.length > 0" class="border-t border-surface-200 dark:border-surface-700 pt-2">
                <div class="font-medium mb-2 flex items-center gap-2">
                    <i class="pi pi-replay text-orange-500"></i>
                    Riwayat Retur ({{ salesDetailData.returns.length }})
                </div>
                <div v-for="ret in salesDetailData.returns" :key="ret.id" class="mb-3 p-2 bg-orange-50 dark:bg-orange-950/20 rounded">
                    <div class="flex justify-between text-sm mb-1">
                        <span class="font-medium">{{ ret.nomor_dokumen }}</span>
                        <Tag severity="success" value="Tunai" size="small" />
                    </div>
                    <div class="text-xs text-surface-500 mb-1">{{ formatDateTime(ret.tanggal) }} oleh {{ ret.created_by?.name || '-' }}</div>
                    <div class="text-xs space-y-0.5">
                        <div v-for="d in ret.details" :key="d.id">
                            <div class="flex justify-between">
                                <span>{{ d.product?.nama_produk }} x {{ formatQty(d.qty) }}</span>
                                <span class="text-orange-600">@ {{ formatCurrency(d.harga_satuan) }}</span>
                            </div>
                            <div v-if="d.serial_units?.length" class="pl-2 text-[10px] text-surface-500 font-mono">
                                <div v-for="(u, i) in d.serial_units" :key="i">{{ serialLineText(u) }}</div>
                            </div>
                        </div>
                    </div>
                    <div v-if="Number(ret.pembulatan)" class="flex justify-between text-xs mt-1">
                        <span>Pembulatan</span>
                        <span class="text-orange-600">{{ formatCurrency(ret.pembulatan) }}</span>
                    </div>
                    <div class="flex justify-between text-sm font-medium mt-1 pt-1 border-t border-orange-200 dark:border-orange-800">
                        <span>Total Retur</span>
                        <span class="text-orange-600">{{ formatCurrency(ret.grand_total) }}</span>
                    </div>
                </div>

                <!-- Ringkasan Retur -->
                <div class="mt-3 p-3 bg-surface-100 dark:bg-surface-800 rounded-lg text-sm">
                    <div class="font-semibold mb-2 text-surface-700 dark:text-surface-300">RINGKASAN</div>

                    <div class="flex justify-between mb-1">
                        <span class="text-surface-600 dark:text-surface-400">Total Pembayaran Asli</span>
                        <span class="font-medium">{{ formatCurrency(salesDetailData.grand_total) }}</span>
                    </div>

                    <div v-if="Number(salesDetailData.biaya_kirim_hasil) > 0 || Number(salesDetailData.biaya_lain_hasil) > 0" class="mt-2 mb-2">
                        <div class="text-xs text-surface-500 mb-1">Tidak Termasuk Retur:</div>
                        <div v-if="Number(salesDetailData.biaya_kirim_hasil) > 0" class="flex justify-between text-xs pl-2">
                            <span class="text-surface-500">Biaya Kirim</span>
                            <span>{{ formatCurrency(salesDetailData.biaya_kirim_hasil) }}</span>
                        </div>
                        <div v-if="Number(salesDetailData.biaya_lain_hasil) > 0" class="flex justify-between text-xs pl-2">
                            <span class="text-surface-500">Biaya Lain</span>
                            <span>{{ formatCurrency(salesDetailData.biaya_lain_hasil) }}</span>
                        </div>
                    </div>

                    <div class="flex justify-between mb-2">
                        <span class="text-surface-600 dark:text-surface-400">Total Retur (Tunai)</span>
                        <span class="font-medium text-orange-600">{{ formatCurrency(salesDetailData.returns.reduce((sum, r) => sum + Number(r.grand_total), 0)) }}</span>
                    </div>

                    <div class="flex justify-between font-bold pt-2 border-t border-surface-300 dark:border-surface-600">
                        <span>NILAI BERSIH</span>
                        <span class="text-primary">{{ formatCurrency(Number(salesDetailData.grand_total) - salesDetailData.returns.reduce((sum, r) => sum + Number(r.grand_total), 0)) }}</span>
                    </div>
                    <div class="text-xs text-surface-500">(Pembayaran - Retur)</div>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-between w-full">
                <div class="flex gap-1">
                    <Button
                        icon="pi pi-whatsapp"
                        severity="success"
                        outlined
                        size="small"
                        @click="
                            receiptData = salesDetailData;
                            openWhatsApp();
                        "
                        v-tooltip.top="'WhatsApp'"
                        aria-label="Kirim via WhatsApp"
                    />
                    <Button
                        icon="pi pi-file-pdf"
                        severity="warn"
                        outlined
                        size="small"
                        @click="
                            receiptData = salesDetailData;
                            downloadPdf();
                        "
                        v-tooltip.top="'PDF'"
                        aria-label="Unduh PDF"
                    />
                </div>
                <div class="flex gap-2">
                    <Button
                        label="Print Struk"
                        icon="pi pi-print"
                        severity="secondary"
                        @click="
                            receiptData = salesDetailData;
                            printReceipt();
                        "
                    />
                    <Button label="Tutup" @click="salesDetailDialog = false" />
                </div>
            </div>
        </template>
    </Dialog>

    <!-- Shortcut Help Dialog -->
    <Dialog v-model:visible="shortcutHelpDialog" header="Shortcut Keyboard POS" modal :style="{ width: '440px' }">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-surface-500 border-b border-surface-200 dark:border-surface-700">
                    <th class="py-2">Tombol</th>
                    <th class="py-2">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b border-surface-100 dark:border-surface-800">
                    <td class="py-2"><kbd class="px-2 py-0.5 bg-surface-100 dark:bg-surface-800 rounded text-xs font-mono">F1</kbd></td>
                    <td>Fokus ke kotak cari produk / scan barcode</td>
                </tr>
                <tr class="border-b border-surface-100 dark:border-surface-800">
                    <td class="py-2"><kbd class="px-2 py-0.5 bg-surface-100 dark:bg-surface-800 rounded text-xs font-mono">F2</kbd></td>
                    <td>Buka bantuan shortcut ini</td>
                </tr>
                <tr class="border-b border-surface-100 dark:border-surface-800">
                    <td class="py-2"><kbd class="px-2 py-0.5 bg-surface-100 dark:bg-surface-800 rounded text-xs font-mono">F9</kbd></td>
                    <td>Hold transaksi (simpan keranjang sementara)</td>
                </tr>
                <tr class="border-b border-surface-100 dark:border-surface-800">
                    <td class="py-2"><kbd class="px-2 py-0.5 bg-surface-100 dark:bg-surface-800 rounded text-xs font-mono">F12</kbd></td>
                    <td>Buka dialog pembayaran</td>
                </tr>
                <tr class="border-b border-surface-100 dark:border-surface-800">
                    <td class="py-2"><kbd class="px-2 py-0.5 bg-surface-100 dark:bg-surface-800 rounded text-xs font-mono">Enter</kbd></td>
                    <td>Tambahkan produk yang difokus / konfirmasi dialog</td>
                </tr>
                <tr class="border-b border-surface-100 dark:border-surface-800">
                    <td class="py-2"><kbd class="px-2 py-0.5 bg-surface-100 dark:bg-surface-800 rounded text-xs font-mono">Esc</kbd></td>
                    <td>Tutup dialog yang sedang terbuka</td>
                </tr>
                <tr>
                    <td class="py-2"><kbd class="px-2 py-0.5 bg-surface-100 dark:bg-surface-800 rounded text-xs font-mono">Ctrl + /</kbd></td>
                    <td>Buka bantuan shortcut ini (alternatif)</td>
                </tr>
            </tbody>
        </table>
        <template #footer>
            <Button label="Tutup" icon="pi pi-check" @click="shortcutHelpDialog = false" />
        </template>
    </Dialog>

    <!-- Shift Report Dialog — editable mode saat belum close (uang fisik wajib di sini) -->
    <ShiftReportDialog v-model:visible="shiftReportDialog" :data="shiftReportData" :loading="loadingShiftReport" :closable="false" :editable="!shiftClosed" v-model:saldoFisik="reconcileSaldoFisik" v-model:closingNotes="reconcileNotes">
        <template #footer>
            <!-- Before closing: Tutup Shift button — disabled sampai uang fisik diisi -->
            <template v-if="!shiftClosed">
                <Button label="Batal" icon="pi pi-times" severity="secondary" @click="closeShiftReport" :disabled="endingShift" />
                <Button label="Tutup Shift" icon="pi pi-lock" severity="warn" @click="endShift" :loading="endingShift" :disabled="reconcileSaldoFisik === null || reconcileSaldoFisik === ''" />
            </template>
            <!-- After closing: show print/pdf buttons -->
            <template v-else>
                <Button label="Print" icon="pi pi-print" severity="secondary" @click="printShiftReport" />
                <Button label="Download PDF" icon="pi pi-file-pdf" severity="secondary" @click="downloadShiftReportPdf" />
                <Button label="Selesai" icon="pi pi-check" @click="finishAndRedirect" />
            </template>
        </template>
    </ShiftReportDialog>

    <!-- Lock Screen Overlay -->
    <Teleport to="body">
        <div v-if="isLocked" class="lock-overlay">
            <div class="lock-backdrop"></div>
            <div class="lock-content">
                <div class="lock-card">
                    <div class="text-center mb-6">
                        <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="pi pi-lock text-4xl text-primary"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-surface-800 dark:text-surface-100 mb-2">Layar Terkunci</h2>
                        <p class="text-surface-500 text-sm">Terminal: {{ terminalData?.kode_terminal }}</p>
                        <p class="text-surface-500 text-sm">Kasir: {{ authStore.user?.name }}</p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2"> Masukkan PIN atau Password </label>
                            <Password v-model="unlockCredential" :feedback="false" toggleMask inputClass="w-full text-center text-lg" class="w-full" placeholder="PIN / Password" @keydown="handleUnlockKeydown" :disabled="unlocking" autofocus />
                        </div>

                        <div v-if="unlockError" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3 text-red-600 dark:text-red-400 text-sm text-center">
                            <i class="pi pi-exclamation-circle mr-2"></i>{{ unlockError }}
                        </div>

                        <Button label="Buka Kunci" icon="pi pi-unlock" class="w-full" @click="unlockScreen" :loading="unlocking" />
                    </div>

                    <div class="mt-6 pt-4 border-t border-surface-200 dark:border-surface-700 text-center text-xs text-surface-400">
                        <i class="pi pi-info-circle mr-1"></i>
                        Tekan Enter untuk membuka kunci
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style>
@media print {
    /* Hide everything */
    body * {
        visibility: hidden !important;
    }
    /* Show only print-area and its children */
    .print-area,
    .print-area * {
        visibility: visible !important;
    }
    /* Position print-area at top-left */
    .print-area {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 80mm !important;
        padding: 2mm !important;
        font-size: 12px !important;
        color: #000 !important;
        background: #fff !important;
    }
    /* Remove dialog styling */
    .p-dialog-mask,
    .p-dialog,
    .p-dialog-header,
    .p-dialog-footer {
        all: unset !important;
    }
}

/* Lock Screen Overlay */
.lock-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}

.lock-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

.lock-content {
    position: relative;
    z-index: 1;
    animation: lockFadeIn 0.3s ease-out;
}

.lock-card {
    background: white;
    border-radius: 1rem;
    padding: 2rem;
    width: 380px;
    max-width: 90vw;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.dark .lock-card {
    background: #1f2937;
}

@keyframes lockFadeIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}
</style>

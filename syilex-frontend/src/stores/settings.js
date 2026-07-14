import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { settingsApi } from '@/api';

const STORAGE_KEY = 'posip_public_settings';

// Load cached settings from localStorage
const loadCachedSettings = () => {
    try {
        const cached = localStorage.getItem(STORAGE_KEY);
        if (cached) {
            return JSON.parse(cached);
        }
    } catch (e) {
        // Ignore parse errors
    }
    return {
        store: {
            name: 'POSIP',
            logo_url: '',
            icon_url: ''
        }
    };
};

// Save settings to localStorage
const saveCachedSettings = (settings) => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch (e) {
        // Ignore storage errors
    }
};

export const useSettingsStore = defineStore('settings', () => {
    // State - initialize from localStorage cache
    const publicSettings = ref(loadCachedSettings());
    const loaded = ref(false);
    const loading = ref(false);

    // Getters - Store (individual for backward compatibility)
    const storeName = computed(() => publicSettings.value.store?.name || 'POSIP');
    const storeLogo = computed(() => publicSettings.value.store?.logo_url || '');
    const storeIcon = computed(() => publicSettings.value.store?.icon_url || '');
    const loginBackground = computed(() => publicSettings.value.store?.login_background_url || '');

    // Getters - Store (full object)
    const store = computed(() => ({
        name: publicSettings.value.store?.name || 'POSIP',
        address: publicSettings.value.store?.address || '',
        phone: publicSettings.value.store?.phone || '',
        email: publicSettings.value.store?.email || '',
        npwp: publicSettings.value.store?.npwp || '',
        url: publicSettings.value.store?.url || '',
        logoUrl: publicSettings.value.store?.logo_url || '',
        iconUrl: publicSettings.value.store?.icon_url || '',
        // Receipt footer (multi-line supported; split on '\n' at render site)
        receiptFooter: publicSettings.value.store?.receipt_footer || 'Terima Kasih!'
    }));

    // Getters - Currency (with defaults)
    const currency = computed(() => ({
        code: publicSettings.value.currency?.code || 'IDR',
        symbol: publicSettings.value.currency?.symbol || 'Rp',
        position: publicSettings.value.currency?.position || 'before',
        thousandSeparator: publicSettings.value.currency?.thousand_separator || '.',
        decimalSeparator: publicSettings.value.currency?.decimal_separator || ',',
        decimalPlaces: publicSettings.value.currency?.decimal_places ?? 0
    }));

    // Getters - Regional (with defaults)
    const regional = computed(() => ({
        timezone: publicSettings.value.regional?.timezone || 'Asia/Jakarta',
        dateFormat: publicSettings.value.regional?.date_format || 'DD/MM/YYYY',
        timeFormat: publicSettings.value.regional?.time_format || 'HH:mm'
    }));

    // Getters - Text (with defaults) - loaded separately for authenticated users
    const text = computed(() => ({
        uppercaseMode: publicSettings.value.text?.uppercase_mode || 'all'
    }));

    // Getters - Number (with defaults)
    const number = computed(() => ({
        qtyDecimalPlaces: publicSettings.value.number?.qty_decimal_places ?? 0,
        percentDecimalPlaces: publicSettings.value.number?.percent_decimal_places ?? 2
    }));

    // Getters - Tax (with defaults)
    const tax = computed(() => ({
        purchaseName: publicSettings.value.tax?.tax_purchase_name || 'PPN',
        purchasePercent: publicSettings.value.tax?.tax_purchase_percent ?? 11,
        purchaseIncludedInHpp: publicSettings.value.tax?.tax_purchase_included_in_hpp ?? false,
        salesName: publicSettings.value.tax?.tax_sales_name || 'PPN',
        salesPercent: publicSettings.value.tax?.tax_sales_percent ?? 11
    }));

    // Getters - Rounding (with defaults)
    const rounding = computed(() => ({
        purchaseMethod: publicSettings.value.rounding?.purchase_method || 'none',
        purchasePrecision: publicSettings.value.rounding?.purchase_precision ?? 1,
        salesMethod: publicSettings.value.rounding?.sales_method || 'none',
        salesPrecision: publicSettings.value.rounding?.sales_precision ?? 1
    }));

    // Getters - Product (with defaults)
    const product = computed(() => ({
        priceInputMode: publicSettings.value.product?.price_input_mode || 'auto'
    }));

    // Getters - Promo (with defaults)
    const promo = computed(() => ({
        enabled: publicSettings.value.promo?.enabled ?? true,
        allowManualDiscount: publicSettings.value.promo?.allow_manual_discount ?? true,
        maxManualDiscountPercent: publicSettings.value.promo?.max_manual_discount_percent ?? 100,
        maxManualDiscountNominal: publicSettings.value.promo?.max_manual_discount_nominal ?? 0
    }));

    // Getters - Stock (with defaults)
    const stock = computed(() => ({
        negativeMode: publicSettings.value.stock?.negative_mode || 'block'
    }));

    // Getters - Calculation (with defaults)
    const calculation = computed(() => ({
        discountMode: publicSettings.value.calculation?.discount_mode || 'recursive',
        costAllocationMode: publicSettings.value.calculation?.cost_allocation_mode || 'by_value'
    }));

    // Getters - Modules (toggle fitur opsional). Retail selalu aktif; elektronik (serial) on/off.
    // Default TRUE bila belum ada setting → fitur serial tampil sampai terbukti dimatikan.
    const serialEnabled = computed(() => publicSettings.value.modules?.elektronik_enabled ?? true);

    // Actions
    const fetchPublicSettings = async () => {
        if (loading.value) return;

        loading.value = true;
        try {
            const response = await settingsApi.getPublic();
            if (response.data.success) {
                // Backend returns { store: {...}, currency: {...}, regional: {...} }
                publicSettings.value = response.data.data;
                loaded.value = true;

                // Cache to localStorage for instant load on next visit
                saveCachedSettings(response.data.data);

                // Update favicon if icon is set
                updateFavicon();
                // Update page title
                updatePageTitle();
            }
        } catch (error) {
            console.error('Failed to fetch public settings:', error);
        } finally {
            loading.value = false;
        }
    };

    const updateFavicon = () => {
        const icon = storeIcon.value || '/favicon.ico';
        let link = document.querySelector("link[rel~='icon']");
        if (!link) {
            link = document.createElement('link');
            link.rel = 'icon';
            document.head.appendChild(link);
        }
        link.href = icon;
    };

    const updatePageTitle = () => {
        const name = storeName.value;
        if (name) {
            document.title = name;
        }
    };

    // Refresh settings (called after settings are updated)
    const refresh = async () => {
        loaded.value = false;
        await fetchPublicSettings();
    };

    // Apply cached settings on initialization
    const initFromCache = () => {
        updateFavicon();
        updatePageTitle();
    };

    // Call init immediately
    initFromCache();

    return {
        // State
        publicSettings,
        loaded,
        loading,

        // Getters - Store
        store,
        storeName,
        storeLogo,
        storeIcon,
        loginBackground,

        // Getters - Formatting
        currency,
        regional,
        text,
        number,

        // Getters - Business Logic
        tax,
        rounding,
        product,
        promo,
        stock,
        calculation,
        serialEnabled,

        // Actions
        fetchPublicSettings,
        updateFavicon,
        updatePageTitle,
        refresh
    };
});

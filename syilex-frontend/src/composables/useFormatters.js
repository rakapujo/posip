/**
 * Composable for formatting values and business logic based on global settings
 * Uses settings from the settings store
 */
import { computed } from 'vue';
import { useSettingsStore } from '@/stores/settings';

export function useFormatters() {
    const settingsStore = useSettingsStore();

    // Get all settings
    const storeSettings = computed(() => settingsStore.store);
    const currencySettings = computed(() => settingsStore.currency);
    const regionalSettings = computed(() => settingsStore.regional);
    const textSettings = computed(() => settingsStore.text);
    const numberSettings = computed(() => settingsStore.number);
    const taxSettings = computed(() => settingsStore.tax);
    const roundingSettings = computed(() => settingsStore.rounding);
    const productSettings = computed(() => settingsStore.product);
    const promoSettings = computed(() => settingsStore.promo);
    const stockSettings = computed(() => settingsStore.stock);
    const calculationSettings = computed(() => settingsStore.calculation);

    /**
     * Format number as currency
     * @param {number} value - The value to format
     * @param {object} options - Override options
     * @returns {string} Formatted currency string
     */
    const formatCurrency = (value, options = {}) => {
        if (value === null || value === undefined) return '-';

        const settings = currencySettings.value;
        const decimalPlaces = options.decimalPlaces ?? settings.decimalPlaces;

        // Format number with separators
        const parts = Number(value).toFixed(decimalPlaces).split('.');
        const integerPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, settings.thousandSeparator);
        const decimalPart = parts[1];

        let formatted = decimalPart ? `${integerPart}${settings.decimalSeparator}${decimalPart}` : integerPart;

        // Add symbol
        if (settings.position === 'before') {
            formatted = `${settings.symbol}${formatted}`;
        } else {
            formatted = `${formatted}${settings.symbol}`;
        }

        return formatted;
    };

    /**
     * Format number with thousand separators (no currency symbol)
     * @param {number} value - The value to format
     * @returns {string} Formatted number string
     */
    const formatNumber = (value) => {
        if (value === null || value === undefined) return '-';

        const settings = currencySettings.value;
        const parts = Number(value).toFixed(0).split('.');
        return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, settings.thousandSeparator);
    };

    /**
     * Format quantity value with proper decimal places
     * @param {number} value - The value to format
     * @returns {string} Formatted quantity string
     */
    const formatQty = (value) => {
        if (value === null || value === undefined) return '-';

        const currency = currencySettings.value;
        const number = numberSettings.value;
        const decimalPlaces = number?.qtyDecimalPlaces ?? 0;

        const parts = Number(value).toFixed(decimalPlaces).split('.');
        const integerPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, currency.thousandSeparator);
        const decimalPart = parts[1];

        return decimalPart && decimalPlaces > 0 ? `${integerPart}${currency.decimalSeparator}${decimalPart}` : integerPart;
    };

    /**
     * Format percentage value
     * @param {number} value - The value to format
     * @returns {string} Formatted percentage string
     */
    const formatPercent = (value) => {
        if (value === null || value === undefined) return '-';

        const currency = currencySettings.value;
        const number = numberSettings.value;
        const decimalPlaces = number?.percentDecimalPlaces ?? 2;

        const parts = Number(value).toFixed(decimalPlaces).split('.');
        const integerPart = parts[0];
        const decimalPart = parts[1];

        const formatted = decimalPart && decimalPlaces > 0 ? `${integerPart}${currency.decimalSeparator}${decimalPart}` : integerPart;

        return `${formatted}%`;
    };

    /**
     * Get min fraction digits for qty InputNumber
     * @returns {number}
     */
    const getQtyMinFractionDigits = computed(() => numberSettings.value?.qtyDecimalPlaces ?? 0);

    /**
     * Get max fraction digits for qty InputNumber
     * @returns {number}
     */
    const getQtyMaxFractionDigits = computed(() => numberSettings.value?.qtyDecimalPlaces ?? 0);

    /**
     * Get min fraction digits for percent InputNumber
     * @returns {number}
     */
    const getPercentMinFractionDigits = computed(() => numberSettings.value?.percentDecimalPlaces ?? 2);

    /**
     * Get max fraction digits for percent InputNumber
     * @returns {number}
     */
    const getPercentMaxFractionDigits = computed(() => numberSettings.value?.percentDecimalPlaces ?? 2);

    /**
     * Get min fraction digits for currency InputNumber
     * @returns {number}
     */
    const getCurrencyMinFractionDigits = computed(() => currencySettings.value?.decimalPlaces ?? 0);

    /**
     * Get max fraction digits for currency InputNumber
     * @returns {number}
     */
    const getCurrencyMaxFractionDigits = computed(() => currencySettings.value?.decimalPlaces ?? 0);

    /**
     * Format number with currency symbol for short display (e.g., error messages)
     * @param {number} value - The value to format
     * @returns {string} Formatted short currency string
     */
    const formatCurrencyShort = (value) => {
        if (value === null || value === undefined) return '-';

        const settings = currencySettings.value;
        const formatted = formatNumber(value);

        if (settings.position === 'before') {
            return `${settings.symbol}${formatted}`;
        }
        return `${formatted}${settings.symbol}`;
    };

    /**
     * Check if text should be uppercase
     * @returns {boolean}
     */
    const shouldUppercase = computed(() => textSettings.value.uppercaseMode === 'all');

    /**
     * Get locale string based on separator settings (for InputNumber)
     * Determines locale from thousand/decimal separator configuration
     *
     * Supported combinations:
     * - "." thousand + "," decimal = id-ID (Indonesian)
     * - "," thousand + "." decimal = en-US (American)
     * - " " thousand + "," decimal = fr-FR (French)
     * - "'" thousand + "." decimal = de-CH (Swiss)
     *
     * For other combinations, fallback based on decimal separator
     * @returns {string}
     */
    const getLocale = computed(() => {
        const thousandSep = currencySettings.value.thousandSeparator;
        const decimalSep = currencySettings.value.decimalSeparator;

        // Exact matches for standard locale combinations
        if (thousandSep === '.' && decimalSep === ',') {
            return 'id-ID'; // Indonesian: 1.000.000,00
        }
        if (thousandSep === ',' && decimalSep === '.') {
            return 'en-US'; // American: 1,000,000.00
        }
        if (thousandSep === ' ' && decimalSep === ',') {
            return 'fr-FR'; // French: 1 000 000,00
        }
        if (thousandSep === "'" && decimalSep === '.') {
            return 'de-CH'; // Swiss: 1'000'000.00
        }

        // Fallback: choose locale based on decimal separator
        // This ensures decimal input works correctly
        if (decimalSep === ',') {
            return 'id-ID'; // Use comma as decimal
        }
        if (decimalSep === '.') {
            return 'en-US'; // Use dot as decimal
        }

        // Ultimate fallback
        return 'id-ID';
    });

    /**
     * Get locale string for date formatting (based on timezone)
     * @returns {string}
     */
    const getDateLocale = computed(() => {
        const timezone = regionalSettings.value.timezone;

        // Map timezone to locale for date formatting
        const timezoneLocaleMap = {
            'Asia/Jakarta': 'id-ID',
            'Asia/Makassar': 'id-ID',
            'Asia/Jayapura': 'id-ID',
            'Asia/Singapore': 'en-SG',
            'Asia/Kuala_Lumpur': 'ms-MY',
            'America/New_York': 'en-US',
            'America/Los_Angeles': 'en-US',
            'Europe/London': 'en-GB',
            'Europe/Paris': 'fr-FR',
            'Europe/Berlin': 'de-DE',
            'Asia/Tokyo': 'ja-JP'
        };

        return timezoneLocaleMap[timezone] || 'id-ID';
    });

    /**
     * Get timezone from settings
     * @returns {string}
     */
    const getTimezone = computed(() => regionalSettings.value.timezone || 'Asia/Jakarta');

    /**
     * Format date for display (readable with month name, order follows regional settings)
     * @param {string|Date} date - Date to format
     * @returns {string} Formatted date string (e.g., "25 Jan 2026")
     */
    const formatDate = (date) => {
        if (!date) return '-';

        const d = new Date(date);
        const format = regionalSettings.value.dateFormat;
        const timezone = getTimezone.value;
        const locale = getDateLocale.value;

        // Use Intl.DateTimeFormat with timezone and short month name for readable display
        const options = { timeZone: timezone, year: 'numeric', month: 'short', day: '2-digit' };
        const parts = new Intl.DateTimeFormat(locale, options).formatToParts(d);
        const year = parts.find((p) => p.type === 'year').value;
        const month = parts.find((p) => p.type === 'month').value;
        const day = parts.find((p) => p.type === 'day').value;

        // Order follows regional settings but uses readable month name
        switch (format) {
            case 'DD/MM/YYYY':
                return `${day} ${month} ${year}`;
            case 'MM/DD/YYYY':
                return `${month} ${day}, ${year}`;
            case 'YYYY-MM-DD':
                return `${year} ${month} ${day}`;
            default:
                return `${day} ${month} ${year}`;
        }
    };

    /**
     * Format time based on regional settings
     * @param {string|Date} date - Date/time to format
     * @returns {string} Formatted time string
     */
    const formatTime = (date) => {
        if (!date) return '-';

        const d = new Date(date);
        const format = regionalSettings.value.timeFormat;
        const timezone = getTimezone.value;

        // Use Intl.DateTimeFormat with timezone for accurate conversion
        const options = { timeZone: timezone, hour: '2-digit', minute: '2-digit', hour12: false };
        const parts = new Intl.DateTimeFormat('en-GB', options).formatToParts(d);
        const hours24 = parseInt(parts.find((p) => p.type === 'hour').value);
        const minutes = parts.find((p) => p.type === 'minute').value;
        const hours12 = hours24 % 12 || 12;
        const ampm = hours24 >= 12 ? 'PM' : 'AM';

        switch (format) {
            case 'HH:mm':
                return `${String(hours24).padStart(2, '0')}:${minutes}`;
            case 'hh:mm A':
                return `${String(hours12).padStart(2, '0')}:${minutes} ${ampm}`;
            default:
                return `${String(hours24).padStart(2, '0')}:${minutes}`;
        }
    };

    /**
     * Format datetime based on regional settings
     * @param {string|Date} date - Date/time to format
     * @returns {string} Formatted datetime string
     */
    const formatDateTime = (date) => {
        if (!date) return '-';
        return `${formatDate(date)} ${formatTime(date)}`;
    };

    /**
     * Get date format for PrimeVue DatePicker component
     * Converts settings format (DD/MM/YYYY) to PrimeVue format (dd/mm/yy)
     * @returns {string}
     */
    const getPrimeDateFormat = computed(() => {
        const format = regionalSettings.value.dateFormat;
        switch (format) {
            case 'DD/MM/YYYY':
                return 'dd/mm/yy';
            case 'MM/DD/YYYY':
                return 'mm/dd/yy';
            case 'YYYY-MM-DD':
                return 'yy-mm-dd';
            default:
                return 'dd/mm/yy';
        }
    });

    /**
     * Get date format with month name for PrimeVue DatePicker
     * Used for display-friendly format (e.g., "12 Jan 2026")
     * @returns {string}
     */
    const getPrimeDateFormatShort = computed(() => {
        const format = regionalSettings.value.dateFormat;
        switch (format) {
            case 'DD/MM/YYYY':
                return 'dd M yy';
            case 'MM/DD/YYYY':
                return 'M dd yy';
            case 'YYYY-MM-DD':
                return 'yy M dd';
            default:
                return 'dd M yy';
        }
    });

    /**
     * Convert date to YYYY-MM-DD string for API requests (no timezone shift)
     * Use this instead of .toISOString().split('T')[0] which causes timezone issues
     * @param {Date|string} date - Date to convert
     * @returns {string} Date in YYYY-MM-DD format
     */
    const toDateString = (date) => {
        if (!date) return null;

        const d = date instanceof Date ? date : new Date(date);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    };

    /**
     * Convert date to YYYY-MM-DD HH:mm:ss string for API requests
     * Use this for datetime fields that need to preserve time
     * @param {Date|string} date - Date to convert
     * @returns {string} Date in YYYY-MM-DD HH:mm:ss format
     */
    const toDateTimeString = (date) => {
        if (!date) return null;

        const d = date instanceof Date ? date : new Date(date);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hours = String(d.getHours()).padStart(2, '0');
        const minutes = String(d.getMinutes()).padStart(2, '0');
        const seconds = String(d.getSeconds()).padStart(2, '0');

        return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    };

    /**
     * Get current datetime as Date object
     * Use this instead of new Date() for consistency
     * @returns {Date}
     */
    const now = () => new Date();

    /**
     * Parse datetime string from API to Date object
     * API returns format: "2026-01-25 12:00:00"
     * @param {string} dateString - Date string from API
     * @returns {Date|null}
     */
    const parseDateTime = (dateString) => {
        if (!dateString) return null;
        return new Date(dateString);
    };

    /**
     * Check if a date is after current time
     * @param {Date|string} date - Date to check
     * @returns {boolean}
     */
    const isAfterNow = (date) => {
        if (!date) return false;
        const d = date instanceof Date ? date : new Date(date);
        return d > new Date();
    };

    /**
     * Check if a date is before current time
     * @param {Date|string} date - Date to check
     * @returns {boolean}
     */
    const isBeforeNow = (date) => {
        if (!date) return false;
        const d = date instanceof Date ? date : new Date(date);
        return d < new Date();
    };

    /**
     * Get today's date as string for filenames
     * Format: "2026-01-25"
     * @returns {string}
     */
    const todayString = () => {
        return toDateString(new Date());
    };

    // =========================================================================
    // ROUNDING HELPERS
    // =========================================================================

    /**
     * Apply rounding for sales
     * @param {number} value
     * @returns {number}
     */
    const roundSales = (value) => {
        return applyRounding(value, roundingSettings.value.salesMethod, roundingSettings.value.salesPrecision);
    };

    /**
     * Generic rounding function
     * @param {number} value
     * @param {string} method - 'none' | 'round' | 'floor' | 'ceil'
     * @param {number} precision - 1, 10, 100, 1000
     * @returns {number}
     */
    const applyRounding = (value, method, precision) => {
        if (method === 'none' || precision === 0 || precision === 1) {
            return value;
        }
        switch (method) {
            case 'round':
                return Math.round(value / precision) * precision;
            case 'floor':
                return Math.floor(value / precision) * precision;
            case 'ceil':
                return Math.ceil(value / precision) * precision;
            default:
                return value;
        }
    };

    // =========================================================================
    // UNIT HELPERS
    // =========================================================================

    /**
     * Get unique units from product data (sorted by konversi descending)
     * @param {object} product - Product object with unit_1-4 and konversi_1-4
     * @returns {Array} Array of unique units with { unit, konversi }
     */
    const getUniqueUnits = (product) => {
        if (!product) return [];

        // Collect all units with their konversi values
        const units = [];
        for (let i = 1; i <= 4; i++) {
            const unit = product[`unit_${i}`];
            const konversi = product[`konversi_${i}`];
            if (unit && konversi != null) {
                units.push({ unit, konversi: Number(konversi) });
            }
        }

        // Filter unique units by konversi value (higher konversi = larger unit)
        // If two units have the same konversi, they are considered identical
        const seen = new Map();
        const uniqueUnits = [];
        for (const u of units) {
            if (!seen.has(u.konversi)) {
                seen.set(u.konversi, u.unit);
                uniqueUnits.push(u);
            }
        }

        // Sort by konversi descending (largest unit first)
        return uniqueUnits.sort((a, b) => b.konversi - a.konversi);
    };

    /**
     * Format unit hierarchy display
     * Example: "1 KRT = 10 BOX = 100 PCS"
     * @param {object} product - Product object with unit_1-4 and konversi_1-4
     * @returns {string} Formatted hierarchy string
     */
    const formatUnitHierarchy = (product) => {
        const units = getUniqueUnits(product);
        if (units.length === 0) return '-';
        if (units.length === 1) return `1 ${units[0].unit}`;

        // Build hierarchy string: "1 KRT = 10 BOX = 100 PCS"
        return units.map((u) => `${u.konversi} ${u.unit}`).join(' = ');
    };

    /**
     * Format stock breakdown by units
     * Example: qty=237, konversi KRT=100, BOX=10, PCS=1 → "2 KRT | 3 BOX | 7 PCS"
     * @param {number} qty - Stock quantity in base unit
     * @param {object} product - Product object with unit_1-4 and konversi_1-4
     * @param {string} separator - Separator between units (default: ' | ')
     * @returns {string} Formatted breakdown string
     */
    const formatStockBreakdown = (qty, product, separator = ' | ') => {
        if (qty == null || !product) return '-';

        const units = getUniqueUnits(product);
        if (units.length === 0) return String(qty);
        if (units.length === 1) return `${qty} ${units[0].unit}`;

        // Calculate breakdown
        let remaining = Math.abs(qty);
        const parts = [];

        for (const u of units) {
            const count = Math.floor(remaining / u.konversi);
            remaining = remaining % u.konversi;
            parts.push(`${count} ${u.unit}`);
        }

        // Add negative sign if qty was negative
        const result = parts.join(separator);
        return qty < 0 ? `-${result}` : result;
    };

    // =========================================================================
    // PRODUCT HELPERS
    // =========================================================================

    /**
     * Check if price input mode is auto
     * @returns {boolean}
     */
    const isPriceInputAuto = computed(() => productSettings.value.priceInputMode === 'auto');

    return {
        // Currency formatting
        formatCurrency,
        formatNumber,
        formatCurrencyShort,
        getLocale,

        // Date/Time formatting
        getDateLocale,
        getTimezone,
        formatDate,
        formatTime,
        formatDateTime,
        toDateString,
        toDateTimeString,
        now,
        parseDateTime,
        isAfterNow,
        isBeforeNow,
        todayString,
        getPrimeDateFormat,
        getPrimeDateFormatShort,

        // Quantity/Number formatting
        formatQty,
        formatPercent,
        getQtyMinFractionDigits,
        getQtyMaxFractionDigits,
        getPercentMinFractionDigits,
        getPercentMaxFractionDigits,
        getCurrencyMinFractionDigits,
        getCurrencyMaxFractionDigits,

        // Text formatting
        shouldUppercase,

        // Rounding helpers
        roundSales,

        // Unit helpers
        getUniqueUnits,
        formatUnitHierarchy,
        formatStockBreakdown,

        // Product helpers
        isPriceInputAuto,

        // Raw settings (for advanced use)
        storeSettings,
        currencySettings,
        regionalSettings,
        numberSettings,
        textSettings,
        taxSettings,
        roundingSettings,
        productSettings,
        promoSettings,
        stockSettings,
        calculationSettings
    };
}

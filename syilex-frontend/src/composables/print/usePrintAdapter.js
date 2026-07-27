import { ref, computed } from 'vue';
import { getStoredPrinter } from './printStorage.js';
import { checkStatusCore, printRawCore } from './printAdapterCore.js';
import { getActiveConnection, isThermalSupported, supportMatrix } from './printTransportCore.js';
import { usePrintTransport } from './usePrintTransport.js';

/**
 * Facade for browser thermal printing (Web Serial / WebUSB / Bluetooth).
 */
export function usePrintAdapter() {
    const transport = usePrintTransport();

    const isAvailable = ref(false);
    const busy = ref(false);
    const error = ref(null);

    const supported = computed(() => isThermalSupported());
    const support = computed(() => supportMatrix());
    const printerLabel = computed(() => transport.printerLabel.value || getStoredPrinter()?.label || null);

    async function checkStatus() {
        const ok = await checkStatusCore();
        isAvailable.value = ok;
        return ok;
    }

    /** True when paired or live-connected (not merely browser API support). */
    function isReadyToThermal() {
        return !!(getActiveConnection() || getStoredPrinter()?.kind);
    }

    async function pick(kind, opts) {
        return transport.pick(kind, opts);
    }

    async function reconnect() {
        return transport.reconnect();
    }

    function forget() {
        transport.forget();
    }

    /**
     * @param {string} base64Data
     * @param {Object} [opts]
     * @param {boolean} [opts.openDrawer] — unused here; drawer bytes belong in ESC/POS payload
     */
    async function printRaw(base64Data, opts = {}) {
        busy.value = true;
        error.value = null;
        try {
            const result = await printRawCore(base64Data, {
                writeFn: (bytes) => transport.write(bytes),
                reconnectFn: () => transport.reconnect()
            });

            if (!result.ok) {
                error.value = result.error || 'Cetak gagal';
            }
            return {
                success: result.ok,
                needPicker: result.needPicker || false,
                message: result.error
            };
        } finally {
            busy.value = false;
        }
    }

    return {
        isAvailable,
        busy,
        error,
        supported,
        support,
        printerLabel,
        checkStatus,
        isReadyToThermal,
        pick,
        reconnect,
        forget,
        printRaw,
        transport
    };
}

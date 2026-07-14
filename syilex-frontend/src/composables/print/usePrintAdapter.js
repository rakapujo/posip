import { ref, computed } from 'vue';
import { usePrintService } from '@/composables/usePrintService';
import { getStoredPrinter } from './printStorage.js';
import { checkStatusCore, printRawCore } from './printAdapterCore.js';
import { isThermalSupported, supportMatrix } from './printTransportCore.js';
import { usePrintTransport } from './usePrintTransport.js';

/**
 * Facade for thermal printing — browser transport primary, legacy Python optional.
 * API mirrors usePrintService for easier migration.
 */
export function usePrintAdapter() {
    const legacy = usePrintService();
    const transport = usePrintTransport();

    const isAvailable = ref(false);
    const busy = ref(false);
    const error = ref(null);

    const supported = computed(() => isThermalSupported());
    const support = computed(() => supportMatrix());
    const printerLabel = computed(() => transport.printerLabel.value || getStoredPrinter()?.label || null);

    async function checkStatus() {
        const ok = await checkStatusCore(typeof navigator !== 'undefined' ? navigator : undefined, legacy);
        isAvailable.value = ok;
        return ok;
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
     * @param {boolean} [opts.openDrawer]
     * @param {string} [opts.legacyPrinterId] — terminal default_printer for legacy bridge
     */
    async function printRaw(base64Data, opts = {}) {
        const { openDrawer = false, legacyPrinterId } = opts;
        busy.value = true;
        error.value = null;
        try {
            const result = await printRawCore(base64Data, {
                openDrawer,
                legacyPrinterId,
                legacy,
                writeFn: (bytes) => transport.write(bytes),
                reconnectFn: () => transport.reconnect()
            });

            if (!result.ok) {
                error.value = result.error || 'Cetak gagal';
            }
            return {
                success: result.ok,
                needPicker: result.needPicker || false,
                message: result.error,
                legacyUsed: result.legacyUsed || false
            };
        } finally {
            busy.value = false;
        }
    }

    /** @deprecated Browser transport uses pairing — returns label for UI compat */
    async function getPrinters() {
        const stored = getStoredPrinter();
        if (stored?.label) {
            return [{ id: stored.kind, name: stored.label }];
        }
        if (transport.printerLabel.value) {
            return [{ id: getStoredPrinter()?.kind || 'browser', name: transport.printerLabel.value }];
        }
        return [];
    }

    return {
        isAvailable,
        busy,
        error,
        supported,
        support,
        printerLabel,
        checkStatus,
        pick,
        reconnect,
        forget,
        printRaw,
        getPrinters,
        transport
    };
}

import { ref } from 'vue';

const PRINT_SERVICE_URL = 'http://localhost:5123';
const TIMEOUT = 3000;
const PRINT_TIMEOUT = 15000;

/**
 * Composable for communicating with POSIP Print Service (Python thin proxy).
 * Sends raw ESC/POS bytes to the printer service.
 * ESC/POS generation is handled by useReceiptEscPos.js.
 */
export function usePrintService() {
    const isAvailable = ref(false);
    const printers = ref([]);

    async function _post(endpoint, body) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), PRINT_TIMEOUT);
        try {
            const res = await fetch(`${PRINT_SERVICE_URL}${endpoint}`, {
                method: 'POST',
                signal: controller.signal,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            clearTimeout(timeoutId);
            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                return { success: false, message: err.message || 'Print service error' };
            }
            return await res.json();
        } catch (e) {
            clearTimeout(timeoutId);
            return { success: false, message: e.name === 'AbortError' ? 'Print timeout' : e.message || 'Print service unavailable' };
        }
    }

    /**
     * Check if print service is running.
     */
    async function checkStatus() {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), TIMEOUT);
            const res = await fetch(`${PRINT_SERVICE_URL}/status`, { signal: controller.signal });
            clearTimeout(timeoutId);
            const data = await res.json();
            isAvailable.value = data.status === 'ok';
            return isAvailable.value;
        } catch {
            isAvailable.value = false;
            return false;
        }
    }

    /**
     * Get list of available printers (USB + network + Windows).
     */
    async function getPrinters() {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), TIMEOUT);
            const res = await fetch(`${PRINT_SERVICE_URL}/printers`, { signal: controller.signal });
            clearTimeout(timeoutId);
            const data = await res.json();
            printers.value = data.all || [];
            return printers.value;
        } catch {
            printers.value = [];
            return [];
        }
    }

    /**
     * Send raw ESC/POS bytes to a printer.
     * @param {string} printer - Printer ID (e.g. "NET:192.168.1.100:9100")
     * @param {string} base64Data - Base64-encoded ESC/POS bytes (from useReceiptEscPos)
     * @param {boolean} openDrawer - Whether to open cash drawer after printing
     */
    async function printRaw(printer, base64Data, openDrawer = false) {
        return _post('/print/raw', { printer, data: base64Data, open_drawer: openDrawer });
    }

    /**
     * Open cash drawer.
     * @param {string} printer - Printer ID
     */
    async function openDrawer(printer) {
        return _post('/drawer/open', { printer });
    }

    /**
     * Send a test print.
     * @param {string} printer - Printer ID
     * @param {string} base64Data - Base64-encoded test page bytes
     */
    async function testPrint(printer, base64Data) {
        return _post('/print/raw', { printer, data: base64Data });
    }

    return {
        isAvailable,
        printers,
        checkStatus,
        getPrinters,
        printRaw,
        openDrawer,
        testPrint
    };
}

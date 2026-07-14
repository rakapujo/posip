import { ref, computed } from 'vue';
import { clearStoredPrinter, getStoredPrinter, setStoredPrinter } from './printStorage.js';
import {
    connectByKind,
    forgetPrinter as coreForget,
    getActiveConnection,
    isThermalSupported as coreIsSupported,
    supportMatrix as coreSupportMatrix,
    trySilentReconnect as coreSilentReconnect
} from './printTransportCore.js';

/**
 * Vue composable — browser ESC/POS transport.
 */
export function usePrintTransport() {
    const connection = ref(getActiveConnection());
    const lastError = ref(null);

    const supported = computed(() => coreIsSupported());
    const support = computed(() => coreSupportMatrix());
    const printerLabel = computed(() => connection.value?.label || getStoredPrinter()?.label || null);
    const isConnected = computed(() => !!connection.value);

    function syncConnection() {
        connection.value = getActiveConnection();
    }

    async function pick(kind, { terminalUlid, label } = {}) {
        lastError.value = null;
        try {
            const conn = await connectByKind(kind);
            setStoredPrinter({
                kind,
                terminalUlid,
                label: label || conn.label
            });
            connection.value = conn;
            return conn;
        } catch (e) {
            lastError.value = e?.message || 'Gagal menghubungkan printer';
            throw e;
        }
    }

    async function reconnect() {
        lastError.value = null;
        const stored = getStoredPrinter();
        const conn = await coreSilentReconnect(stored?.kind ?? null);
        connection.value = conn;
        return conn;
    }

    function forget() {
        coreForget(getStoredPrinter(), clearStoredPrinter);
        connection.value = null;
        lastError.value = null;
    }

    async function write(bytes) {
        const conn = connection.value || (await reconnect());
        if (!conn) {
            throw new Error('Printer belum dipasangkan');
        }
        await conn.write(bytes);
    }

    return {
        connection,
        lastError,
        supported,
        support,
        printerLabel,
        isConnected,
        pick,
        reconnect,
        forget,
        write,
        syncConnection
    };
}

export { coreSupportMatrix as supportMatrix, coreIsSupported as isThermalSupported };

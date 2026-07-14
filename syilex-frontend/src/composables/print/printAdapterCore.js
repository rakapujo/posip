/**
 * Print adapter core — decode ESC/POS, reconnect, write, optional legacy fallback.
 */

import { base64ToBytes } from './base64Bytes.js';
import { getStoredPrinter } from './printStorage.js';
import { getActiveConnection, isThermalSupported, trySilentReconnect } from './printTransportCore.js';

/** Set false to disable Python localhost:5123 bridge entirely. */
export const ENABLE_LEGACY_PRINT_SERVICE = true;

let legacyWarned = false;

/**
 * @typedef {Object} PrintResult
 * @property {boolean} ok
 * @property {boolean} [needPicker]
 * @property {string} [error]
 * @property {boolean} [legacyUsed]
 */

/**
 * @typedef {Object} LegacyPrintService
 * @property {() => Promise<boolean>} checkStatus
 * @property {(printer: string, b64: string, openDrawer?: boolean) => Promise<{ success: boolean, message?: string }>} printRaw
 */

/**
 * @param {string} base64Data
 * @param {Object} [options]
 * @param {boolean} [options.openDrawer] — drawer should already be in payload from encoder; kept for legacy bridge
 * @param {string} [options.legacyPrinterId] — WIN:/NET: id for Python service
 * @param {LegacyPrintService} [options.legacy]
 * @param {() => Promise<void>} [options.writeFn]
 * @param {() => Promise<import('./printTransportCore.js').PrinterConnection | null>} [options.reconnectFn]
 * @returns {Promise<PrintResult>}
 */
export async function printRawCore(base64Data, options = {}) {
    const { openDrawer = false, legacyPrinterId, legacy, writeFn, reconnectFn } = options;

    if (!base64Data) {
        return { ok: false, error: 'Data cetak kosong' };
    }

    let bytes;
    try {
        bytes = base64ToBytes(base64Data);
    } catch {
        return { ok: false, error: 'Data base64 tidak valid' };
    }

    if (!bytes.length) {
        return { ok: false, error: 'Payload ESC/POS kosong' };
    }

    const stored = getStoredPrinter();
    let conn = getActiveConnection();
    if (!conn) {
        const reconnect = reconnectFn || (() => trySilentReconnect(stored?.kind ?? null));
        conn = await reconnect();
    }

    if (conn) {
        try {
            const write = writeFn || ((data) => conn.write(data));
            await write(bytes);
            return { ok: true };
        } catch (e) {
            const msg = e?.message || 'Gagal mengirim ke printer';
            if (ENABLE_LEGACY_PRINT_SERVICE && legacyPrinterId && legacy) {
                const legacyResult = await tryLegacyPrint(legacy, legacyPrinterId, base64Data, openDrawer);
                if (legacyResult.ok) return legacyResult;
            }
            return { ok: false, error: msg };
        }
    }

    // No browser connection — try legacy if configured
    if (ENABLE_LEGACY_PRINT_SERVICE && legacyPrinterId && legacy) {
        const legacyResult = await tryLegacyPrint(legacy, legacyPrinterId, base64Data, openDrawer);
        if (legacyResult.ok) return legacyResult;
    }

    if (stored?.kind) {
        return { ok: false, needPicker: true, error: 'Printer perlu disambungkan ulang' };
    }

    if (ENABLE_LEGACY_PRINT_SERVICE && legacyPrinterId) {
        return { ok: false, needPicker: true, error: 'Pasangkan printer di pengaturan terminal atau jalankan Print Service legacy' };
    }

    return { ok: false, needPicker: true, error: 'Printer thermal belum dipasangkan' };
}

/**
 * @param {LegacyPrintService} legacy
 * @param {string} printerId
 * @param {string} base64Data
 * @param {boolean} openDrawer
 * @returns {Promise<PrintResult>}
 */
async function tryLegacyPrint(legacy, printerId, base64Data, openDrawer) {
    if (!legacyWarned) {
        console.warn('[POSIP] Legacy Print Service (:5123) is deprecated. Pair printer via browser transport.');
        legacyWarned = true;
    }
    try {
        const available = await legacy.checkStatus();
        if (!available) {
            return { ok: false, error: 'Print service legacy tidak tersedia' };
        }
        const result = await legacy.printRaw(printerId, base64Data, openDrawer);
        if (result.success) {
            return { ok: true, legacyUsed: true };
        }
        return { ok: false, error: result.message || 'Legacy print gagal' };
    } catch (e) {
        return { ok: false, error: e?.message || 'Legacy print gagal' };
    }
}

/**
 * @param {Navigator} [nav]
 * @param {LegacyPrintService} [legacy]
 * @returns {Promise<boolean>}
 */
export async function checkStatusCore(nav, legacy) {
    if (isThermalSupported(nav)) return true;
    if (ENABLE_LEGACY_PRINT_SERVICE && legacy) {
        try {
            return await legacy.checkStatus();
        } catch {
            return false;
        }
    }
    return false;
}

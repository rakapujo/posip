/**
 * Print adapter core — decode ESC/POS, reconnect, write via browser transport.
 */

import { base64ToBytes } from './base64Bytes.js';
import { getStoredPrinter } from './printStorage.js';
import { getActiveConnection, trySilentReconnect } from './printTransportCore.js';

/**
 * @typedef {Object} PrintResult
 * @property {boolean} ok
 * @property {boolean} [needPicker]
 * @property {string} [error]
 */

/**
 * @param {string} base64Data
 * @param {Object} [options]
 * @param {() => Promise<void>} [options.writeFn]
 * @param {() => Promise<import('./printTransportCore.js').PrinterConnection | null>} [options.reconnectFn]
 * @returns {Promise<PrintResult>}
 */
export async function printRawCore(base64Data, options = {}) {
    const { writeFn, reconnectFn } = options;

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
            return { ok: false, error: e?.message || 'Gagal mengirim ke printer' };
        }
    }

    if (stored?.kind) {
        return { ok: false, needPicker: true, error: 'Printer perlu disambungkan ulang' };
    }

    return { ok: false, needPicker: true, error: 'Printer thermal belum dipasangkan' };
}

/**
 * @returns {Promise<boolean>}
 */
export async function checkStatusCore() {
    if (getActiveConnection()) return true;
    if (getStoredPrinter()) return true;
    return false;
}

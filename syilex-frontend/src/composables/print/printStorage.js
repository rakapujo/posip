/** @typedef {'bluetooth' | 'serial' | 'usb'} PrinterKind */

/** @typedef {{ kind: PrinterKind, terminalUlid?: string, label?: string }} StoredPrinter */

export const STORAGE_KEY = 'posip-thermal-printer';

const VALID_KINDS = new Set(['bluetooth', 'serial', 'usb']);

/**
 * @param {unknown} raw
 * @returns {StoredPrinter | null}
 */
export function parseStoredPrinter(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const kind = /** @type {StoredPrinter} */ (raw).kind;
    if (!VALID_KINDS.has(kind)) return null;
    const out = { kind };
    const terminalUlid = /** @type {StoredPrinter} */ (raw).terminalUlid;
    const label = /** @type {StoredPrinter} */ (raw).label;
    if (typeof terminalUlid === 'string' && terminalUlid.trim()) {
        out.terminalUlid = terminalUlid.trim();
    }
    if (typeof label === 'string' && label.trim()) {
        out.label = label.trim();
    }
    return out;
}

/**
 * @returns {StoredPrinter | null}
 */
export function getStoredPrinter() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        return parseStoredPrinter(JSON.parse(raw));
    } catch {
        return null;
    }
}

/**
 * @param {StoredPrinter} data
 */
export function setStoredPrinter(data) {
    if (!data?.kind || !VALID_KINDS.has(data.kind)) {
        throw new Error('Invalid printer kind');
    }
    const payload = { kind: data.kind };
    if (data.terminalUlid) payload.terminalUlid = data.terminalUlid;
    if (data.label) payload.label = data.label;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
}

export function clearStoredPrinter() {
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
        /* abaikan */
    }
}

/**
 * @param {string | undefined | null} terminalUlid
 * @returns {boolean}
 */
export function isStoredForTerminal(terminalUlid) {
    const stored = getStoredPrinter();
    if (!stored) return false;
    if (!terminalUlid) return true;
    if (!stored.terminalUlid) return true;
    return stored.terminalUlid === terminalUlid;
}

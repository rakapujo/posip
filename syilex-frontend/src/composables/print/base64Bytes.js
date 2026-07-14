/**
 * Base64 ↔ Uint8Array helpers for ESC/POS payloads.
 */

/**
 * @param {string} b64
 * @returns {Uint8Array}
 */
export function base64ToBytes(b64) {
    if (typeof b64 !== 'string' || !b64.length) {
        return new Uint8Array(0);
    }
    const trimmed = b64.trim();
    if (!trimmed.length) {
        return new Uint8Array(0);
    }
    const bin = atob(trimmed);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) {
        out[i] = bin.charCodeAt(i);
    }
    return out;
}

/**
 * @param {Uint8Array} bytes
 * @returns {string}
 */
export function bytesToBase64(bytes) {
    if (!bytes?.length) return '';
    let bin = '';
    for (let i = 0; i < bytes.length; i++) {
        bin += String.fromCharCode(bytes[i]);
    }
    return btoa(bin);
}

/**
 * Find last index of byte subsequence (for drawer-before-cut assertions).
 * @param {Uint8Array} haystack
 * @param {number[]} needle
 * @returns {number}
 */
export function lastIndexOfBytes(haystack, needle) {
    if (!needle.length || haystack.length < needle.length) return -1;
    outer: for (let i = haystack.length - needle.length; i >= 0; i--) {
        for (let j = 0; j < needle.length; j++) {
            if (haystack[i + j] !== needle[j]) continue outer;
        }
        return i;
    }
    return -1;
}

/** ESC/POS drawer pulse (pin 2) — same as useReceiptEscPos CMD.DRAWER_2 */
export const DRAWER_2_BYTES = [0x1b, 0x70, 0x00, 0x19, 0x19];

/** Partial cut */
export const CUT_BYTES = [0x1d, 0x56, 0x01];

/**
 * @param {Uint8Array} bytes
 * @returns {boolean}
 */
export function hasDrawerBeforeCut(bytes) {
    const drawerAt = lastIndexOfBytes(bytes, DRAWER_2_BYTES);
    const cutAt = lastIndexOfBytes(bytes, CUT_BYTES);
    return drawerAt >= 0 && cutAt >= 0 && drawerAt < cutAt;
}

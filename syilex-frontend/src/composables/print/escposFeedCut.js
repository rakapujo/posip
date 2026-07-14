import { CUT_BYTES, DRAWER_2_BYTES } from './base64Bytes.js';

/**
 * Build ESC/POS tail sequence: optional drawer → feed → cut (POSTITIK order).
 * @param {number} feedLines
 * @param {boolean} openDrawer
 * @returns {Uint8Array}
 */
export function buildFeedAndCutBytes(feedLines = 4, openDrawer = false) {
    /** @type {number[]} */
    const parts = [];
    if (openDrawer) parts.push(...DRAWER_2_BYTES);
    const feed = Math.min(Math.max(feedLines, 0), 10);
    if (feed > 0) parts.push(0x1b, 0x64, feed);
    parts.push(...CUT_BYTES);
    return new Uint8Array(parts);
}

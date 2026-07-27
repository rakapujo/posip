/**
 * Resolve POS document store branding.
 * Prefer non-empty terminal overrides; otherwise fall back to global settings.
 *
 * @param {Object|null|undefined} terminal - MasterPosTerminal-like (store_* snake_case)
 * @param {Object} global - settingsStore.store shape (camelCase receiptFooter)
 */
export function resolveStoreBranding(terminal = null, global = {}) {
    const pick = (override, fallback) => {
        const v = String(override ?? '').trim();
        return v !== '' ? v : (fallback ?? '');
    };

    return {
        name: pick(terminal?.store_name, global.name || 'POSIP'),
        address: pick(terminal?.store_address, global.address || ''),
        phone: pick(terminal?.store_phone, global.phone || ''),
        email: pick(terminal?.store_email, global.email || ''),
        npwp: pick(terminal?.store_npwp, global.npwp || ''),
        receiptFooter: pick(terminal?.receipt_footer, global.receiptFooter || '')
    };
}

/**
 * Normalize BE SettingService::getStoreInfo* payload (snake_case receipt_footer).
 */
export function normalizeStoreInfo(raw, fallback = {}) {
    if (!raw || typeof raw !== 'object') {
        return resolveStoreBranding(null, fallback);
    }
    return {
        name: String(raw.name || fallback.name || 'POSIP').trim() || 'POSIP',
        address: String(raw.address ?? fallback.address ?? ''),
        phone: String(raw.phone ?? fallback.phone ?? ''),
        email: String(raw.email ?? fallback.email ?? ''),
        npwp: String(raw.npwp ?? fallback.npwp ?? ''),
        receiptFooter: String(raw.receipt_footer ?? raw.receiptFooter ?? fallback.receiptFooter ?? '')
    };
}

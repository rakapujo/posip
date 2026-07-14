import client from '../client';

/**
 * Settings API module
 */
export const settingsApi = {
    /**
     * Get all settings (grouped)
     * @returns {Promise}
     */
    getAll: () => client.get('/settings'),

    /**
     * Get settings by group
     * @param {string} group
     * @returns {Promise}
     */
    getGroup: (group) => client.get(`/settings/group/${group}`),

    /**
     * Get single setting
     * @param {string} group
     * @param {string} key
     * @returns {Promise}
     */
    get: (group, key) => client.get(`/settings/${group}/${key}`),

    /**
     * Update single setting
     * @param {string} group
     * @param {string} key
     * @param {*} value
     * @param {string} [type]
     * @returns {Promise}
     */
    update: (group, key, value, type = null) => {
        const data = { value };
        if (type) data.type = type;
        return client.put(`/settings/${group}/${key}`, data);
    },

    /**
     * Update multiple settings in a group
     * @param {string} group
     * @param {Array} settings - Array of {key, value, type?}
     * @returns {Promise}
     */
    updateGroup: (group, settings) => client.put(`/settings/group/${group}`, { settings }),

    /**
     * Bulk update settings
     * @param {Object} settings - Object with 'group.key': value pairs
     * @returns {Promise}
     */
    bulkUpdate: (settings) => client.put('/settings/bulk', { settings }),

    /**
     * Get public settings (no auth required)
     * @returns {Promise}
     */
    getPublic: () => client.get('/settings/public'),

    /**
     * Check if price input mode is locked (products exist)
     * @returns {Promise} - { locked: boolean, product_count: number, message: string }
     */
    checkPriceModeLock: () => client.get('/settings/price-mode-lock'),

    /**
     * Check if stock negative mode is locked (stock card has records)
     * @returns {Promise} - { locked: boolean, stock_card_count: number, message: string }
     */
    checkStockModeLock: () => client.get('/settings/stock-mode-lock'),

    /**
     * Check if elektronik module can be disabled (locked when serial data exists)
     * @returns {Promise} - { locked, enabled, serial_products, serial_units, message }
     */
    checkElektronikLock: () => client.get('/settings/elektronik-lock'),

    /**
     * Get all document prefixes with info
     * @returns {Promise} - { prefixes: Array }
     */
    getPrefixes: () => client.get('/settings/prefixes'),

    /**
     * Update a single document prefix
     * @param {string} type - Document type (e.g., 'purchase_order')
     * @param {string} prefix - New prefix value
     * @returns {Promise}
     */
    updatePrefix: (type, prefix) => client.put(`/settings/prefixes/${type}`, { prefix }),

    /**
     * Get grouped timezone options for the regional dropdown.
     * Backend returns array of groups: [{ label, items: [{ label, value }] }]
     * @returns {Promise}
     */
    getTimezones: () => client.get('/settings/timezones')
};

export default settingsApi;

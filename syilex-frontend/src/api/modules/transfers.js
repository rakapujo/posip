import client from '../client';

/**
 * Transfers API module
 */
export const transfersApi = {
    /**
     * Get all transfers with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by nomor_dokumen, notes
     * @param {number} [params.warehouse_from_id] - Filter by source warehouse ID
     * @param {number} [params.warehouse_to_id] - Filter by destination warehouse ID
     * @param {string} [params.status] - Filter by status (draft, approved)
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/transfers', { params }),

    /**
     * Get a single transfer by ULID
     * @param {string} ulid - Transfer ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/transfers/${ulid}`),

    /**
     * Create a new transfer (draft)
     * @param {Object} data - Transfer data
     * @param {number} data.warehouse_from_id - Source warehouse ID
     * @param {number} data.warehouse_to_id - Destination warehouse ID
     * @param {string} data.tanggal - Date and time (ISO format)
     * @param {string} [data.notes] - Notes/description
     * @param {Array} data.details - Transfer details
     * @param {number} data.details[].product_id - Product ID
     * @param {number} data.details[].qty - Quantity
     * @returns {Promise}
     */
    create: (data) => client.post('/transfers', data),

    /**
     * Update an existing transfer (draft only)
     * @param {string} ulid - Transfer ULID
     * @param {Object} data - Transfer data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/transfers/${ulid}`, data),

    /**
     * Delete a transfer (draft only)
     * @param {string} ulid - Transfer ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/transfers/${ulid}`),

    /**
     * Approve a transfer
     * @param {string} ulid - Transfer ULID
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/transfers/${ulid}/approve`),

    /**
     * Get products with stock for autocomplete
     * @param {Object} params - Query parameters
     * @param {number} params.warehouse_from_id - Source warehouse ID (required)
     * @param {string} [params.search] - Search keyword
     * @returns {Promise}
     */
    getProducts: (params) => client.get('/transfers/products', { params }),

    /**
     * Get stock setting (negative stock allowed or not)
     * @returns {Promise}
     */
    getStockSetting: () => client.get('/transfers/stock-setting'),

    /**
     * Pattern summary — agregasi transfer approved per (warehouse_from, warehouse_to).
     * @param {Object} params
     * @returns {Promise}
     */
    getPatternSummary: (params = {}) => client.get('/transfers/pattern-summary', { params })
};

export default transfersApi;

import client from '../client';

/**
 * Stock Opname API module
 */
export const opnamesApi = {
    /**
     * Get all stock opnames with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by nomor_dokumen, notes
     * @param {number} [params.warehouse_id] - Filter by warehouse ID
     * @param {string} [params.status] - Filter by status (draft, approved, cancelled)
     * @param {string} [params.mode] - Filter by mode (full, partial)
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/opnames', { params }),

    /**
     * Get a single stock opname by ULID
     * @param {string} ulid - Stock opname ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/opnames/${ulid}`),

    /**
     * Create a new stock opname (draft)
     * @param {Object} data - Stock opname data
     * @param {number} data.warehouse_id - Warehouse ID
     * @param {string} data.tanggal_opname - Date and time (ISO format)
     * @param {string} data.mode - Mode (full or partial)
     * @param {string} [data.notes] - Notes/description
     * @param {Array} data.details - Opname details
     * @param {number} data.details[].product_id - Product ID
     * @param {number} data.details[].qty_physical - Physical quantity
     * @param {string} [data.details[].notes] - Item notes
     * @returns {Promise}
     */
    create: (data) => client.post('/opnames', data),

    /**
     * Update an existing stock opname (draft only)
     * @param {string} ulid - Stock opname ULID
     * @param {Object} data - Stock opname data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/opnames/${ulid}`, data),

    /**
     * Delete a stock opname (draft only)
     * @param {string} ulid - Stock opname ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/opnames/${ulid}`),

    /**
     * Approve a stock opname
     * @param {string} ulid - Stock opname ULID
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/opnames/${ulid}/approve`),

    /**
     * Get products with stock for autocomplete (partial mode)
     * @param {Object} params - Query parameters
     * @param {number} params.warehouse_id - Warehouse ID (required)
     * @param {string} [params.search] - Search keyword
     * @returns {Promise}
     */
    getProducts: (params) => client.get('/opnames/products', { params }),

    /**
     * Get all products with stock (full mode) - paginated
     * @param {Object} params - Query parameters
     * @param {number} params.warehouse_id - Warehouse ID (required)
     * @param {number} [params.page] - Page number
     * @param {number} [params.per_page] - Items per page (10-100)
     * @returns {Promise}
     */
    loadAllProducts: (params) => client.get('/opnames/all-products', { params }),

    /**
     * Get stock setting (negative stock allowed or not)
     * @returns {Promise}
     */
    getStockSetting: () => client.get('/opnames/stock-setting'),

    /**
     * Check if there's an existing draft opname for a warehouse
     * @param {Object} params - Query parameters
     * @param {number} params.warehouse_id - Warehouse ID (required)
     * @returns {Promise}
     */
    checkDraft: (params) => client.get('/opnames/check-draft', { params }),

    /**
     * Refresh stock system for products in opname form
     * @param {Object} data - Request data
     * @param {number} data.warehouse_id - Warehouse ID (required)
     * @param {Array} data.product_ids - Product IDs to refresh (required)
     * @returns {Promise}
     */
    refreshStock: (data) => client.post('/opnames/refresh-stock', data)
};

export default opnamesApi;

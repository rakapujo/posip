import client from '../client';

/**
 * Purchase Returns API module
 */
export const purchaseReturnsApi = {
    /**
     * Get all purchase returns with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by nomor_dokumen, notes, supplier
     * @param {number} [params.supplier_id] - Filter by supplier ID
     * @param {number} [params.warehouse_id] - Filter by warehouse ID
     * @param {string} [params.status] - Filter by status (draft, lock, approved)
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/purchase-returns', { params }),

    /**
     * Get a single purchase return by ULID
     * @param {string} ulid - Purchase return ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/purchase-returns/${ulid}`),

    /**
     * Create a new purchase return (draft)
     * @param {Object} data - Purchase return data
     * @returns {Promise}
     */
    create: (data) => client.post('/purchase-returns', data),

    /**
     * Update an existing purchase return (draft only)
     * @param {string} ulid - Purchase return ULID
     * @param {Object} data - Purchase return data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/purchase-returns/${ulid}`, data),

    /**
     * Delete a purchase return (draft only)
     * @param {string} ulid - Purchase return ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/purchase-returns/${ulid}`),

    /**
     * Lock a purchase return (draft -> lock, stock out)
     * @param {string} ulid - Purchase return ULID
     * @returns {Promise}
     */
    lock: (ulid) => client.post(`/purchase-returns/${ulid}/lock`),

    /**
     * Approve a purchase return (lock -> approved, create deposit)
     * @param {string} ulid - Purchase return ULID
     * @param {Object} data - Approval data
     * @param {number} data.nilai_diakui - Acknowledged value
     * @param {string} [data.catatan_approval] - Approval notes
     * @returns {Promise}
     */
    approve: (ulid, data) => client.post(`/purchase-returns/${ulid}/approve`, data),

    /**
     * Get products for autocomplete
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search keyword
     * @returns {Promise}
     */
    getProducts: (params) => client.get('/purchase-returns/products', { params }),

    /**
     * Get last purchase price for a product
     * @param {Object} params - Query parameters
     * @param {number} params.product_id - Product ID (required)
     * @param {number} [params.supplier_id] - Supplier ID (optional)
     * @param {string} [params.unit] - Unit (optional)
     * @returns {Promise}
     */
    getLastPrice: (params) => client.get('/purchase-returns/last-price', { params }),

    /**
     * Get tax settings for purchase returns
     * @returns {Promise}
     */
    getTaxSettings: () => client.get('/purchase-returns/tax-settings'),

    /**
     * Get stock setting (allow negative)
     * @returns {Promise}
     */
    getStockSetting: () => client.get('/purchase-returns/stock-setting'),

    /**
     * Calculate purchase return totals (preview)
     * @param {Object} data - Purchase return data
     * @returns {Promise}
     */
    calculate: (data) => client.post('/purchase-returns/calculate', data),

    /**
     * Get returnable details from a PO
     * @param {string} poUlid - PO ULID
     * @returns {Promise}
     */
    getReturnableDetails: (poUlid) => client.get(`/purchase-returns/po/${poUlid}/returnable-details`)
};

export default purchaseReturnsApi;

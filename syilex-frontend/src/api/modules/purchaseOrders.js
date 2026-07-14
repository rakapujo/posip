import client from '../client';

/**
 * Purchase Orders API module
 */
export const purchaseOrdersApi = {
    /**
     * Get all purchase orders with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by nomor_dokumen, notes, supplier
     * @param {number} [params.supplier_id] - Filter by supplier ID
     * @param {number} [params.warehouse_id] - Filter by warehouse ID
     * @param {string} [params.status] - Filter by status (draft, approved)
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/purchase-orders', { params }),

    /**
     * Get list of approved POs for dropdown
     * @param {Object} params - Query parameters
     * @param {number} [params.supplier_id] - Filter by supplier ID
     * @returns {Promise}
     */
    getList: (params = {}) => client.get('/purchase-orders/list', { params }),

    /**
     * Get a single purchase order by ULID
     * @param {string} ulid - Purchase order ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/purchase-orders/${ulid}`),

    /**
     * Create a new purchase order (draft)
     * @param {Object} data - Purchase order data
     * @returns {Promise}
     */
    create: (data) => client.post('/purchase-orders', data),

    /**
     * Update an existing purchase order (draft only)
     * @param {string} ulid - Purchase order ULID
     * @param {Object} data - Purchase order data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/purchase-orders/${ulid}`, data),

    /**
     * Delete a purchase order (draft only)
     * @param {string} ulid - Purchase order ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/purchase-orders/${ulid}`),

    /**
     * Approve a purchase order
     * @param {string} ulid - Purchase order ULID
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/purchase-orders/${ulid}/approve`),

    /**
     * Get products for autocomplete
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search keyword
     * @returns {Promise}
     */
    getProducts: (params) => client.get('/purchase-orders/products', { params }),

    /**
     * Get last purchase price for a product
     * @param {Object} params - Query parameters
     * @param {number} params.product_id - Product ID (required)
     * @param {number} [params.supplier_id] - Supplier ID (optional)
     * @param {string} [params.unit] - Unit (optional)
     * @returns {Promise}
     */
    getLastPrice: (params) => client.get('/purchase-orders/last-price', { params }),

    /**
     * Get price history for a product
     * @param {Object} params - Query parameters
     * @param {number} params.product_id - Product ID (required)
     * @param {number} [params.supplier_id] - Supplier ID (optional)
     * @param {string} [params.unit] - Unit (optional)
     * @param {number} [params.limit] - Limit (default 10, max 50)
     * @returns {Promise}
     */
    getPriceHistory: (params) => client.get('/purchase-orders/price-history', { params }),

    /**
     * Get tax settings for purchase orders
     * @returns {Promise}
     */
    getTaxSettings: () => client.get('/purchase-orders/tax-settings'),

    /**
     * Calculate purchase order totals (preview)
     * @param {Object} data - Purchase order data
     * @returns {Promise}
     */
    calculate: (data) => client.post('/purchase-orders/calculate', data)
};

export default purchaseOrdersApi;

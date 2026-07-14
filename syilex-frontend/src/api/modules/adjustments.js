import client from '../client';

/**
 * Adjustments API module
 */
export const adjustmentsApi = {
    /**
     * Get all adjustments with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by nomor_dokumen, keterangan
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
    getAll: (params = {}) => client.get('/adjustments', { params }),

    /**
     * Get a single adjustment by ULID
     * @param {string} ulid - Adjustment ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/adjustments/${ulid}`),

    /**
     * Create a new adjustment (draft)
     * @param {Object} data - Adjustment data
     * @param {number} data.warehouse_id - Warehouse ID
     * @param {string} data.tanggal - Date and time (ISO format)
     * @param {string} [data.keterangan] - Notes/description
     * @param {Array} data.details - Adjustment details
     * @param {number} data.details[].product_id - Product ID
     * @param {string} data.details[].jenis - Type (debit or kredit)
     * @param {number} data.details[].qty - Quantity
     * @param {string} [data.details[].notes] - Item notes
     * @returns {Promise}
     */
    create: (data) => client.post('/adjustments', data),

    /**
     * Update an existing adjustment (draft only)
     * @param {string} ulid - Adjustment ULID
     * @param {Object} data - Adjustment data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/adjustments/${ulid}`, data),

    /**
     * Delete an adjustment (draft only)
     * @param {string} ulid - Adjustment ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/adjustments/${ulid}`),

    /**
     * Approve an adjustment
     * @param {string} ulid - Adjustment ULID
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/adjustments/${ulid}/approve`),

    /**
     * Get products with stock for autocomplete
     * @param {Object} params - Query parameters
     * @param {number} params.warehouse_id - Warehouse ID (required)
     * @param {string} [params.search] - Search keyword
     * @returns {Promise}
     */
    getProducts: (params) => client.get('/adjustments/products', { params }),

    /**
     * Get stock setting (negative stock allowed or not)
     * @returns {Promise}
     */
    getStockSetting: () => client.get('/adjustments/stock-setting')
};

export default adjustmentsApi;

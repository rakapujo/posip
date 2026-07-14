import client from '../client';

/**
 * Repacks API module
 */
export const repacksApi = {
    /**
     * Get all repacks with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by nomor_dokumen, notes
     * @param {number} [params.warehouse_id] - Filter by warehouse ID
     * @param {string} [params.tipe] - Filter by tipe (pecah, gabung)
     * @param {string} [params.status] - Filter by status (draft, approved)
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/repacks', { params }),

    /**
     * Get a single repack by ULID
     * @param {string} ulid - Repack ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/repacks/${ulid}`),

    /**
     * Create a new repack (draft)
     * @param {Object} data - Repack data
     * @param {number} data.warehouse_id - Warehouse ID
     * @param {string} data.tipe - Tipe (pecah, gabung)
     * @param {string} data.tanggal - Date and time (ISO format)
     * @param {number} [data.biaya_repack] - Additional cost (default 0)
     * @param {string} [data.notes] - Notes/description
     * @param {Array} data.inputs - Input items (bahan)
     * @param {number} data.inputs[].product_id - Product ID
     * @param {number} data.inputs[].qty - Quantity
     * @param {Array} data.outputs - Output items (hasil)
     * @param {number} data.outputs[].product_id - Product ID
     * @param {number} data.outputs[].qty - Quantity
     * @returns {Promise}
     */
    create: (data) => client.post('/repacks', data),

    /**
     * Update an existing repack (draft only)
     * @param {string} ulid - Repack ULID
     * @param {Object} data - Repack data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/repacks/${ulid}`, data),

    /**
     * Delete a repack (draft only)
     * @param {string} ulid - Repack ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/repacks/${ulid}`),

    /**
     * Approve a repack
     * @param {string} ulid - Repack ULID
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/repacks/${ulid}/approve`),

    /**
     * Get products with stock for autocomplete
     * @param {Object} params - Query parameters
     * @param {number} params.warehouse_id - Warehouse ID (required)
     * @param {string} [params.search] - Search keyword
     * @returns {Promise}
     */
    getProducts: (params) => client.get('/repacks/products', { params }),

    /**
     * Get stock setting (negative stock allowed or not)
     * @returns {Promise}
     */
    getStockSetting: () => client.get('/repacks/stock-setting')
};

export default repacksApi;

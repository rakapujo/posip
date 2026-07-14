import client from '../client';

/**
 * Pembayaran Hutang API module
 */
export const pembayaranHutangsApi = {
    /**
     * Get all pembayaran hutang with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by nomor_dokumen, no_referensi, notes, supplier
     * @param {number} [params.supplier_id] - Filter by supplier ID
     * @param {string} [params.status] - Filter by status (draft, completed)
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/pembayaran-hutangs', { params }),

    /**
     * Get a single pembayaran hutang by ULID
     * @param {string} ulid - Pembayaran hutang ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/pembayaran-hutangs/${ulid}`),

    /**
     * Create a new pembayaran hutang (draft)
     * @param {Object} data - Pembayaran hutang data
     * @returns {Promise}
     */
    create: (data) => client.post('/pembayaran-hutangs', data),

    /**
     * Update an existing pembayaran hutang (draft only)
     * @param {string} ulid - Pembayaran hutang ULID
     * @param {Object} data - Pembayaran hutang data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/pembayaran-hutangs/${ulid}`, data),

    /**
     * Delete a pembayaran hutang (draft only)
     * @param {string} ulid - Pembayaran hutang ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/pembayaran-hutangs/${ulid}`),

    /**
     * Complete a pembayaran hutang
     * @param {string} ulid - Pembayaran hutang ULID
     * @returns {Promise}
     */
    complete: (ulid) => client.post(`/pembayaran-hutangs/${ulid}/complete`),

    /**
     * Alias for complete (for useTransactionList compatibility)
     * @param {string} ulid - Pembayaran hutang ULID
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/pembayaran-hutangs/${ulid}/complete`),

    /**
     * Get outstanding hutangs for a supplier
     * @param {Object} params - Query parameters
     * @param {number} params.supplier_id - Supplier ID (required)
     * @returns {Promise}
     */
    getOutstandingHutangs: (params) => client.get('/pembayaran-hutangs/outstanding-hutangs', { params }),

    /**
     * Get available deposits for a supplier
     * @param {Object} params - Query parameters
     * @param {number} params.supplier_id - Supplier ID (required)
     * @returns {Promise}
     */
    getAvailableDeposits: (params) => client.get('/pembayaran-hutangs/available-deposits', { params })
};

export default pembayaranHutangsApi;

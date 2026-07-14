import client from '../client';

/**
 * Supplier Deposits API module
 */
export const supplierDepositsApi = {
    /**
     * Get all supplier deposits with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by supplier name/code, document number
     * @param {number} [params.supplier_id] - Filter by supplier ID
     * @param {string} [params.status] - Filter by status (available, used_partial, used_all)
     * @param {boolean} [params.has_balance_only] - Filter to show only deposits with balance
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/supplier-deposits', { params }),

    /**
     * Get a single supplier deposit by ULID
     * @param {string} ulid - Supplier deposit ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/supplier-deposits/${ulid}`),

    /**
     * Get summary of supplier deposits
     * @param {Object} params - Query parameters
     * @param {number} [params.supplier_id] - Filter by supplier ID
     * @returns {Promise}
     */
    getSummary: (params = {}) => client.get('/supplier-deposits/summary', { params }),

    /**
     * Get deposits by supplier (for payment form)
     * @param {Object} params - Query parameters
     * @param {number} params.supplier_id - Supplier ID (required)
     * @returns {Promise}
     */
    bySupplier: (params) => client.get('/supplier-deposits/by-supplier', { params }),

    /**
     * Create a new manual deposit
     * @param {Object} data - Deposit data
     * @param {number} data.supplier_id - Supplier ID
     * @param {string} data.tanggal - Deposit date (YYYY-MM-DD)
     * @param {number} data.nominal_awal - Initial amount
     * @param {string} [data.no_referensi] - Reference number (voucher, loyalty, etc)
     * @param {string} [data.keterangan] - Description
     * @returns {Promise}
     */
    create: (data) => client.post('/supplier-deposits', data),

    /**
     * Update a manual deposit
     * @param {string} ulid - Deposit ULID
     * @param {Object} data - Deposit data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/supplier-deposits/${ulid}`, data),

    /**
     * Delete a manual deposit
     * @param {string} ulid - Deposit ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/supplier-deposits/${ulid}`),

    /**
     * Export deposits to Excel
     * @param {Object} params - Filter parameters
     * @returns {Promise}
     */
    export: (params = {}) => client.get('/supplier-deposits/export', { params, responseType: 'blob' }),

    /**
     * Get usage history — pemakaian deposit ke pembayaran hutang mana saja.
     * @param {string} ulid - Deposit ULID
     * @returns {Promise}
     */
    getUsage: (ulid) => client.get(`/supplier-deposits/${ulid}/usage`)
};

export default supplierDepositsApi;

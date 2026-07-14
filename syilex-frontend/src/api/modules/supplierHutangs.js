import client from '../client';

/**
 * Supplier Hutangs API module
 */
export const supplierHutangsApi = {
    /**
     * Get all hutang with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by PO number, supplier name
     * @param {number} [params.supplier_id] - Filter by supplier ID
     * @param {string} [params.status] - Filter by status (unpaid, partial, paid, outstanding)
     * @param {boolean} [params.overdue] - Filter by overdue only
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/supplier-hutangs', { params }),

    /**
     * Get a single hutang by ULID
     * @param {string} ulid - Hutang ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/supplier-hutangs/${ulid}`),

    /**
     * Get hutang summary statistics
     * @param {Object} params - Query parameters
     * @param {number} [params.supplier_id] - Filter by supplier ID
     * @returns {Promise}
     */
    getSummary: (params = {}) => client.get('/supplier-hutangs/summary', { params }),

    /**
     * Get aging bucket summary (30/60/90 days).
     * Requires hutang.view_nominal permission.
     * @param {Object} params - Query parameters
     * @param {number} [params.supplier_id] - Filter by supplier ID
     * @returns {Promise}
     */
    getAgingSummary: (params = {}) => client.get('/supplier-hutangs/aging-summary', { params }),

    /**
     * Get outstanding hutang for a specific supplier
     * @param {Object} params - Query parameters
     * @param {number} params.supplier_id - Supplier ID (required)
     * @returns {Promise}
     */
    getBySupplier: (params) => client.get('/supplier-hutangs/by-supplier', { params }),

    /**
     * Export hutang to Excel
     * @param {Object} params - Filter parameters
     * @returns {Promise}
     */
    export: (params = {}) => client.get('/supplier-hutangs/export', { params, responseType: 'blob' })
};

export default supplierHutangsApi;

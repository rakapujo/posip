import client from '../client';

/**
 * Stock Cards API module
 */
export const stockCardsApi = {
    /**
     * Get stock card entries with filters
     * @param {Object} params - Query parameters
     * @param {number|string} params.product_id - Product ID or ULID (required)
     * @param {number} [params.warehouse_id] - Filter by warehouse
     * @param {string} [params.start_date] - Start date (YYYY-MM-DD)
     * @param {string} [params.end_date] - End date (YYYY-MM-DD)
     * @param {string} [params.transaction_type] - Filter by transaction type
     * @param {string} [params.search] - Search by transaction_no
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc/desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/inventory/stock-cards', { params }),

    /**
     * Get summary for stock card (opening, total in/out, ending)
     * @param {Object} params
     * @param {number|string} params.product_id - Product ID or ULID (required)
     * @param {number} [params.warehouse_id] - Filter by warehouse
     * @param {string} [params.start_date] - Start date
     * @param {string} [params.end_date] - End date
     * @param {string} [params.transaction_type] - Filter by transaction type
     * @returns {Promise}
     */
    getSummary: (params = {}) => client.get('/inventory/stock-cards/summary', { params }),

    /**
     * Get HPP movement summary (avg_cost awal/akhir, total nilai masuk/keluar)
     * @param {Object} params
     * @param {number|string} params.product_id - Product ID or ULID (required)
     * @param {number} [params.warehouse_id] - Filter by warehouse
     * @param {string} [params.start_date] - Start date
     * @param {string} [params.end_date] - End date
     * @param {string} [params.transaction_type] - Filter by transaction type
     * @returns {Promise}
     */
    getHppSummary: (params = {}) => client.get('/inventory/stock-cards/hpp-summary', { params }),

    /**
     * Export stock card to Excel
     * @param {Object} params - Same as getAll
     * @returns {Promise} - Blob response
     */
    export: (params = {}) =>
        client.get('/inventory/stock-cards/export', {
            params,
            responseType: 'blob'
        })
};

export default stockCardsApi;

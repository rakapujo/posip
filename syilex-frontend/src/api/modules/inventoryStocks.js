import client from '../client';

/**
 * Inventory Stocks API module (View Only)
 */
export const inventoryStocksApi = {
    /**
     * Get all stocks grouped by product with pagination, search, and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by kode_produk, barcode, nama_produk
     * @param {number} [params.warehouse_id] - Filter by warehouse ID
     * @param {string} [params.status] - Filter by product status (active, inactive)
     * @param {boolean} [params.low_stock] - Filter low stock only
     * @param {string} [params.sort_field] - Sort field (default: kode_produk)
     * @param {string} [params.sort_order] - Sort order (default: asc)
     * @param {number} [params.per_page] - Items per page (default: 10)
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/inventory/stocks', { params }),

    /**
     * Get stock details for a specific product (all warehouses)
     * @param {string} ulid - Product ULID
     * @returns {Promise}
     */
    getByProduct: (ulid) => client.get(`/inventory/stocks/by-product/${ulid}`),

    /**
     * Get stock summary (total qty, value, low stock count, etc.)
     * @param {Object} params - Query parameters
     * @param {number} [params.warehouse_id] - Filter by warehouse ID
     * @returns {Promise}
     */
    getSummary: (params = {}) => client.get('/inventory/stocks/summary', { params }),

    /**
     * Get valuation per warehouse (qty × avg_cost per warehouse).
     * Requires stok.view_hpp permission.
     * @returns {Promise}
     */
    getValuationByWarehouse: () => client.get('/inventory/stocks/valuation-by-warehouse'),

    /**
     * Export stocks to Excel (flat view: 1 product per warehouse)
     * @param {Object} params - Query parameters
     * @param {number} [params.warehouse_id] - Filter by warehouse ID
     * @param {string} [params.search] - Search filter
     * @param {boolean} [params.low_stock] - Filter low stock only
     * @returns {Promise} - Returns blob for download
     */
    export: (params = {}) =>
        client.get('/inventory/stocks/export', {
            params,
            responseType: 'blob'
        })
};

export default inventoryStocksApi;

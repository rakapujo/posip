import client from '../client';

/**
 * POS Kasir API module
 */
export const posApi = {
    /**
     * Get active terminal for current user
     * @returns {Promise}
     */
    getActiveTerminal: () => client.get('/pos/active-terminal'),

    /**
     * Search products for POS
     * @param {Object} params
     * @param {number} params.warehouse_id - Warehouse ID
     * @param {string} [params.search] - Search by kode/nama/barcode
     * @returns {Promise}
     */
    searchProducts: (params = {}) => client.get('/pos/products', { params }),

    /**
     * Get currently-effective promos (for frontend preview matching).
     * Backend always rebuilds at checkout — this is display-only.
     * @param {Object} [params]
     * @returns {Promise}
     */
    getActivePromos: (params = {}) => client.get('/pos/active-promos', { params }),

    /**
     * Get product by barcode (exact match)
     * @param {string} barcode
     * @param {Object} params
     * @param {number} params.warehouse_id - Warehouse ID
     * @returns {Promise}
     */
    getProductByBarcode: (barcode, params = {}) => client.get(`/pos/products/barcode/${barcode}`, { params }),

    /**
     * Calculate totals (preview before checkout)
     * @param {Object} data
     * @param {number} data.subtotal
     * @param {string} data.diskon_tipe - percent, nominal, none
     * @param {number} [data.diskon_nilai]
     * @param {Array} [data.payments]
     * @returns {Promise}
     */
    calculate: (data) => client.post('/pos/calculate', data),

    /**
     * Process checkout (create sales transaction)
     * @param {Object} data
     * @param {number} data.terminal_id
     * @param {number} data.shift_id
     * @param {number} data.warehouse_id
     * @param {number} data.customer_id
     * @param {string} data.diskon_tipe
     * @param {number} [data.diskon_nilai]
     * @param {string} [data.notes]
     * @param {Array} data.items - Cart items
     * @param {Array} data.payments - Payment methods
     * @returns {Promise}
     */
    checkout: (data) => client.post('/pos/checkout', data),

    /**
     * Get sales history for current shift
     * @param {Object} params
     * @param {number} params.shift_id
     * @param {string} [params.search]
     * @returns {Promise}
     */
    getHistory: (params = {}) => client.get('/pos/history', { params }),

    /**
     * Get sales detail (for receipt reprint)
     * @param {string} ulid
     * @returns {Promise}
     */
    getSales: (ulid) => client.get(`/pos/sales/${ulid}`),

    /**
     * Void a sales transaction
     * @param {string} ulid
     * @param {Object} data
     * @param {string} data.reason
     * @returns {Promise}
     */
    voidSales: (ulid, data) => client.post(`/pos/sales/${ulid}/void`, data),

    /**
     * Get shift report data
     * @param {string} shiftUlid
     * @returns {Promise}
     */
    getShiftReport: (shiftUlid) => client.get(`/pos/shift-report/${shiftUlid}`),

    /**
     * Search sales for return
     * @param {Object} params
     * @param {number} params.shift_id
     * @param {number} params.terminal_id
     * @param {string} params.session_type - current or previous
     * @param {string} [params.search]
     * @returns {Promise}
     */
    searchSalesForReturn: (params = {}) => client.get('/pos/returns/search-sales', { params }),

    /**
     * Get sales detail with returnable quantities
     * @param {string} ulid
     * @returns {Promise}
     */
    getSalesForReturn: (ulid) => client.get(`/pos/returns/sales/${ulid}`),

    /**
     * Process a sales return
     * @param {Object} data
     * @param {number} data.sales_id
     * @param {number} data.terminal_id
     * @param {number} data.shift_id
     * @param {number} data.warehouse_id
     * @param {string} data.refund_method - cash or credit
     * @param {string} [data.notes]
     * @param {Array} data.items
     * @returns {Promise}
     */
    processReturn: (data) => client.post('/pos/returns', data),

    /**
     * Get returns for current shift
     * @param {Object} params
     * @param {number} params.shift_id
     * @returns {Promise}
     */
    getReturns: (params = {}) => client.get('/pos/returns', { params }),

    /**
     * Get cash transactions for current shift
     * @param {Object} params
     * @param {number} params.shift_id
     * @returns {Promise}
     */
    getCashTransactions: (params = {}) => client.get('/pos/cash', { params }),

    /**
     * Create a cash transaction
     * @param {Object} data
     * @param {number} data.terminal_id
     * @param {number} data.shift_id
     * @param {string} data.tipe - setor_awal, kas_masuk, kas_keluar
     * @param {number} data.nominal
     * @param {string} [data.keterangan]
     * @returns {Promise}
     */
    createCashTransaction: (data) => client.post('/pos/cash', data),

    /**
     * Get cash summary for current shift
     * @param {Object} params
     * @param {number} params.shift_id
     * @returns {Promise}
     */
    getCashSummary: (params = {}) => client.get('/pos/cash/summary', { params }),

    /**
     * Lock the shift (screen lock)
     * @param {Object} data
     * @param {number} data.shift_id
     * @returns {Promise}
     */
    lockShift: (data) => client.post('/pos/lock', data),

    /**
     * Unlock the shift (screen unlock)
     * @param {Object} data
     * @param {number} data.shift_id
     * @param {string} data.credential - PIN or password
     * @returns {Promise}
     */
    unlockShift: (data) => client.post('/pos/unlock', data)
};

export default posApi;

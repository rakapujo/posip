import client from '../client';

/**
 * POS Terminals API module
 */
export const posTerminalsApi = {
    /**
     * Get all POS terminals with pagination, search, and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by kode_terminal, nama_terminal
     * @param {string} [params.status] - Filter by status (active, inactive)
     * @param {string} [params.sort_field] - Sort field (default: created_at)
     * @param {string} [params.sort_order] - Sort order (default: desc)
     * @param {number} [params.per_page] - Items per page (default: 12)
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/pos-terminals', { params }),

    /**
     * Get single POS terminal by ULID
     * @param {string} ulid
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/pos-terminals/${ulid}`),

    /**
     * Create new POS terminal
     * @param {Object} data
     * @param {string} data.kode_terminal - Terminal code (unique)
     * @param {string} data.nama_terminal - Terminal name
     * @param {number} data.warehouse_id - Warehouse ID
     * @param {number} [data.default_customer_id] - Default customer ID
     * @param {number} [data.default_metode_pembayaran_id] - Default payment method ID
     * @param {string} [data.default_printer] - Default printer name
     * @param {boolean} data.izinkan_retur - Allow returns
     * @param {number} [data.durasi_retur] - Return duration (0=shift, 1+=days, null=unlimited)
     * @param {string} [data.keterangan] - Notes
     * @param {string} data.status - active or inactive
     * @param {number[]} [data.user_ids] - Assigned user IDs
     * @param {number[]} [data.metode_pembayaran_ids] - Allowed payment method IDs
     * @returns {Promise}
     */
    create: (data) => client.post('/pos-terminals', data),

    /**
     * Update POS terminal
     * Note: kode_terminal cannot be changed after creation
     * @param {string} ulid
     * @param {Object} data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/pos-terminals/${ulid}`, data),

    /**
     * Toggle terminal status (activate/deactivate)
     * @param {string} ulid
     * @returns {Promise}
     */
    toggleStatus: (ulid) => client.patch(`/pos-terminals/${ulid}/toggle-status`),

    /**
     * Permanently delete terminal
     * @param {string} ulid
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/pos-terminals/${ulid}`),

    /**
     * Force release an active terminal
     * @param {string} ulid
     * @returns {Promise}
     */
    forceRelease: (ulid, data = {}) => client.post(`/pos-terminals/${ulid}/force-release`, data),

    /**
     * Get list of active terminals for dropdowns
     * @returns {Promise}
     */
    getList: (params = {}) => client.get('/pos-terminals/list', { params }),

    /**
     * Summary shift aktif — dipakai halaman Settings untuk warn admin sebelum
     * ubah setting fiskal yang mempengaruhi transaksi berjalan.
     * @returns {Promise<{count: number, shifts: Array}>}
     */
    getActiveShiftsSummary: () => client.get('/pos-terminals/active-shifts-summary'),

    /**
     * Start a shift on a terminal
     * @param {string} ulid
     * @returns {Promise}
     */
    startShift: (ulid) => client.post(`/pos-terminals/${ulid}/start-shift`),

    /**
     * End a shift on a terminal
     * @param {string} ulid
     * @param {Object} [data] - Optional reconcile payload
     * @param {number} [data.saldo_fisik] - Uang fisik yang dihitung kasir
     * @param {string} [data.closing_notes] - Catatan tutup shift
     * @returns {Promise}
     */
    endShift: (ulid, data = {}) => client.post(`/pos-terminals/${ulid}/end-shift`, data)
};

export default posTerminalsApi;

import client from '../client';

/**
 * Price Change API module
 */
export const priceChangesApi = {
    /**
     * Get all price changes with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by nomor_dokumen, notes
     * @param {string} [params.status] - Filter by status (draft, scheduled, applied)
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/price-changes', { params }),

    /**
     * Get a single price change by ULID
     * @param {string} ulid - Price change ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/price-changes/${ulid}`),

    /**
     * Create a new price change (draft)
     * @param {Object} data - Price change data
     * @param {string} data.tanggal_pengajuan - Submission date (ISO format)
     * @param {string} data.tanggal_berlaku - Effective date (ISO format)
     * @param {string} [data.notes] - Notes/description
     * @param {Array} data.details - Price change details
     * @param {number} data.details[].product_id - Product ID
     * @param {number} data.details[].harga_1_baru - New price for unit 1
     * @param {number} [data.details[].harga_2_baru] - New price for unit 2 (manual mode)
     * @param {number} [data.details[].harga_3_baru] - New price for unit 3 (manual mode)
     * @param {number} [data.details[].harga_4_baru] - New price for unit 4 (manual mode)
     * @param {string} data.details[].alasan - Reason (enum)
     * @param {string} [data.details[].notes] - Item notes
     * @returns {Promise}
     */
    create: (data) => client.post('/price-changes', data),

    /**
     * Update an existing price change (draft only)
     * @param {string} ulid - Price change ULID
     * @param {Object} data - Price change data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/price-changes/${ulid}`, data),

    /**
     * Delete a price change (draft only)
     * @param {string} ulid - Price change ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/price-changes/${ulid}`),

    /**
     * Approve a price change (draft -> scheduled)
     * @param {string} ulid - Price change ULID
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/price-changes/${ulid}/approve`),

    /**
     * Cancel a price change (scheduled -> draft)
     * @param {string} ulid - Price change ULID
     * @returns {Promise}
     */
    cancel: (ulid) => client.post(`/price-changes/${ulid}/cancel`),

    /**
     * Apply a price change now (scheduled -> applied)
     * @param {string} ulid - Price change ULID
     * @returns {Promise}
     */
    apply: (ulid) => client.post(`/price-changes/${ulid}/apply`),

    /**
     * Get products for autocomplete (with lock info)
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search keyword
     * @param {string} [params.exclude_document_ulid] - Exclude this document's products from lock (for edit mode)
     * @returns {Promise}
     */
    getProducts: (params) => client.get('/price-changes/products', { params }),

    /**
     * Get locked products with document info
     * @param {Object} params - Query parameters
     * @param {string} [params.exclude_document_ulid] - Exclude this document's products from lock
     * @returns {Promise} - { product_ids: [], locked_products: { [id]: { ulid, nomor_dokumen, status } } }
     */
    getLockedProducts: (params = {}) => client.get('/price-changes/locked-products', { params }),

    /**
     * Check if there are other draft/scheduled documents
     * @param {Object} params - Query parameters
     * @param {string} [params.exclude_document_ulid] - Exclude this document from count
     * @returns {Promise} - { has_other_drafts: boolean, count: number, drafts: [] }
     */
    hasOtherDrafts: (params = {}) => client.get('/price-changes/has-other-drafts', { params }),

    /**
     * Get alasan options for dropdown
     * @returns {Promise}
     */
    getAlasanOptions: () => client.get('/price-changes/alasan-options'),

    /**
     * Get count of pending scheduled documents (for badge)
     * @returns {Promise}
     */
    getPendingCount: () => client.get('/price-changes/pending-count')
};

export default priceChangesApi;

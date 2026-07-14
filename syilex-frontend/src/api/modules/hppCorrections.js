import client from '../client';

/**
 * HPP Correction API module
 */
export const hppCorrectionsApi = {
    /**
     * Get all HPP corrections with pagination and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by nomor_dokumen, notes
     * @param {string} [params.status] - Filter by status (draft, approved)
     * @param {string} [params.date_from] - Filter by start date (YYYY-MM-DD)
     * @param {string} [params.date_to] - Filter by end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/hpp-corrections', { params }),

    /**
     * Get a single HPP correction by ULID
     * @param {string} ulid - HPP correction ULID
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/hpp-corrections/${ulid}`),

    /**
     * Create a new HPP correction (draft)
     * @param {Object} data - HPP correction data
     * @param {string} data.tanggal_koreksi - Date and time (ISO format)
     * @param {string} [data.notes] - Notes/description
     * @param {Array} data.details - Correction details
     * @param {number} data.details[].product_id - Product ID
     * @param {number} data.details[].hpp_baru - New HPP value
     * @param {string} data.details[].alasan - Reason (enum)
     * @param {string} [data.details[].notes] - Item notes
     * @returns {Promise}
     */
    create: (data) => client.post('/hpp-corrections', data),

    /**
     * Update an existing HPP correction (draft only)
     * @param {string} ulid - HPP correction ULID
     * @param {Object} data - HPP correction data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/hpp-corrections/${ulid}`, data),

    /**
     * Delete an HPP correction (draft only)
     * @param {string} ulid - HPP correction ULID
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/hpp-corrections/${ulid}`),

    /**
     * Approve an HPP correction
     * @param {string} ulid - HPP correction ULID
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/hpp-corrections/${ulid}/approve`),

    /**
     * Get products for autocomplete (excludes locked products)
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search keyword
     * @returns {Promise}
     */
    getProducts: (params) => client.get('/hpp-corrections/products', { params }),

    /**
     * Check if there's an existing draft HPP correction
     * @returns {Promise}
     */
    checkDraft: () => client.get('/hpp-corrections/check-draft'),

    /**
     * Get locked product IDs (products already in a draft)
     * @returns {Promise}
     */
    getLockedProducts: () => client.get('/hpp-corrections/locked-products'),

    /**
     * Get alasan options for dropdown
     * @returns {Promise}
     */
    getAlasanOptions: () => client.get('/hpp-corrections/alasan-options')
};

export default hppCorrectionsApi;

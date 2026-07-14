import client from '../client';

/**
 * Produk API module
 */
export const produksApi = {
    /**
     * Get all produks with pagination, search, and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by kode_produk, barcode, nama_produk
     * @param {number} [params.brand_id] - Filter by brand
     * @param {number} [params.tipe_id] - Filter by tipe
     * @param {number} [params.kategori_id] - Filter by kategori
     * @param {number} [params.grup_id] - Filter by grup
     * @param {string} [params.status] - Filter by status (active, inactive)
     * @param {string} [params.sort_field] - Sort field (default: created_at)
     * @param {string} [params.sort_order] - Sort order (default: desc)
     * @param {number} [params.per_page] - Items per page (default: 10)
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/produks', { params }),

    /**
     * Get single produk by ULID
     * @param {string} ulid
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/produks/${ulid}`),

    /**
     * Create new produk with multipart form data (for image upload)
     * @param {FormData} formData
     * @returns {Promise}
     */
    create: (formData) =>
        client.post('/produks', formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        }),

    /**
     * Update produk with multipart form data (for image upload)
     * Note: kode_produk cannot be changed after creation
     * @param {string} ulid
     * @param {FormData} formData
     * @returns {Promise}
     */
    update: (ulid, formData) =>
        client.post(`/produks/${ulid}`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            params: { _method: 'PUT' }
        }),

    /**
     * Toggle produk status (activate/deactivate)
     * @param {string} ulid
     * @returns {Promise}
     */
    toggleStatus: (ulid) => client.patch(`/produks/${ulid}/toggle-status`),

    /**
     * Permanently delete produk
     * Will fail if used by inventory or transactions
     * @param {string} ulid
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/produks/${ulid}`),

    /**
     * Delete produk image
     * @param {string} ulid
     * @returns {Promise}
     */
    deleteImage: (ulid) => client.delete(`/produks/${ulid}/image`),

    /**
     * Get list of active produks for dropdowns
     * @param {Object} [params] - Query parameters
     * @param {string} [params.search] - Search by kode_produk, barcode, nama_produk
     * @returns {Promise}
     */
    getList: (params = {}) => client.get('/produks/list', { params }),

    /**
     * Get price input mode setting
     * @returns {Promise} - { price_input_mode: 'auto' | 'manual' }
     */
    getPriceMode: () => client.get('/produks/price-mode'),

    /**
     * Export produks to Excel file
     * @param {Object} [params] - Filter parameters (search, brand_id, tipe_id, kategori_id, grup_id, status)
     * @returns {Promise<Blob>}
     */
    export: (params = {}) =>
        client.get('/produks/export', {
            params,
            responseType: 'blob'
        })
};

export default produksApi;

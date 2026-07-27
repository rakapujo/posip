import client from '../client';

/**
 * Produk API module
 */
export const produksApi = {
    /**
     * Get all produks with pagination, search, and filters
     */
    getAll: (params = {}) => client.get('/produks', { params }),

    /**
     * Get single produk by ULID
     */
    get: (ulid) => client.get(`/produks/${ulid}`),

    /**
     * Create new produk (gambar = path/URL string dari ImageUpload, bukan multipart file)
     */
    create: (data) => client.post('/produks', data),

    /**
     * Update produk (kode_produk tidak bisa diubah)
     */
    update: (ulid, data) => client.put(`/produks/${ulid}`, data),

    /**
     * Toggle produk status (activate/deactivate)
     */
    toggleStatus: (ulid) => client.patch(`/produks/${ulid}/toggle-status`),

    /**
     * Permanently delete produk
     */
    delete: (ulid) => client.delete(`/produks/${ulid}`),

    /**
     * Delete produk image
     */
    deleteImage: (ulid) => client.delete(`/produks/${ulid}/image`),

    /**
     * Get list of active produks for dropdowns
     */
    getList: (params = {}) => client.get('/produks/list', { params }),

    /**
     * Get price input mode setting
     */
    getPriceMode: () => client.get('/produks/price-mode'),

    /**
     * Export produks to Excel file
     */
    export: (params = {}) =>
        client.get('/produks/export', {
            params,
            responseType: 'blob'
        })
};

export default produksApi;

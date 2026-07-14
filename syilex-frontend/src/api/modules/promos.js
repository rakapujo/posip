import client from '../client';

/**
 * Promo API module
 */
export const promosApi = {
    /**
     * Get all promos with pagination and filters
     * @param {Object} params
     * @param {string} [params.search] - Search by kode_promo, nama_promo
     * @param {string} [params.status] - Filter by computed status (draft, active, upcoming, expired, inactive)
     * @param {string} [params.date_from] - Filter by tanggal_mulai >= date_from
     * @param {string} [params.date_to] - Filter by tanggal_selesai <= date_to
     * @param {string} [params.sort_field] - Sort field
     * @param {string} [params.sort_order] - Sort order (asc, desc)
     * @param {number} [params.per_page] - Items per page
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/promos', { params }),

    /**
     * Get a single promo by ULID (includes details with target_name resolved)
     * @param {string} ulid
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/promos/${ulid}`),

    /**
     * Create a new promo (status = draft)
     * @param {Object} data
     * @param {string} data.nama_promo
     * @param {string} [data.deskripsi]
     * @param {number} [data.customer_type_id]     - Filter by tipe customer (null = semua tipe)
     * @param {number} [data.customer_category_id] - Filter by kategori customer (null = semua kategori)
     * @param {number} [data.terminal_id]          - Filter by terminal (null = semua terminal)
     * @param {string} data.tanggal_mulai   - YYYY-MM-DD (wajib)
     * @param {string} [data.tanggal_selesai] - YYYY-MM-DD (null = tanpa batas)
     * @param {string} [data.jam_mulai]   - HH:mm (Happy Hour; null = sepanjang hari)
     * @param {string} [data.jam_selesai] - HH:mm (wajib jika jam_mulai diisi)
     * @param {Array}  data.details - array of detail rows with target_type, target_id, min_qty, diskon_1_tipe/nilai ... diskon_4_tipe/nilai
     * @returns {Promise}
     */
    create: (data) => client.post('/promos', data),

    /**
     * Update a promo (draft only).
     * Payload shape sama seperti create() — termasuk customer_category_id & Happy Hour jam.
     * @param {string} ulid
     * @param {Object} data - @see create() for payload schema
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/promos/${ulid}`, data),

    /**
     * Delete a promo (draft only)
     * @param {string} ulid
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/promos/${ulid}`),

    /**
     * Approve a promo (draft → approved)
     * @param {string} ulid
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/promos/${ulid}/approve`),

    /**
     * Cancel approval (approved → draft)
     * @param {string} ulid
     * @returns {Promise}
     */
    cancel: (ulid) => client.post(`/promos/${ulid}/cancel`),

    /**
     * Deactivate a promo (approved → inactive)
     * @param {string} ulid
     * @returns {Promise}
     */
    deactivate: (ulid) => client.post(`/promos/${ulid}/deactivate`),

    /**
     * Reactivate a promo (inactive → approved)
     * @param {string} ulid
     * @returns {Promise}
     */
    reactivate: (ulid) => client.post(`/promos/${ulid}/reactivate`)
};

export default promosApi;

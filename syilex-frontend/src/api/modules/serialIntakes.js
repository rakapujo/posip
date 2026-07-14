import client from '../client';

/**
 * Serial Intakes API module (Input Pembelian Serial — modul serial A+).
 */
export const serialIntakesApi = {
    /**
     * List pembelian serial (paginated).
     * @param {Object} params - search, product_id(ulid), status, date_from, date_to, sort_field, sort_order, per_page, page
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/serial-intakes', { params }),

    /**
     * Detail pembelian serial + unit-nya.
     * @param {string} ulid
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/serial-intakes/${ulid}`),

    /**
     * Simpan pembelian serial baru (draft).
     * @param {Object} data - { product_id, warehouse_id, supplier_id?, tanggal?, no_doc_referensi?, notes?, units[] }
     * @returns {Promise}
     */
    create: (data) => client.post('/serial-intakes', data),

    /**
     * Preview kalkulasi finansial (Ringkasan) tanpa simpan.
     * @param {Object} data - { units[], diskon_*, biaya_* }
     * @returns {Promise}
     */
    calculate: (data) => client.post('/serial-intakes/calculate', data),

    /**
     * Ubah pembelian serial (hanya draft).
     * @param {string} ulid
     * @param {Object} data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/serial-intakes/${ulid}`, data),

    /**
     * Hapus pembelian serial (hanya draft).
     * @param {string} ulid
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/serial-intakes/${ulid}`),

    /**
     * Approve pembelian serial (draft → approved, komit stok + HPP).
     * @param {string} ulid
     * @returns {Promise}
     */
    approve: (ulid) => client.post(`/serial-intakes/${ulid}/approve`)
};

export default serialIntakesApi;

import client from '../client';

/**
 * Koreksi HPP Serial API (koreksi harga_modal & cost_per_unit per-unit — modul serial A+).
 */
export const serialHppCorrectionsApi = {
    getAll: (params = {}) => client.get('/serial-hpp-corrections', { params }),
    get: (ulid) => client.get(`/serial-hpp-corrections/${ulid}`),

    /** Unit tersedia suatu produk serial + harga_modal & cost_per_unit terkini (untuk form). */
    units: (productUlid) => client.get('/serial-hpp-corrections/units', { params: { product_id: productUlid } }),

    create: (data) => client.post('/serial-hpp-corrections', data),
    update: (ulid, data) => client.put(`/serial-hpp-corrections/${ulid}`, data),
    delete: (ulid) => client.delete(`/serial-hpp-corrections/${ulid}`),
    approve: (ulid) => client.post(`/serial-hpp-corrections/${ulid}/approve`)
};

export default serialHppCorrectionsApi;

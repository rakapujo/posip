import client from '../client';

/**
 * Perubahan Data Serial API (koreksi data unit serial — modul serial A+).
 */
export const serialChangesApi = {
    getAll: (params = {}) => client.get('/serial-changes', { params }),
    get: (ulid) => client.get(`/serial-changes/${ulid}`),

    /** Unit tersedia suatu produk serial (untuk dimuat di form koreksi). */
    units: (productUlid) => client.get('/serial-changes/units', { params: { product_id: productUlid } }),

    create: (data) => client.post('/serial-changes', data),
    update: (ulid, data) => client.put(`/serial-changes/${ulid}`, data),
    delete: (ulid) => client.delete(`/serial-changes/${ulid}`),
    approve: (ulid) => client.post(`/serial-changes/${ulid}/approve`)
};

export default serialChangesApi;

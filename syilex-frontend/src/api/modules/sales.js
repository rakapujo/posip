import client from '../client';

/**
 * Manual Sales (Backoffice Penjualan) API — always source=manual on backend.
 */
export const salesApi = {
    getAll: (params = {}) => client.get('/sales', { params }),
    get: (ulid) => client.get(`/sales/${ulid}`),
    create: (data) => client.post('/sales', data),
    update: (ulid, data) => client.put(`/sales/${ulid}`, data),
    delete: (ulid) => client.delete(`/sales/${ulid}`),
    approve: (ulid) => client.post(`/sales/${ulid}/approve`),
    void: (ulid, data) => client.post(`/sales/${ulid}/void`, data),
    getProducts: (params) => client.get('/sales/products', { params }),
    getTaxSettings: () => client.get('/sales/tax-settings'),
    calculate: (data) => client.post('/sales/calculate', data)
};

export default salesApi;

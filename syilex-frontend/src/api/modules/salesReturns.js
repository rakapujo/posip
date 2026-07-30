import client from '../client';

/**
 * Backoffice Sales Returns API module
 */
export const salesReturnsApi = {
    getAll: (params = {}) => client.get('/sales-returns', { params }),
    get: (ulid) => client.get(`/sales-returns/${ulid}`),
    create: (data) => client.post('/sales-returns', data),
    update: (ulid, data) => client.put(`/sales-returns/${ulid}`, data),
    delete: (ulid) => client.delete(`/sales-returns/${ulid}`),
    lock: (ulid) => client.post(`/sales-returns/${ulid}/lock`),
    approve: (ulid, data) => client.post(`/sales-returns/${ulid}/approve`, data),
    getReturnableSales: (params = {}) => client.get('/sales-returns/returnable-sales', { params }),
    getReturnableProducts: (params = {}) => client.get('/sales-returns/returnable-products', { params }),
    getReturnableDetails: (salesUlid) => client.get(`/sales-returns/sales/${salesUlid}/returnable-details`)
};

export default salesReturnsApi;

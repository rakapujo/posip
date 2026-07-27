import client from '../client';

/**
 * Customer Deposits API module
 */
export const customerDepositsApi = {
    getAll: (params = {}) => client.get('/customer-deposits', { params }),
    get: (ulid) => client.get(`/customer-deposits/${ulid}`),
    getSummary: (params = {}) => client.get('/customer-deposits/summary', { params }),
    create: (data) => client.post('/customer-deposits', data),
    update: (ulid, data) => client.put(`/customer-deposits/${ulid}`, data),
    delete: (ulid) => client.delete(`/customer-deposits/${ulid}`),
    export: (params = {}) => client.get('/customer-deposits/export', { params, responseType: 'blob' }),
    getUsage: (ulid) => client.get(`/customer-deposits/${ulid}/usage`)
};

export default customerDepositsApi;

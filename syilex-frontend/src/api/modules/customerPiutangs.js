import client from '../client';

/**
 * Customer Piutang API module
 */
export const customerPiutangsApi = {
    getAll: (params = {}) => client.get('/customer-piutangs', { params }),
    get: (ulid) => client.get(`/customer-piutangs/${ulid}`),
    getSummary: (params = {}) => client.get('/customer-piutangs/summary', { params }),
    getAgingSummary: (params = {}) => client.get('/customer-piutangs/aging-summary', { params }),
    export: (params = {}) => client.get('/customer-piutangs/export', { params, responseType: 'blob' })
};

export default customerPiutangsApi;

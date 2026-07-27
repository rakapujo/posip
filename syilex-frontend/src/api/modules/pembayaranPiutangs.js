import client from '../client';

/**
 * Pembayaran Piutang API module
 */
export const pembayaranPiutangsApi = {
    getAll: (params = {}) => client.get('/pembayaran-piutangs', { params }),
    get: (ulid) => client.get(`/pembayaran-piutangs/${ulid}`),
    create: (data) => client.post('/pembayaran-piutangs', data),
    update: (ulid, data) => client.put(`/pembayaran-piutangs/${ulid}`, data),
    delete: (ulid) => client.delete(`/pembayaran-piutangs/${ulid}`),
    complete: (ulid) => client.post(`/pembayaran-piutangs/${ulid}/complete`),
    /** Alias for useTransactionList */
    approve: (ulid) => client.post(`/pembayaran-piutangs/${ulid}/complete`),
    getOutstandingPiutangs: (params) => client.get('/pembayaran-piutangs/outstanding-piutangs', { params }),
    getAvailableDeposits: (params) => client.get('/pembayaran-piutangs/available-deposits', { params })
};

export default pembayaranPiutangsApi;

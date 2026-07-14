import client from '../client';

/**
 * Dashboard API
 *
 * @module dashboardApi
 */
export const dashboardApi = {
    /**
     * Get dashboard data (permission-based)
     * @returns {Promise<import('axios').AxiosResponse>} Response with dynamic dashboard data
     */
    get: () => client.get('/dashboard')
};

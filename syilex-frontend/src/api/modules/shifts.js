import client from '../client';

/**
 * Shifts API module (read-only)
 */
export const shiftsApi = {
    /**
     * Get all shifts with pagination, search, and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by terminal kode/nama, user name
     * @param {string} [params.status] - Filter by status (active, ended, forced)
     * @param {string} [params.start_date] - Filter start date (YYYY-MM-DD)
     * @param {string} [params.end_date] - Filter end date (YYYY-MM-DD)
     * @param {string} [params.sort_field] - Sort field (default: started_at)
     * @param {string} [params.sort_order] - Sort order (default: desc)
     * @param {number} [params.per_page] - Items per page (default: 10)
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/shifts', { params }),

    /**
     * Get daily shift summary aggregation (per-tanggal tab).
     * @param {Object} params
     * @param {string} [params.date_from] - Start date YYYY-MM-DD
     * @param {string} [params.date_to]   - End date YYYY-MM-DD
     * @returns {Promise}
     */
    getDailySummary: (params = {}) => client.get('/shifts/daily-summary', { params })
};

export default shiftsApi;

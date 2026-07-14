import client from '../client';

/**
 * Users API module
 */
export const usersApi = {
    /**
     * Get all users with pagination, search, and filters
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by name, email, phone
     * @param {string} [params.status] - Filter by status (active, inactive)
     * @param {string} [params.role] - Filter by role name
     * @param {string} [params.sort_field] - Sort field (default: created_at)
     * @param {string} [params.sort_order] - Sort order (default: desc)
     * @param {number} [params.per_page] - Items per page (default: 10)
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/users', { params }),

    /**
     * Get single user by ULID
     * @param {string} ulid
     * @returns {Promise}
     */
    get: (ulid) => client.get(`/users/${ulid}`),

    /**
     * Create new user
     * @param {Object} data
     * @param {string} data.name
     * @param {string} data.email
     * @param {string} data.password
     * @param {string} [data.pin] - 6 digit PIN
     * @param {string} [data.phone]
     * @param {string} data.role
     * @param {string} data.status - active or inactive
     * @param {string} [data.avatar] - Avatar path
     * @returns {Promise}
     */
    create: (data) => client.post('/users', data),

    /**
     * Update user
     * @param {string} ulid
     * @param {Object} data
     * @returns {Promise}
     */
    update: (ulid, data) => client.put(`/users/${ulid}`, data),

    /**
     * Delete user
     * @param {string} ulid
     * @returns {Promise}
     */
    delete: (ulid) => client.delete(`/users/${ulid}`),

    /**
     * Toggle user status (active/inactive)
     * @param {string} ulid
     * @returns {Promise}
     */
    toggleStatus: (ulid) => client.patch(`/users/${ulid}/toggle-status`),

    /**
     * Get list of active users for dropdowns
     * @param {Object} [params]
     * @param {string} [params.permission] - Filter users yang punya permission ini (e.g. 'pos.access')
     * @param {number[]} [params.include_ids] - Force include user IDs (meski tidak match permission)
     * @returns {Promise}
     */
    getList: (params = {}) => client.get('/users/list', { params }),

    /**
     * Get available roles
     * @returns {Promise}
     */
    getRoles: () => client.get('/users/roles')
};

export default usersApi;

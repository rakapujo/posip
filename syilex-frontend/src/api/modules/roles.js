import client from '../client';

/**
 * Roles API module
 */
export const rolesApi = {
    /**
     * Get all roles with pagination and search
     * @param {Object} params - Query parameters
     * @param {string} [params.search] - Search by name
     * @param {string} [params.sort_field] - Sort field (default: name)
     * @param {string} [params.sort_order] - Sort order (default: asc)
     * @param {number} [params.per_page] - Items per page (default: 10)
     * @param {number} [params.page] - Page number
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/roles', { params }),

    /**
     * Get single role by ID with permissions
     * @param {number} id
     * @returns {Promise}
     */
    get: (id) => client.get(`/roles/${id}`),

    /**
     * Create new role
     * @param {Object} data
     * @param {string} data.name - Role name (lowercase, alphanumeric, hyphens)
     * @param {string[]} data.permissions - Array of permission names
     * @returns {Promise}
     */
    create: (data) => client.post('/roles', data),

    /**
     * Update role
     * @param {number} id
     * @param {Object} data
     * @param {string} data.name - Role name
     * @param {string[]} data.permissions - Array of permission names
     * @returns {Promise}
     */
    update: (id, data) => client.put(`/roles/${id}`, data),

    /**
     * Delete role
     * Will fail if role is used by users or is super-admin
     * @param {number} id
     * @returns {Promise}
     */
    delete: (id) => client.delete(`/roles/${id}`),

    /**
     * Get all permissions grouped for matrix UI
     * @returns {Promise}
     */
    getPermissions: () => client.get('/roles/permissions')
};

export default rolesApi;

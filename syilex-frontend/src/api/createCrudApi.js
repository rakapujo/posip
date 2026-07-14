import client from './client';

/**
 * DRY CRUD API factory — master data modules share the same REST shape.
 *
 * @param {string} basePath - e.g. '/brands'
 * @param {Object} [extras] - resource-specific methods merged onto the result
 */
export function createCrudApi(basePath, extras = {}) {
    return {
        getAll: (params = {}) => client.get(basePath, { params }),
        get: (ulid) => client.get(`${basePath}/${ulid}`),
        create: (data) => client.post(basePath, data),
        update: (ulid, data) => client.put(`${basePath}/${ulid}`, data),
        delete: (ulid) => client.delete(`${basePath}/${ulid}`),
        toggleStatus: (ulid) => client.patch(`${basePath}/${ulid}/toggle-status`),
        getList: (params = {}) => client.get(`${basePath}/list`, { params }),
        export: (params = {}) => client.get(`${basePath}/export`, { params, responseType: 'blob' }),
        ...extras
    };
}

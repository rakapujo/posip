import client from '../client';

/**
 * Import Master Data API module
 */
export const importApi = {
    /**
     * Download import template
     * @param {string} entity - Entity key (brand, tipe, etc.)
     * @returns {Promise}
     */
    downloadTemplate: (entity) => client.get(`/import/template/${entity}`, { responseType: 'blob' }),

    /**
     * Import data from Excel file
     * @param {string} entity - Entity key
     * @param {FormData} formData - file + mode
     * @returns {Promise}
     */
    import: (entity, formData) =>
        client.post(`/import/${entity}`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })
};

export default importApi;

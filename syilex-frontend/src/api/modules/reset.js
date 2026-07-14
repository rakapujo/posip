import client from '../client';

/**
 * Reset Database API module
 */
export const resetApi = {
    /**
     * Get row counts for all resettable tables
     * @returns {Promise}
     */
    getCounts: () => client.get('/reset/counts'),

    /**
     * Reset target (group or individual table)
     * @param {Object} data
     * @param {string} data.target - Reset target key
     * @param {string} data.password - User password for confirmation
     * @returns {Promise}
     */
    reset: (data) => client.post('/reset', data),

    /**
     * Get database info for backup card
     * @returns {Promise}
     */
    getBackupInfo: () => client.get('/backup/info'),

    /**
     * Download database backup as .sql file
     * @param {Object} data
     * @param {string} data.password - User password for confirmation
     * @returns {Promise} Blob response
     */
    downloadBackup: (data) => client.post('/backup/download', data, { responseType: 'blob' }),

    /**
     * Restore database from .sql backup file
     * @param {FormData} formData - Contains 'file' (.sql) and 'password'
     * @returns {Promise}
     */
    restoreBackup: (formData) =>
        client.post('/backup/restore', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            timeout: 600000 // 10 min for large files
        })
};

export default resetApi;

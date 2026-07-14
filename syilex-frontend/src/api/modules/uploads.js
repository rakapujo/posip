import client from '../client';

/**
 * Uploads API module
 */
export const uploadsApi = {
    /**
     * Upload an image
     * @param {File} file - The file to upload
     * @param {string} folder - Target folder (settings, products, users, documents)
     * @param {string|null} oldPath - Path to old file (will be deleted)
     * @returns {Promise}
     */
    upload: (file, folder, oldPath = null) => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('folder', folder);
        if (oldPath) {
            formData.append('old_path', oldPath);
        }

        return client.post('/uploads', formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            }
        });
    },

    /**
     * Delete a file
     * @param {string} path - Path to file
     * @returns {Promise}
     */
    delete: (path) => client.delete('/uploads', { data: { path } }),

    /**
     * Get available folders and their configurations
     * @returns {Promise}
     */
    getFolders: () => client.get('/uploads/folders')
};

export default uploadsApi;

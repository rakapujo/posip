import client from '../client';

/**
 * Auth API module
 */
export const authApi = {
    /**
     * Login user
     * @param {Object} credentials
     * @param {string} credentials.email
     * @param {string} credentials.password
     * @returns {Promise}
     */
    login: (credentials) => client.post('/auth/login', credentials),

    /**
     * Get current user
     * @returns {Promise}
     */
    me: () => client.get('/auth/me'),

    /**
     * Logout current session
     * @returns {Promise}
     */
    logout: () => client.post('/auth/logout'),

    /**
     * Logout from all devices
     * @returns {Promise}
     */
    logoutAll: () => client.post('/auth/logout-all'),

    /**
     * Refresh token
     * @returns {Promise}
     */
    refresh: () => client.post('/auth/refresh'),

    /**
     * Get user preferences
     * @returns {Promise}
     */
    getPreferences: () => client.get('/auth/preferences'),

    /**
     * Update user preferences
     * @param {Object} preferences
     * @param {string} [preferences.preset] - Theme preset (Aura, Lara, Nora)
     * @param {string} [preferences.primary] - Primary color
     * @param {string} [preferences.surface] - Surface color
     * @param {boolean} [preferences.dark_theme] - Dark mode
     * @param {string} [preferences.menu_mode] - Menu mode (static, overlay)
     * @returns {Promise}
     */
    updatePreferences: (preferences) => client.put('/auth/preferences', preferences)
};

export default authApi;

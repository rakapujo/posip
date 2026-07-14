import { defineStore } from 'pinia';
import { authApi } from '@/api';
import router from '@/router';
import { usePreferencesStore } from '@/stores/preferences';
import { useLayout } from '@/layout/composables/layout';
import { applyFullTheme } from '@/utils/theme';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: JSON.parse(localStorage.getItem('user')) || null,
        token: localStorage.getItem('token') || null,
        permissions: JSON.parse(localStorage.getItem('permissions')) || [],
        tokenExpiresAt: localStorage.getItem('token_expires_at') || null,
        loading: false,
        error: null,
        bootstrapped: false
    }),

    getters: {
        /**
         * Check if user is authenticated
         */
        isAuthenticated: (state) => !!state.token,

        /**
         * Check if user has specific permission
         */
        can: (state) => (permission) => {
            // Super admin has all permissions
            if (state.user?.roles?.includes('super-admin')) {
                return true;
            }
            return state.permissions.includes(permission);
        },

        /**
         * Check if user has specific role
         */
        hasRole: (state) => (role) => {
            return state.user?.roles?.includes(role) || false;
        },

        /**
         * Get user's display name
         */
        displayName: (state) => state.user?.name || 'User',

        /**
         * Get user's avatar URL
         */
        avatarUrl: (state) => state.user?.avatar_url || null
    },

    actions: {
        /**
         * Login user with credentials
         * @param {Object} credentials
         * @param {string} credentials.email
         * @param {string} credentials.password
         */
        async login(credentials) {
            this.loading = true;
            this.error = null;

            try {
                const response = await authApi.login(credentials);
                const { data } = response.data;

                // Set state
                this.token = data.token;
                this.user = data.user;
                this.permissions = data.user.permissions || [];

                // Persist to localStorage
                localStorage.setItem('token', data.token);
                localStorage.setItem('user', JSON.stringify(data.user));
                localStorage.setItem('permissions', JSON.stringify(data.user.permissions || []));
                if (data.token_expires_at) {
                    this.tokenExpiresAt = data.token_expires_at;
                    localStorage.setItem('token_expires_at', data.token_expires_at);
                }

                // Initialize user preferences and apply to layout
                const preferencesStore = usePreferencesStore();
                const { applyPreferences } = useLayout();

                if (data.user.preferences) {
                    preferencesStore.initFromUser(data.user.preferences);
                    applyPreferences(data.user.preferences);
                    // Apply PrimeVue theme (colors, preset)
                    applyFullTheme(data.user.preferences);
                }

                return { success: true };
            } catch (error) {
                const message = error.response?.data?.message || 'Login gagal. Silakan coba lagi.';
                this.error = message;
                return { success: false, message };
            } finally {
                this.loading = false;
            }
        },

        /**
         * Logout user
         */
        async logout() {
            try {
                if (this.token) {
                    await authApi.logout();
                }
            } catch (error) {
                // Ignore error, still clear local state
                console.error('Logout error:', error);
            } finally {
                this.clearAuth();
                router.push('/');
            }
        },

        /**
         * Fetch current user data
         */
        async fetchUser() {
            if (!this.token) return;

            this.loading = true;
            try {
                const response = await authApi.me();
                const { data } = response.data;

                this.user = data.user;
                this.permissions = data.user.permissions || [];

                // Update localStorage
                localStorage.setItem('user', JSON.stringify(data.user));
                localStorage.setItem('permissions', JSON.stringify(data.user.permissions || []));
                if (data.token_expires_at) {
                    this.tokenExpiresAt = data.token_expires_at;
                    localStorage.setItem('token_expires_at', data.token_expires_at);
                }

                // Update preferences if changed (sync from another device)
                const preferencesStore = usePreferencesStore();
                const { applyPreferences } = useLayout();

                if (data.user.preferences) {
                    preferencesStore.initFromUser(data.user.preferences);
                    applyPreferences(data.user.preferences);
                    // Apply PrimeVue theme (colors, preset)
                    applyFullTheme(data.user.preferences);
                }
            } catch (error) {
                // Token might be invalid, clear auth
                if (error.response?.status === 401) {
                    this.clearAuth();
                }
            } finally {
                this.loading = false;
            }
        },

        /**
         * Clear authentication state
         */
        clearAuth() {
            this.token = null;
            this.user = null;
            this.permissions = [];
            this.tokenExpiresAt = null;
            this.error = null;

            localStorage.removeItem('token');
            localStorage.removeItem('user');
            localStorage.removeItem('permissions');
            localStorage.removeItem('token_expires_at');

            // Clear preferences and reset layout to defaults
            const preferencesStore = usePreferencesStore();
            const { resetToDefaults } = useLayout();

            preferencesStore.clear();
            resetToDefaults();
        },

        /**
         * Initialize auth state on app load
         */
        async initAuth() {
            if (this.token) {
                await this.fetchUser();
            }
        }
    }
});

export default useAuthStore;

import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { authApi } from '@/api';

const STORAGE_KEY = 'posip_user_preferences';

// Default preferences (matches backend User::DEFAULT_PREFERENCES)
const DEFAULT_PREFERENCES = {
    preset: 'Lara',
    primary: 'blue',
    surface: 'slate',
    dark_theme: false,
    menu_mode: 'static'
};

// Load cached preferences from localStorage
const loadCachedPreferences = () => {
    try {
        const cached = localStorage.getItem(STORAGE_KEY);
        if (cached) {
            return JSON.parse(cached);
        }
    } catch (e) {
        // Ignore parse errors
    }
    return { ...DEFAULT_PREFERENCES };
};

// Save preferences to localStorage
const saveCachedPreferences = (preferences) => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(preferences));
    } catch (e) {
        // Ignore storage errors
    }
};

// Clear cached preferences (on logout)
const clearCachedPreferences = () => {
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch (e) {
        // Ignore errors
    }
};

export const usePreferencesStore = defineStore('preferences', () => {
    // State - initialize from localStorage cache
    const preferences = ref(loadCachedPreferences());
    const loaded = ref(false);
    const loading = ref(false);
    const syncing = ref(false);

    // Debounce timer for API sync
    let syncTimeout = null;

    // Getters
    const preset = computed(() => preferences.value.preset || DEFAULT_PREFERENCES.preset);
    const primary = computed(() => preferences.value.primary || DEFAULT_PREFERENCES.primary);
    const surface = computed(() => preferences.value.surface || DEFAULT_PREFERENCES.surface);
    const darkTheme = computed(() => preferences.value.dark_theme ?? DEFAULT_PREFERENCES.dark_theme);
    const menuMode = computed(() => preferences.value.menu_mode || DEFAULT_PREFERENCES.menu_mode);

    // Fetch preferences from API (called after login)
    const fetchPreferences = async () => {
        if (loading.value) return;

        loading.value = true;
        try {
            const response = await authApi.getPreferences();
            if (response.data.success) {
                preferences.value = response.data.data.preferences;
                loaded.value = true;

                // Cache to localStorage for instant load on next visit
                saveCachedPreferences(preferences.value);
            }
        } catch (error) {
            console.error('Failed to fetch preferences:', error);
        } finally {
            loading.value = false;
        }
    };

    // Update preference locally and sync to API with debounce
    const updatePreference = (key, value) => {
        // Update local state immediately
        preferences.value = {
            ...preferences.value,
            [key]: value
        };

        // Save to localStorage immediately (instant persist)
        saveCachedPreferences(preferences.value);

        // Debounce API sync (500ms)
        if (syncTimeout) {
            clearTimeout(syncTimeout);
        }
        syncTimeout = setTimeout(() => {
            syncToApi();
        }, 500);
    };

    // Sync preferences to API
    const syncToApi = async () => {
        if (syncing.value) return;

        syncing.value = true;
        try {
            await authApi.updatePreferences(preferences.value);
        } catch (error) {
            console.error('Failed to sync preferences:', error);
        } finally {
            syncing.value = false;
        }
    };

    // Initialize preferences from user data (called after login)
    const initFromUser = (userPreferences) => {
        if (userPreferences) {
            preferences.value = userPreferences;
            saveCachedPreferences(userPreferences);
            loaded.value = true;
        }
    };

    // Clear preferences (called on logout)
    const clear = () => {
        preferences.value = { ...DEFAULT_PREFERENCES };
        clearCachedPreferences();
        loaded.value = false;
    };

    // Force refresh from API
    const refresh = async () => {
        loaded.value = false;
        await fetchPreferences();
    };

    return {
        // State
        preferences,
        loaded,
        loading,
        syncing,

        // Getters
        preset,
        primary,
        surface,
        darkTheme,
        menuMode,

        // Actions
        fetchPreferences,
        updatePreference,
        syncToApi,
        initFromUser,
        clear,
        refresh,

        // Constants
        DEFAULT_PREFERENCES
    };
});

import { computed, reactive } from 'vue';
import { usePreferencesStore } from '@/stores/preferences';

// Load initial values from localStorage for instant display (no flash)
const loadInitialPreferences = () => {
    try {
        const cached = localStorage.getItem('posip_user_preferences');
        if (cached) {
            return JSON.parse(cached);
        }
    } catch (e) {
        // Ignore
    }
    return {
        preset: 'Lara',
        primary: 'blue',
        surface: 'slate',
        dark_theme: false,
        menu_mode: 'static'
    };
};

const initialPrefs = loadInitialPreferences();

const layoutConfig = reactive({
    preset: initialPrefs.preset || 'Lara',
    primary: initialPrefs.primary || 'blue',
    surface: initialPrefs.surface || 'slate',
    darkTheme: initialPrefs.dark_theme ?? false,
    menuMode: initialPrefs.menu_mode || 'static'
});

const layoutState = reactive({
    staticMenuInactive: false,
    overlayMenuActive: false,
    profileSidebarVisible: false,
    configSidebarVisible: false,
    sidebarExpanded: false,
    menuHoverActive: false,
    activeMenuItem: null,
    activePath: null
});

// Apply dark mode class on init if needed
if (layoutConfig.darkTheme) {
    document.documentElement.classList.add('app-dark');
}

export function useLayout() {
    const preferencesStore = usePreferencesStore();

    const toggleDarkMode = () => {
        if (!document.startViewTransition) {
            executeDarkModeToggle();
            return;
        }

        document.startViewTransition(() => executeDarkModeToggle(event));
    };

    const executeDarkModeToggle = () => {
        layoutConfig.darkTheme = !layoutConfig.darkTheme;
        document.documentElement.classList.toggle('app-dark');

        // Sync to preferences store
        preferencesStore.updatePreference('dark_theme', layoutConfig.darkTheme);
    };

    const toggleMenu = () => {
        if (isDesktop()) {
            if (layoutConfig.menuMode === 'static') {
                layoutState.staticMenuInactive = !layoutState.staticMenuInactive;
            }

            if (layoutConfig.menuMode === 'overlay') {
                layoutState.overlayMenuActive = !layoutState.overlayMenuActive;
            }
        } else {
            layoutState.mobileMenuActive = !layoutState.mobileMenuActive;
        }
    };

    const toggleConfigSidebar = () => {
        layoutState.configSidebarVisible = !layoutState.configSidebarVisible;
    };

    const hideMobileMenu = () => {
        layoutState.mobileMenuActive = false;
    };

    const changeMenuMode = (event) => {
        layoutConfig.menuMode = event.value;
        layoutState.staticMenuInactive = false;
        layoutState.mobileMenuActive = false;
        layoutState.sidebarExpanded = false;
        layoutState.menuHoverActive = false;
        layoutState.anchored = false;

        // Sync to preferences store
        preferencesStore.updatePreference('menu_mode', layoutConfig.menuMode);
    };

    // Sync preset change to preferences store
    const changePreset = (preset) => {
        layoutConfig.preset = preset;
        preferencesStore.updatePreference('preset', preset);
    };

    // Sync primary color change to preferences store
    const changePrimary = (primary) => {
        layoutConfig.primary = primary;
        preferencesStore.updatePreference('primary', primary);
    };

    // Sync surface color change to preferences store
    const changeSurface = (surface) => {
        layoutConfig.surface = surface;
        preferencesStore.updatePreference('surface', surface);
    };

    // Apply preferences from store to layoutConfig (called after login/fetch)
    const applyPreferences = (prefs) => {
        if (!prefs) return;

        layoutConfig.preset = prefs.preset || 'Lara';
        layoutConfig.primary = prefs.primary || 'blue';
        layoutConfig.surface = prefs.surface || 'slate';
        layoutConfig.menuMode = prefs.menu_mode || 'static';

        // Handle dark theme
        const shouldBeDark = prefs.dark_theme ?? false;
        if (layoutConfig.darkTheme !== shouldBeDark) {
            layoutConfig.darkTheme = shouldBeDark;
            if (shouldBeDark) {
                document.documentElement.classList.add('app-dark');
            } else {
                document.documentElement.classList.remove('app-dark');
            }
        }
    };

    // Reset to defaults (called on logout)
    const resetToDefaults = () => {
        layoutConfig.preset = 'Lara';
        layoutConfig.primary = 'blue';
        layoutConfig.surface = 'slate';
        layoutConfig.menuMode = 'static';
        layoutConfig.darkTheme = false;
        document.documentElement.classList.remove('app-dark');
    };

    const isDarkTheme = computed(() => layoutConfig.darkTheme);
    const isDesktop = () => window.innerWidth > 991;

    const hasOpenOverlay = computed(() => layoutState.overlayMenuActive);

    return {
        layoutConfig,
        layoutState,
        isDarkTheme,
        toggleDarkMode,
        toggleConfigSidebar,
        toggleMenu,
        hideMobileMenu,
        changeMenuMode,
        changePreset,
        changePrimary,
        changeSurface,
        applyPreferences,
        resetToDefaults,
        isDesktop,
        hasOpenOverlay
    };
}

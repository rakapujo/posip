<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useLayout } from '@/layout/composables/layout';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { useConfirm } from 'primevue/useconfirm';
import AppConfigurator from './AppConfigurator.vue';

const router = useRouter();
const { toggleMenu, toggleDarkMode, isDarkTheme } = useLayout();
const authStore = useAuthStore();
const settingsStore = useSettingsStore();
const confirm = useConfirm();

const userMenuRef = ref();

const toggleUserMenu = (event) => {
    userMenuRef.value.toggle(event);
};

// Default avatar placeholder
const defaultAvatar = computed(() => {
    const name = authStore.displayName || 'U';
    const initial = name.charAt(0).toUpperCase();
    // Generate a color based on the name
    const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
    const colorIndex = name.charCodeAt(0) % colors.length;
    return { initial, color: colors[colorIndex] };
});

const userMenuItems = ref([
    {
        label: 'Edit Profile',
        icon: 'pi pi-user-edit',
        command: () => {
            // Navigate to edit own profile
            if (authStore.user?.ulid) {
                router.push(`/app/pengaturan/user?edit=${authStore.user.ulid}`);
            }
        }
    },
    {
        separator: true
    },
    {
        label: 'Logout',
        icon: 'pi pi-sign-out',
        command: () => {
            confirmLogout();
        }
    }
]);

const confirmLogout = () => {
    confirm.require({
        message: 'Apakah Anda yakin ingin keluar?',
        header: 'Konfirmasi Logout',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Batal',
            severity: 'secondary',
            outlined: true
        },
        acceptProps: {
            label: 'Logout',
            severity: 'danger'
        },
        accept: () => {
            authStore.logout();
        }
    });
};
</script>

<template>
    <div class="layout-topbar">
        <div class="layout-topbar-logo-container">
            <button class="layout-menu-button layout-topbar-action" @click="toggleMenu">
                <i class="pi pi-bars"></i>
            </button>
            <router-link to="/app" class="layout-topbar-logo !flex-nowrap">
                <img :src="settingsStore.storeLogo || '/logo.svg'" :alt="settingsStore.storeName" class="h-8 max-w-[120px] object-contain flex-shrink-0" />
                <span class="whitespace-nowrap">{{ settingsStore.storeName }}</span>
            </router-link>
        </div>

        <div class="layout-topbar-actions">
            <div class="layout-config-menu">
                <button type="button" class="layout-topbar-action" @click="toggleDarkMode">
                    <i :class="['pi', { 'pi-moon': isDarkTheme, 'pi-sun': !isDarkTheme }]"></i>
                </button>
                <div class="relative">
                    <button
                        v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'p-anchored-overlay-enter-active', leaveToClass: 'hidden', leaveActiveClass: 'p-anchored-overlay-leave-active', hideOnOutsideClick: true }"
                        type="button"
                        class="layout-topbar-action layout-topbar-action-highlight"
                    >
                        <i class="pi pi-palette"></i>
                    </button>
                    <AppConfigurator />
                </div>
            </div>

            <button
                class="layout-topbar-menu-button layout-topbar-action"
                v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'p-anchored-overlay-enter-active', leaveToClass: 'hidden', leaveActiveClass: 'p-anchored-overlay-leave-active', hideOnOutsideClick: true }"
            >
                <i class="pi pi-ellipsis-v"></i>
            </button>

            <div class="layout-topbar-menu hidden lg:block">
                <div class="layout-topbar-menu-content">
                    <!-- User Info & Menu -->
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium hidden xl:inline text-surface-700 dark:text-surface-100">
                            {{ authStore.displayName }}
                        </span>
                        <button type="button" class="layout-topbar-action !p-0 !w-10 !h-10 overflow-hidden" @click="toggleUserMenu" aria-haspopup="true" aria-controls="user_menu">
                            <img v-if="authStore.avatarUrl" :src="authStore.avatarUrl" :alt="authStore.displayName" class="w-full h-full rounded-full object-cover" />
                            <div v-else class="w-full h-full rounded-full flex items-center justify-center text-white font-semibold text-sm" :style="{ backgroundColor: defaultAvatar.color }">
                                {{ defaultAvatar.initial }}
                            </div>
                        </button>
                        <Menu ref="userMenuRef" id="user_menu" :model="userMenuItems" :popup="true">
                            <template #start>
                                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700">
                                    <p class="font-semibold">{{ authStore.displayName }}</p>
                                    <p class="text-sm text-muted-color">{{ authStore.user?.email }}</p>
                                    <Tag v-if="authStore.user?.roles?.length" :value="authStore.user.roles[0]" severity="info" class="mt-2" />
                                </div>
                            </template>
                        </Menu>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <ConfirmDialog />
</template>

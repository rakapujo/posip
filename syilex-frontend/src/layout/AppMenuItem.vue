<script setup>
import { useLayout } from '@/layout/composables/layout';
import { useRoute } from 'vue-router';
import { computed } from 'vue';

const { layoutState, isDesktop } = useLayout();
const route = useRoute();

const props = defineProps({
    item: {
        type: Object,
        default: () => ({})
    },
    root: {
        type: Boolean,
        default: true
    },
    parentPath: {
        type: String,
        default: null
    }
});

const fullPath = computed(() => (props.item.path ? (props.parentPath ? props.parentPath + props.item.path : props.item.path) : null));

/**
 * Check if current route matches any child routes recursively
 */
const hasActiveChild = computed(() => {
    if (!props.item.items) return false;

    const checkChildren = (items) => {
        for (const child of items) {
            // Check if child's 'to' matches current route
            if (child.to && route.path === child.to) {
                return true;
            }
            // Recursively check nested children
            if (child.items && checkChildren(child.items)) {
                return true;
            }
        }
        return false;
    };

    return checkChildren(props.item.items);
});

const isActive = computed(() => {
    // If any child route is active, keep this submenu open
    if (hasActiveChild.value) {
        return true;
    }
    // Check if activePath matches this item's path
    // Use exact match or startsWith with '/' to avoid partial matches
    // e.g., '/klasifikasi' should NOT match '/klasifikasi-customer'
    if (props.item.path) {
        const activePath = layoutState.activePath;
        if (!activePath) return false;
        // Exact match or nested path (with trailing slash)
        return activePath === fullPath.value || activePath.startsWith(fullPath.value + '/');
    }
    return layoutState.activePath === props.item.to;
});

const itemClick = (event, item) => {
    if (item.disabled) {
        event.preventDefault();
        return;
    }

    if (item.command) {
        item.command({ originalEvent: event, item: item });
    }

    if (item.items) {
        if (isActive.value && !hasActiveChild.value) {
            // Only close if no child is active
            layoutState.activePath = layoutState.activePath?.replace(item.path, '') || null;
        } else if (!isActive.value) {
            layoutState.activePath = fullPath.value;
            layoutState.menuHoverActive = true;
        }
    } else {
        layoutState.overlayMenuActive = false;
        layoutState.mobileMenuActive = false;
        layoutState.menuHoverActive = false;
    }
};

const onMouseEnter = () => {
    if (isDesktop() && props.root && props.item.items && layoutState.menuHoverActive) {
        layoutState.activePath = fullPath.value;
    }
};
</script>

<template>
    <li v-if="item.visible !== false" :class="{ 'layout-root-menuitem': root, 'active-menuitem': isActive }">
        <div v-if="root && item.visible !== false" class="layout-menuitem-root-text">{{ item.label }}</div>
        <a v-if="(!item.to || item.items) && item.visible !== false" :href="item.url" @click="itemClick($event, item)" :class="item.class" :target="item.target" tabindex="0" @mouseenter="onMouseEnter">
            <i :class="item.icon" class="layout-menuitem-icon" />
            <span class="layout-menuitem-text">{{ item.label }}</span>
            <i class="pi pi-fw pi-angle-down layout-submenu-toggler" v-if="item.items" />
        </a>
        <router-link v-if="item.to && !item.items && item.visible !== false" @click="itemClick($event, item)" exactActiveClass="active-route" :class="item.class" tabindex="0" :to="item.to" @mouseenter="onMouseEnter">
            <i :class="item.icon" class="layout-menuitem-icon" />
            <span class="layout-menuitem-text">{{ item.label }}</span>
            <i class="pi pi-fw pi-angle-down layout-submenu-toggler" v-if="item.items" />
        </router-link>
        <Transition v-if="item.items && item.visible !== false" name="layout-submenu">
            <ul v-show="root ? true : isActive" class="layout-submenu">
                <app-menu-item v-for="child in item.items" :key="child.label + '_' + (child.to || child.path)" :item="child" :root="false" :parentPath="fullPath" />
            </ul>
        </Transition>
    </li>
</template>

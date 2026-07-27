<script setup>
/**
 * Filter list seragam: desktop inline, mobile Dialog (opsi A).
 * Satu instance slot saja (matchMedia) — hindari double v-model.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    activeCount: { type: Number, default: 0 }
});

const visible = ref(false);
const isDesktop = ref(true);

let mql = null;
function syncDesktop() {
    isDesktop.value = !!mql?.matches;
}

onMounted(() => {
    mql = window.matchMedia('(min-width: 992px)');
    syncDesktop();
    mql.addEventListener('change', syncDesktop);
});

onUnmounted(() => {
    mql?.removeEventListener('change', syncDesktop);
});

const buttonLabel = computed(() => `Filter (${props.activeCount})`);
</script>

<template>
    <!-- Desktop -->
    <div v-if="isDesktop" class="list-filters">
        <slot />
    </div>

    <!-- Mobile -->
    <template v-else>
        <Button class="list-filters-trigger" :label="buttonLabel" icon="pi pi-filter" severity="secondary" outlined @click="visible = true" />
        <Dialog
            v-model:visible="visible"
            header="Filter"
            modal
            :style="{ width: 'min(420px, 95vw)' }"
            :breakpoints="{ '960px': '95vw' }"
        >
            <div class="list-filters list-filters--sheet">
                <slot />
            </div>
            <template #footer>
                <Button label="Tutup" severity="secondary" @click="visible = false" />
            </template>
        </Dialog>
    </template>
</template>

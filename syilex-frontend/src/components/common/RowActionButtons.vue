<script setup>
/** Tombol aksi baris tabel — wrapper seragam (flex gap-1). */
const props = defineProps({
    variant: { type: String, default: 'text' }, // 'text' | 'outlined' — dokumentasi; tombol di slot tetap set sendiri
    wrap: { type: Boolean, default: false }
});
</script>

<template>
    <div class="row-action-buttons flex gap-1 items-center" :class="props.wrap ? 'flex-wrap' : 'flex-nowrap'">
        <slot />
    </div>
</template>

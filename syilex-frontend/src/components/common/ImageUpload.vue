<script setup>
import { ref, computed, watch } from 'vue';
import { uploadsApi } from '@/api';
import { useToast } from 'primevue/usetoast';

import Button from 'primevue/button';
import ProgressSpinner from 'primevue/progressspinner';

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    folder: {
        type: String,
        required: true
    },
    label: {
        type: String,
        default: 'Upload Gambar'
    },
    accept: {
        type: String,
        default: 'image/*'
    },
    maxSize: {
        type: Number,
        default: 2048 // KB
    },
    previewWidth: {
        type: String,
        default: '150px'
    },
    previewHeight: {
        type: String,
        default: '150px'
    },
    showDelete: {
        type: Boolean,
        default: true
    },
    disabled: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:modelValue', 'uploaded', 'deleted', 'error']);

const toast = useToast();
const fileInput = ref(null);
const uploading = ref(false);
const dragOver = ref(false);

const imageUrl = computed(() => props.modelValue);

const hasImage = computed(() => !!props.modelValue);

const previewStyle = computed(() => ({
    width: props.previewWidth,
    height: props.previewHeight
}));

// Watch for external changes
watch(
    () => props.modelValue,
    () => {
        // Handle external value changes if needed
    }
);

const triggerFileInput = () => {
    if (!props.disabled && !uploading.value) {
        fileInput.value?.click();
    }
};

const handleFileSelect = async (event) => {
    const file = event.target.files?.[0];
    if (file) {
        await uploadFile(file);
    }
    // Reset input for re-selecting same file
    event.target.value = '';
};

const handleDrop = async (event) => {
    event.preventDefault();
    dragOver.value = false;

    if (props.disabled || uploading.value) return;

    const file = event.dataTransfer?.files?.[0];
    if (file && file.type.startsWith('image/')) {
        await uploadFile(file);
    }
};

const handleDragOver = (event) => {
    event.preventDefault();
    if (!props.disabled && !uploading.value) {
        dragOver.value = true;
    }
};

const handleDragLeave = () => {
    dragOver.value = false;
};

const uploadFile = async (file) => {
    // Validate file size
    const sizeInKb = file.size / 1024;
    if (sizeInKb > props.maxSize) {
        const maxSizeMb = (props.maxSize / 1024).toFixed(1);
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: `Ukuran file terlalu besar. Maksimal ${maxSizeMb}MB`,
            life: 3000
        });
        emit('error', { message: 'File size too large' });
        return;
    }

    uploading.value = true;

    try {
        const oldPath = props.modelValue || null;
        const response = await uploadsApi.upload(file, props.folder, oldPath);

        if (response.data.success) {
            const url = response.data.data.url;
            emit('update:modelValue', url);
            emit('uploaded', response.data.data);
            toast.add({
                severity: 'success',
                summary: 'Berhasil',
                detail: 'Gambar berhasil diupload',
                life: 3000
            });
        } else {
            throw new Error(response.data.message || 'Upload gagal');
        }
    } catch (error) {
        const message = error.response?.data?.message || error.message || 'Gagal mengupload gambar';
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: message,
            life: 3000
        });
        emit('error', { message });
    } finally {
        uploading.value = false;
    }
};

const deleteImage = async () => {
    if (!props.modelValue || props.disabled) return;

    try {
        await uploadsApi.delete(props.modelValue);
        emit('update:modelValue', '');
        emit('deleted');
        toast.add({
            severity: 'success',
            summary: 'Berhasil',
            detail: 'Gambar berhasil dihapus',
            life: 3000
        });
    } catch (error) {
        // Even if delete fails on server, clear the value
        emit('update:modelValue', '');
        emit('deleted');
    }
};
</script>

<template>
    <div class="image-upload-wrapper">
        <input ref="fileInput" type="file" :accept="accept" class="hidden" @change="handleFileSelect" />

        <!-- Upload Area -->
        <div
            class="upload-area"
            :class="{
                'has-image': hasImage,
                'drag-over': dragOver,
                disabled: disabled || uploading
            }"
            :style="previewStyle"
            @click="triggerFileInput"
            @drop="handleDrop"
            @dragover="handleDragOver"
            @dragleave="handleDragLeave"
        >
            <!-- Loading State -->
            <div v-if="uploading" class="upload-loading">
                <ProgressSpinner style="width: 40px; height: 40px" strokeWidth="4" />
            </div>

            <!-- Image Preview -->
            <img v-else-if="hasImage" :src="imageUrl" :alt="label" class="preview-image" />

            <!-- Empty State -->
            <div v-else class="upload-placeholder">
                <i class="pi pi-image text-3xl text-surface-400"></i>
                <span class="text-sm text-surface-500 mt-2">{{ label }}</span>
            </div>

            <!-- Hover Overlay -->
            <div v-if="!uploading && !disabled" class="upload-overlay">
                <i class="pi pi-upload text-2xl"></i>
                <span class="text-sm mt-1">{{ hasImage ? 'Ganti' : 'Upload' }}</span>
            </div>
        </div>

        <!-- Delete Button -->
        <Button v-if="hasImage && showDelete && !disabled" icon="pi pi-trash" severity="danger" size="small" text rounded class="delete-btn mt-2" @click.stop="deleteImage" :disabled="uploading" />
    </div>
</template>

<style scoped>
.image-upload-wrapper {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
}

.upload-area {
    position: relative;
    border: 2px dashed var(--p-surface-300);
    border-radius: 8px;
    cursor: pointer;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--p-surface-50);
    transition: all 0.2s ease;
}

.upload-area:hover:not(.disabled) {
    border-color: var(--p-primary-color);
    background: var(--p-surface-100);
}

.upload-area.drag-over {
    border-color: var(--p-primary-color);
    background: var(--p-primary-50);
}

.upload-area.has-image {
    border-style: solid;
    border-color: var(--p-surface-200);
}

.upload-area.disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.preview-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.upload-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    text-align: center;
}

.upload-loading {
    display: flex;
    align-items: center;
    justify-content: center;
}

.upload-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    color: white;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.upload-area:hover:not(.disabled) .upload-overlay {
    opacity: 1;
}

.delete-btn {
    margin-top: 0.5rem;
}
</style>

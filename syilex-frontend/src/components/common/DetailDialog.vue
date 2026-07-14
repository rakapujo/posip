<script setup>
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import ProgressSpinner from 'primevue/progressspinner';
import Divider from 'primevue/divider';
import DetailItem from './DetailItem.vue';

defineProps({
    visible: {
        type: Boolean,
        default: false
    },
    title: {
        type: String,
        default: 'Detail'
    },
    loading: {
        type: Boolean,
        default: false
    },
    width: {
        type: String,
        default: '500px'
    },
    // Audit trail data
    createdAt: {
        type: String,
        default: null
    },
    createdBy: {
        type: String,
        default: null
    },
    updatedAt: {
        type: String,
        default: null
    },
    updatedBy: {
        type: String,
        default: null
    },
    // Whether to show audit section
    showAudit: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['update:visible']);

function closeDialog() {
    emit('update:visible', false);
}
</script>

<template>
    <Dialog :visible="visible" @update:visible="$emit('update:visible', $event)" :style="{ width: width }" :header="title" :modal="true" :closable="!loading">
        <!-- Loading State -->
        <div v-if="loading" class="flex justify-center items-center py-8">
            <ProgressSpinner style="width: 50px; height: 50px" strokeWidth="4" />
        </div>

        <!-- Content -->
        <div v-else class="flex flex-col gap-4">
            <!-- Main content slot -->
            <slot name="content"></slot>

            <!-- Audit Trail Section -->
            <template v-if="showAudit && (createdAt || updatedAt)">
                <Divider />
                <div class="bg-surface-50 dark:bg-surface-800 rounded-lg p-4">
                    <h4 class="text-sm font-semibold text-surface-600 dark:text-surface-400 mb-3 flex items-center gap-2">
                        <i class="pi pi-history"></i>
                        Informasi Audit
                    </h4>
                    <div class="grid grid-cols-2 gap-4">
                        <DetailItem label="Dibuat pada" :value="createdAt" type="datetime" :user="createdBy" />
                        <DetailItem label="Diubah pada" :value="updatedAt" type="datetime" :user="updatedBy" />
                    </div>
                </div>
            </template>
        </div>

        <template #footer>
            <div class="flex justify-between w-full">
                <div class="flex gap-2">
                    <slot name="footer-extra"></slot>
                </div>
                <Button label="Tutup" icon="pi pi-times" text @click="closeDialog" />
            </div>
        </template>
    </Dialog>
</template>

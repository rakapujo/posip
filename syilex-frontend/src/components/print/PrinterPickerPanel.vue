<script setup>
import { ref, computed, onMounted } from 'vue';
import { usePrintAdapter } from '@/composables/print/usePrintAdapter';
import { isStoredForTerminal } from '@/composables/print/printStorage';

const props = defineProps({
    terminalUlid: {
        type: String,
        default: null
    },
    compact: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['connected', 'disconnected', 'error']);

const printAdapter = usePrintAdapter();
const picking = ref(false);

const support = computed(() => printAdapter.support.value);
const supported = computed(() => printAdapter.supported.value);
const printerLabel = computed(() => printAdapter.printerLabel.value);
const isPaired = computed(() => isStoredForTerminal(props.terminalUlid));

onMounted(async () => {
    await printAdapter.checkStatus();
    if (isPaired.value) {
        try {
            await printAdapter.reconnect();
        } catch {
            /* silent — user can pair again */
        }
    }
});

async function pickKind(kind) {
    picking.value = true;
    try {
        await printAdapter.pick(kind, { terminalUlid: props.terminalUlid || undefined });
        emit('connected', { kind, label: printAdapter.printerLabel.value });
    } catch (e) {
        emit('error', e?.message || 'Gagal memasangkan printer');
    } finally {
        picking.value = false;
    }
}

function forget() {
    printAdapter.forget();
    emit('disconnected');
}

defineExpose({
    reconnect: () => printAdapter.reconnect(),
    printAdapter
});
</script>

<template>
    <div class="rounded-lg border border-surface-200 dark:border-surface-700 p-4 space-y-3">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="font-medium">Printer Thermal (Browser)</div>
                <p class="text-sm text-muted-color mt-1">Chrome/Edge — Bluetooth, USB Serial, atau WebUSB. Firefox/Safari: gunakan PDF.</p>
            </div>
            <Tag v-if="printerLabel" severity="success" :value="printerLabel" />
            <Tag v-else-if="!supported" severity="warn" value="Browser tidak mendukung" />
            <Tag v-else severity="secondary" value="Belum dipasangkan" />
        </div>

        <div v-if="!supported" class="text-sm text-orange-600 dark:text-orange-400">
            Web Serial / WebUSB / Web Bluetooth tidak tersedia. Struk tetap bisa dicetak via PDF.
        </div>

        <div v-else class="flex flex-wrap gap-2">
            <Button
                v-if="support.bluetooth"
                label="Bluetooth"
                icon="pi pi-bluetooth"
                size="small"
                :loading="picking"
                :disabled="picking"
                @click="pickKind('bluetooth')"
            />
            <Button
                v-if="support.serial"
                label="USB Serial"
                icon="pi pi-link"
                size="small"
                severity="secondary"
                :loading="picking"
                :disabled="picking"
                @click="pickKind('serial')"
            />
            <Button
                v-if="support.usb"
                label="WebUSB"
                icon="pi pi-usb"
                size="small"
                severity="help"
                :loading="picking"
                :disabled="picking"
                @click="pickKind('usb')"
            />
            <Button v-if="isPaired || printerLabel" label="Lupakan" icon="pi pi-times" size="small" severity="danger" text :disabled="picking" @click="forget" />
        </div>

        <p v-if="!compact" class="text-xs text-muted-color mb-0">
            Pairing disimpan per browser (localStorage). Legacy Print Service (:5123) masih bisa dipakai jika `default_printer` terminal diisi ID Windows/Network.
        </p>
    </div>
</template>

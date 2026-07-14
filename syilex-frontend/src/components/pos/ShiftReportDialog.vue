<script setup>
import { computed } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useSettingsStore } from '@/stores/settings';

const props = defineProps({
    visible: { type: Boolean, default: false },
    data: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    closable: { type: Boolean, default: true },
    // Editable mode: render input fields for uang fisik + catatan.
    // Used saat menutup shift (kasir) atau force close (admin) sebelum
    // commit ke DB. Parent binds saldoFisik + closingNotes via v-model.
    editable: { type: Boolean, default: false },
    saldoFisik: { type: [Number, String], default: null },
    closingNotes: { type: String, default: '' }
});

const emit = defineEmits(['update:visible', 'update:saldoFisik', 'update:closingNotes', 'print', 'download', 'close']);

const saldoFisikModel = computed({
    get: () => props.saldoFisik,
    set: (v) => emit('update:saldoFisik', v)
});
const closingNotesModel = computed({
    get: () => props.closingNotes,
    set: (v) => emit('update:closingNotes', v)
});

// Baseline untuk selisih:
//  - Editable mode (pre-close): pakai data.kas.saldo (fresh compute)
//  - Read-only mode (post-close): pakai data.shift.saldo_system (persisted)
const saldoBaseline = computed(() => {
    if (props.editable) return Number(props.data?.kas?.saldo || 0);
    return Number(props.data?.shift?.saldo_system || 0);
});

// Live selisih saat editable — fisik - baseline
const selisihLive = computed(() => {
    if (!props.editable) return null;
    const v = saldoFisikModel.value;
    if (v === null || v === undefined || v === '') return null;
    return Number(v) - saldoBaseline.value;
});

// Total biaya pembayaran = sum fee tambahan semua metode
const totalBiayaPembayaran = computed(() => {
    return (props.data?.payment_breakdown || []).reduce((sum, pb) => sum + Number(pb.biaya_tambahan || 0), 0);
});

const { formatDateTime, formatCurrency } = useFormatters();
const settingsStore = useSettingsStore();

const dialogVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val)
});

const dialogHeader = computed(() => {
    if (props.data?.shift?.ended_by_force) return 'LAPORAN SHIFT (Tutup Paksa)';
    return 'LAPORAN SHIFT';
});

const getShiftCloseStatusText = (shift) => {
    if (!shift?.ended_at) return 'Masih Aktif';
    if (shift.ended_by_force) return `Ditutup Paksa oleh ${shift.forced_by_user?.name || 'Admin'}`;
    return 'Ditutup Normal';
};

const getStatusSeverity = (shift) => {
    if (!shift?.ended_at) return 'info';
    if (shift.ended_by_force) return 'danger';
    return 'success';
};

// Shortcut alias — data.penjualan is used heavily below
const p = computed(() => props.data?.penjualan || {});
</script>

<template>
    <Dialog v-model:visible="dialogVisible" :header="dialogHeader" modal :style="{ width: '540px', maxHeight: '90vh' }" :closable="closable" :contentStyle="{ overflowY: 'auto' }">
        <!-- Loading State -->
        <div v-if="loading" class="text-center py-8">
            <i class="pi pi-spin pi-spinner text-2xl"></i>
        </div>

        <!-- Missing Shift Data -->
        <div v-else-if="data && !data.shift" class="text-center py-8 text-orange-600">
            <i class="pi pi-exclamation-triangle text-2xl mb-2"></i>
            <p>Data shift tidak tersedia</p>
        </div>

        <!-- Report Content -->
        <div v-else-if="data && data.shift" class="text-sm">
            <!-- Store Header -->
            <div class="text-center pb-4 mb-4 border-b border-dashed border-surface-300 dark:border-surface-600">
                <div class="font-bold text-base">{{ settingsStore.store.name }}</div>
                <div v-if="settingsStore.store.address" class="text-xs text-surface-500 mt-0.5">{{ settingsStore.store.address }}</div>
                <div v-if="settingsStore.store.phone" class="text-xs text-surface-500">Telp: {{ settingsStore.store.phone }}</div>
                <div class="font-bold mt-3">LAPORAN SHIFT</div>
                <div class="text-xs text-surface-400 font-mono mt-0.5">{{ data.shift?.ulid }}</div>
            </div>

            <!-- Shift Info -->
            <div class="space-y-1.5 pb-4 mb-4 border-b border-dashed border-surface-300 dark:border-surface-600">
                <div>
                    Terminal: <strong>{{ data.shift?.terminal?.kode_terminal }}</strong> — {{ data.shift?.terminal?.nama_terminal }}
                </div>
                <div>
                    Kasir: <strong>{{ data.shift?.user?.name }}</strong>
                </div>
                <div>Mulai: {{ formatDateTime(data.shift?.started_at) }}</div>
                <div>Selesai: {{ data.shift?.ended_at ? formatDateTime(data.shift.ended_at) : '-' }}</div>
                <div>
                    Status:
                    <Tag :value="getShiftCloseStatusText(data.shift)" :severity="getStatusSeverity(data.shift)" />
                </div>
            </div>

            <!-- ═══ PENJUALAN ═══ -->
            <div class="pb-4 mb-4 border-b border-dashed border-surface-300 dark:border-surface-600">
                <div class="flex justify-between font-bold mb-3">
                    <span>PENJUALAN</span>
                    <span>{{ p.jumlah_transaksi || 0 }} trx</span>
                </div>

                <div class="space-y-1.5">
                    <div class="flex justify-between">
                        <span>Penjualan Kotor</span>
                        <span>{{ formatCurrency(p.penjualan_kotor) }}</span>
                    </div>

                    <!-- Diskon Item (line discount breakdown) -->
                    <div class="flex justify-between" :class="{ 'text-red-500': Number(p.diskon_item) > 0 }">
                        <span>Diskon Item</span>
                        <span>{{ Number(p.diskon_item) > 0 ? '-' : '' }}{{ formatCurrency(p.diskon_item) }}</span>
                    </div>
                    <template v-if="Number(p.diskon_item) > 0">
                        <div v-if="Number(p.diskon_line_1) > 0" class="flex justify-between text-red-400 text-xs pl-4">
                            <span>Line 1</span><span>-{{ formatCurrency(p.diskon_line_1) }}</span>
                        </div>
                        <div v-if="Number(p.diskon_line_2) > 0" class="flex justify-between text-red-400 text-xs pl-4">
                            <span>Line 2</span><span>-{{ formatCurrency(p.diskon_line_2) }}</span>
                        </div>
                        <div v-if="Number(p.diskon_line_3) > 0" class="flex justify-between text-red-400 text-xs pl-4">
                            <span>Line 3</span><span>-{{ formatCurrency(p.diskon_line_3) }}</span>
                        </div>
                        <div v-if="Number(p.diskon_line_4) > 0" class="flex justify-between text-red-400 text-xs pl-4">
                            <span>Line 4</span><span>-{{ formatCurrency(p.diskon_line_4) }}</span>
                        </div>
                        <div v-if="Number(p.diskon_line_5) > 0" class="flex justify-between text-red-400 text-xs pl-4">
                            <span>Manual (Line 5)</span><span>-{{ formatCurrency(p.diskon_line_5) }}</span>
                        </div>
                    </template>

                    <!-- Diskon Nota (header discount breakdown) -->
                    <div class="flex justify-between" :class="{ 'text-red-500': Number(p.diskon_nota) > 0 }">
                        <span>Diskon Nota</span>
                        <span>{{ Number(p.diskon_nota) > 0 ? '-' : '' }}{{ formatCurrency(p.diskon_nota) }}</span>
                    </div>
                    <template v-if="Number(p.diskon_nota) > 0">
                        <div v-if="Number(p.diskon_nota_l1) > 0" class="flex justify-between text-red-400 text-xs pl-4">
                            <span>Tipe Customer (L1)</span><span>-{{ formatCurrency(p.diskon_nota_l1) }}</span>
                        </div>
                        <div v-if="Number(p.diskon_nota_l2) > 0" class="flex justify-between text-red-400 text-xs pl-4">
                            <span>Kategori Customer (L2)</span><span>-{{ formatCurrency(p.diskon_nota_l2) }}</span>
                        </div>
                        <div v-if="Number(p.diskon_nota_l3) > 0" class="flex justify-between text-red-400 text-xs pl-4">
                            <span>Manual Kasir (L3)</span><span>-{{ formatCurrency(p.diskon_nota_l3) }}</span>
                        </div>
                    </template>

                    <!-- Penjualan Bersih -->
                    <div class="flex justify-between">
                        <span>Penjualan Bersih</span>
                        <span>{{ formatCurrency(p.penjualan_bersih) }}</span>
                    </div>

                    <!-- Biaya & Pajak -->
                    <div class="flex justify-between">
                        <span>Biaya Kirim</span>
                        <span>{{ formatCurrency(p.biaya_kirim) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Biaya Lain</span>
                        <span>{{ formatCurrency(p.biaya_lain) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Pajak{{ p.pajak_nama ? ` (${p.pajak_nama} ${p.pajak_persen}%)` : '' }}</span>
                        <span>{{ formatCurrency(p.pajak_nominal) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Pembulatan</span>
                        <span>{{ formatCurrency(p.pembulatan) }}</span>
                    </div>
                    <div v-if="totalBiayaPembayaran > 0" class="flex justify-between">
                        <span>Biaya Pembayaran</span>
                        <span>{{ formatCurrency(totalBiayaPembayaran) }}</span>
                    </div>
                </div>

                <!-- OMZET -->
                <div class="flex justify-between font-bold text-base mt-3 pt-3 border-t border-surface-300 dark:border-surface-600">
                    <span>OMZET</span>
                    <span>{{ formatCurrency(p.omzet) }}</span>
                </div>
            </div>

            <!-- ═══ UNIT SERIAL TERJUAL ═══ -->
            <!-- Hanya tampil kalau ada unit serial yang terjual di sesi ini -->
            <div v-if="data.serial_units_sold?.length" class="pb-4 mb-4 border-b border-dashed border-surface-300 dark:border-surface-600">
                <div class="flex justify-between font-bold mb-3">
                    <span>UNIT SERIAL TERJUAL</span>
                    <span>{{ data.serial_units_sold.length }} unit</span>
                </div>
                <div class="space-y-2">
                    <div v-for="(u, i) in data.serial_units_sold" :key="'su' + i" class="pb-2 border-b border-surface-200 dark:border-surface-700 last:border-0 last:pb-0">
                        <div class="flex justify-between gap-2">
                            <span class="font-medium">{{ u.product || '-' }}</span>
                            <span class="font-medium whitespace-nowrap">{{ formatCurrency(u.harga) }}</span>
                        </div>
                        <div class="text-xs text-surface-500 mt-0.5">
                            <span v-if="u.kode_internal" class="font-mono">{{ u.kode_internal }} · </span>
                            <span class="font-mono">SN: {{ u.serial_number || '-' }}</span>
                            <span> · {{ u.nomor_dokumen || '-' }}</span>
                        </div>
                        <div class="text-xs text-surface-500 mt-0.5 flex flex-wrap gap-x-3">
                            <span v-if="u.grade">Grade: {{ u.grade }}</span>
                            <span v-if="u.battery_health !== null && u.battery_health !== undefined">Baterai: {{ u.battery_health }}%</span>
                            <span v-if="u.account_status">Akun: {{ u.account_status }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ PER METODE BAYAR ═══ -->
            <div class="pb-4 mb-4 border-b border-dashed border-surface-300 dark:border-surface-600">
                <div class="font-bold mb-3">PER METODE BAYAR</div>
                <div class="space-y-1.5">
                    <template v-for="pb in data.payment_breakdown" :key="pb.nama">
                        <div class="flex justify-between">
                            <span>{{ pb.nama }} ({{ pb.count }}x)</span>
                            <span>{{ formatCurrency(pb.total) }}</span>
                        </div>
                        <template v-if="pb.is_tunai && Number(data.total_kembalian) > 0">
                            <div class="flex justify-between text-xs pl-4 text-surface-500">
                                <span>Kembalian</span><span>-{{ formatCurrency(data.total_kembalian) }}</span>
                            </div>
                            <div class="flex justify-between text-xs pl-4 font-medium">
                                <span>Nett Tunai</span><span>{{ formatCurrency(pb.total - data.total_kembalian) }}</span>
                            </div>
                        </template>
                        <div v-if="Number(pb.biaya_tambahan) > 0" class="flex justify-between text-xs pl-4 text-surface-500">
                            <span>Biaya</span><span>{{ formatCurrency(pb.biaya_tambahan) }}</span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ═══ VOID ═══ -->
            <div class="pb-4 mb-4 border-b border-dashed border-surface-300 dark:border-surface-600">
                <div class="flex justify-between font-bold mb-3">
                    <span>VOID</span>
                    <span>{{ data.void?.jumlah || 0 }} trx</span>
                </div>
                <div class="flex justify-between">
                    <span>Nominal Void</span>
                    <span>{{ formatCurrency(data.void?.nominal) }}</span>
                </div>
            </div>

            <!-- ═══ RETUR ═══ -->
            <div class="pb-4 mb-4 border-b border-dashed border-surface-300 dark:border-surface-600">
                <div class="flex justify-between font-bold mb-3">
                    <span>RETUR</span>
                    <span>{{ data.retur?.jumlah || 0 }} trx</span>
                </div>
                <div class="space-y-1.5">
                    <div class="flex justify-between">
                        <span>Total Refund</span>
                        <span>{{ formatCurrency(data.retur?.total_refund) }}</span>
                    </div>
                    <template v-if="Number(data.retur?.total_refund) > 0">
                        <div class="flex justify-between text-xs pl-4 text-surface-500">
                            <span>Sesi Ini ({{ data.retur?.sesi_ini?.jumlah || 0 }})</span>
                            <span>{{ formatCurrency(data.retur?.sesi_ini?.nominal) }}</span>
                        </div>
                        <div class="flex justify-between text-xs pl-4 text-surface-500">
                            <span>Sesi Sebelumnya ({{ data.retur?.sesi_sebelumnya?.jumlah || 0 }})</span>
                            <span>{{ formatCurrency(data.retur?.sesi_sebelumnya?.nominal) }}</span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ═══ KAS ═══ -->
            <div class="pb-4 mb-4 border-b border-dashed border-surface-300 dark:border-surface-600">
                <div class="font-bold mb-3">KAS (Uang Fisik di Laci)</div>
                <div class="space-y-1.5">
                    <div class="flex justify-between">
                        <span>Setor Awal</span>
                        <span>{{ formatCurrency(data.kas?.setor_awal) }}</span>
                    </div>
                    <div class="flex justify-between text-green-600">
                        <span>Penjualan Tunai (net)</span>
                        <span>+{{ formatCurrency(data.kas?.penjualan_tunai) }}</span>
                    </div>

                    <!-- Kas Masuk (with detail breakdown) -->
                    <div class="flex justify-between" :class="{ 'text-green-600': Number(data.kas?.kas_masuk) > 0 }">
                        <span>Kas Masuk{{ data.kas?.kas_masuk_detail?.length ? ` (${data.kas.kas_masuk_detail.length}x)` : '' }}</span>
                        <span>{{ Number(data.kas?.kas_masuk) > 0 ? '+' : '' }}{{ formatCurrency(data.kas?.kas_masuk) }}</span>
                    </div>
                    <template v-if="data.kas?.kas_masuk_detail?.length">
                        <div v-for="(item, i) in data.kas.kas_masuk_detail" :key="'km' + i" class="flex justify-between text-xs pl-4 text-surface-500">
                            <span>{{ item.keterangan || '-' }}</span>
                            <span>+{{ formatCurrency(item.nominal) }}</span>
                        </div>
                    </template>

                    <!-- Kas Keluar (with detail breakdown) -->
                    <div class="flex justify-between" :class="{ 'text-red-500': Number(data.kas?.kas_keluar) > 0 }">
                        <span>Kas Keluar{{ data.kas?.kas_keluar_detail?.length ? ` (${data.kas.kas_keluar_detail.length}x)` : '' }}</span>
                        <span>{{ Number(data.kas?.kas_keluar) > 0 ? '-' : '' }}{{ formatCurrency(data.kas?.kas_keluar) }}</span>
                    </div>
                    <template v-if="data.kas?.kas_keluar_detail?.length">
                        <div v-for="(item, i) in data.kas.kas_keluar_detail" :key="'kk' + i" class="flex justify-between text-xs pl-4 text-surface-500">
                            <span>{{ item.keterangan || '-' }}</span>
                            <span>-{{ formatCurrency(item.nominal) }}</span>
                        </div>
                    </template>

                    <!-- Refund Retur -->
                    <div class="flex justify-between" :class="{ 'text-red-500': Number(data.kas?.refund_tunai) > 0 }">
                        <span>Refund Retur (Cash)</span>
                        <span>{{ Number(data.kas?.refund_tunai) > 0 ? '-' : '' }}{{ formatCurrency(data.kas?.refund_tunai) }}</span>
                    </div>
                </div>

                <!-- Saldo Kas -->
                <div class="flex justify-between font-bold text-base mt-3 pt-3 border-t border-surface-300 dark:border-surface-600">
                    <span>Saldo Kas</span>
                    <span>{{ formatCurrency(data.kas?.saldo) }}</span>
                </div>
            </div>

            <!-- ═══ REKONSILIASI ═══ -->
            <!-- Editable mode: kasir/admin isi uang fisik + catatan sebelum commit
                 Read-only mode: tampil data post-close dari DB -->
            <div v-if="editable || data.shift?.ended_at" class="pb-4 mb-4 border-b border-dashed border-surface-300 dark:border-surface-600">
                <div class="font-bold mb-3">REKONSILIASI KAS</div>

                <!-- EDITABLE MODE: form input -->
                <div v-if="editable" class="space-y-2">
                    <div class="flex justify-between">
                        <span>Saldo Sistem</span>
                        <span class="font-medium">{{ formatCurrency(saldoBaseline) }}</span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1"> Uang Fisik di Laci <span class="text-red-500">*</span> </label>
                        <InputNumber v-model="saldoFisikModel" :min="0" :minFractionDigits="0" placeholder="Hitung uang di laci kas" class="w-full" inputClass="w-full" autofocus />
                        <small class="text-surface-500">Wajib diisi untuk menutup shift</small>
                    </div>
                    <div v-if="selisihLive !== null" class="flex justify-between font-bold pt-1" :class="selisihLive === 0 ? 'text-green-600' : selisihLive > 0 ? 'text-blue-600' : 'text-red-500'">
                        <span>Selisih</span>
                        <span>
                            {{ selisihLive > 0 ? '+' : '' }}{{ formatCurrency(selisihLive) }}
                            <span v-if="selisihLive === 0" class="text-xs">(Cocok)</span>
                            <span v-else-if="selisihLive > 0" class="text-xs">(Lebih)</span>
                            <span v-else class="text-xs">(Kurang)</span>
                        </span>
                    </div>
                    <div class="pt-1">
                        <label class="block text-sm font-medium mb-1">Catatan (opsional)</label>
                        <Textarea v-model="closingNotesModel" rows="2" class="w-full" placeholder="Misal: lebih 2rb karena uang receh tambahan" maxlength="1000" />
                    </div>
                </div>

                <!-- READ-ONLY MODE: tampil data post-close -->
                <div v-else class="space-y-1.5">
                    <div class="flex justify-between">
                        <span>Saldo Sistem</span>
                        <span>{{ formatCurrency(data.shift?.saldo_system) }}</span>
                    </div>

                    <template v-if="data.shift?.saldo_fisik !== null && data.shift?.saldo_fisik !== undefined">
                        <div class="flex justify-between">
                            <span>Uang Fisik di Laci</span>
                            <span class="font-medium">{{ formatCurrency(data.shift?.saldo_fisik) }}</span>
                        </div>
                        <div class="flex justify-between font-bold" :class="Number(data.shift?.selisih) === 0 ? 'text-green-600' : Number(data.shift?.selisih) > 0 ? 'text-blue-600' : 'text-red-500'">
                            <span>Selisih</span>
                            <span>
                                {{ Number(data.shift?.selisih) > 0 ? '+' : '' }}{{ formatCurrency(data.shift?.selisih) }}
                                <span v-if="Number(data.shift?.selisih) === 0" class="text-xs">(Cocok)</span>
                                <span v-else-if="Number(data.shift?.selisih) > 0" class="text-xs">(Lebih)</span>
                                <span v-else class="text-xs">(Kurang)</span>
                            </span>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex justify-between text-surface-400 italic">
                            <span>Uang Fisik di Laci</span>
                            <span>Belum di-input oleh kasir</span>
                        </div>
                    </template>

                    <div v-if="data.shift?.closing_notes" class="pt-2 border-t border-surface-200 dark:border-surface-700 mt-2">
                        <div class="text-xs text-surface-500">Catatan:</div>
                        <div class="text-xs mt-0.5 whitespace-pre-wrap">{{ data.shift.closing_notes }}</div>
                    </div>
                </div>
            </div>

            <!-- ═══ RINGKASAN AKHIR ═══ -->
            <div>
                <div class="font-bold mb-3">RINGKASAN AKHIR</div>
                <div class="space-y-2">
                    <div class="flex justify-between text-lg">
                        <span>Total Tunai (Saldo Kas)</span>
                        <span class="font-bold text-green-600">{{ formatCurrency(data.ringkasan?.total_tunai) }}</span>
                    </div>
                    <div class="flex justify-between text-lg">
                        <span>Total Non-Tunai</span>
                        <span class="font-bold text-blue-600">{{ formatCurrency(data.ringkasan?.total_non_tunai) }}</span>
                    </div>
                </div>
                <div class="flex justify-between text-xl font-bold mt-4 pt-3 border-t-2 border-primary">
                    <span>TOTAL SEMUA</span>
                    <span class="text-primary">{{ formatCurrency(data.ringkasan?.total_semua) }}</span>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <template #footer>
            <slot name="footer">
                <Button label="Print" icon="pi pi-print" severity="secondary" @click="$emit('print')" />
                <Button label="Download PDF" icon="pi pi-file-pdf" severity="secondary" @click="$emit('download')" />
                <Button label="Tutup" icon="pi pi-times" @click="$emit('close')" />
            </slot>
        </template>
    </Dialog>
</template>

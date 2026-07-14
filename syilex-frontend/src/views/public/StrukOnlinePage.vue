<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import { useReceiptPdf } from '@/composables/useReceiptPdf';

const route = useRoute();
const loading = ref(true);
const error = ref(false);
const sales = ref(null);
const store = ref(null);
const receiptStatus = ref(null);
const returPolicy = ref(null);

const apiBase = import.meta.env.VITE_API_URL || '/api/v1';
const { formatDiscLine, downloadReceiptPdf, buildReturPolicyText } = useReceiptPdf({
    storeOverride: () => ({
        name: store.value?.name || 'POSIP',
        address: store.value?.address || '',
        phone: store.value?.phone || '',
        email: store.value?.email || '',
        npwp: store.value?.npwp || '',
        receiptFooter: store.value?.receipt_footer || 'Terima Kasih!'
    })
});

// Split receipt footer into lines for template render
const footerLines = computed(() => {
    const raw = store.value?.receipt_footer || 'Terima Kasih!';
    return String(raw)
        .split(/\r?\n/)
        .filter((l) => l.trim().length > 0);
});

// Retur policy line — derived via shared composable so thermal/PDF/online all match
const returPolicyText = computed(() => {
    if (!sales.value) return '';
    return buildReturPolicyText(returPolicy.value, sales.value.tanggal);
});

onMounted(async () => {
    try {
        const res = await axios.get(`${apiBase}/public/receipt/${route.params.ulid}`);
        sales.value = res.data.data?.sales;
        store.value = res.data.data?.store;
        receiptStatus.value = res.data.data?.receipt_status;
        returPolicy.value = res.data.data?.retur_policy ?? null;
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
});

// Watermark config per status
const watermark = computed(() => {
    const map = {
        voided: { text: 'VOID', color: '#ef4444' },
        completed: { text: 'LUNAS', color: '#22c55e' },
        retur_partial: { text: 'RETUR PARTIAL', color: '#ef4444' },
        retur_full: { text: 'RETUR FULL', color: '#ef4444' }
    };
    return map[receiptStatus.value] || null;
});

// Status banner config
const statusBanner = computed(() => {
    const map = {
        voided: { label: 'TRANSAKSI DIBATALKAN', bg: '#fef2f2', border: '#fecaca', textColor: '#dc2626', icon: '✕' },
        completed: { label: 'TRANSAKSI SELESAI', bg: '#f0fdf4', border: '#bbf7d0', textColor: '#16a34a', icon: '✓' },
        retur_partial: { label: 'RETUR SEBAGIAN', bg: '#fffbeb', border: '#fde68a', textColor: '#d97706', icon: '⟲' },
        retur_full: { label: 'RETUR PENUH', bg: '#fef2f2', border: '#fecaca', textColor: '#dc2626', icon: '⟲' }
    };
    return map[receiptStatus.value] || null;
});

// Simple formatters (no dependency on composables since this is a public page)
const formatCurrency = (val) => {
    const num = Number(val || 0);
    return 'Rp ' + num.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
};

const formatQty = (val) => {
    const num = Number(val || 0);
    return num % 1 === 0 ? num.toString() : num.toFixed(2);
};

const formatPercent = (val) => {
    const num = Number(val || 0);
    return num.toFixed(2).replace('.', ',') + '%';
};

const formatDateTime = (val) => {
    if (!val) return '';
    const d = new Date(val);
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' + d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
};

const bruto = (detail) => Number(detail.qty) * Number(detail.harga_satuan);

const downloadPdf = () =>
    downloadReceiptPdf(sales.value, {
        receiptStatus: receiptStatus.value,
        returPolicy: returPolicy.value
    });
</script>

<template>
    <div class="min-h-screen bg-gradient-to-b from-slate-100 to-slate-200 flex items-start justify-center py-6 px-4">
        <!-- Loading -->
        <div v-if="loading" class="text-center py-20">
            <div class="animate-spin inline-block w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full"></div>
            <p class="mt-3 text-slate-500">Memuat struk...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error || !sales" class="bg-white rounded-2xl shadow-lg p-8 text-center max-w-sm w-full">
            <div class="text-5xl mb-4">🧾</div>
            <h2 class="text-xl font-bold text-slate-700 mb-2">Struk Tidak Ditemukan</h2>
            <p class="text-slate-500">Link struk tidak valid atau sudah tidak tersedia.</p>
        </div>

        <!-- Receipt -->
        <div v-else class="receipt-wrapper bg-white rounded-2xl shadow-xl max-w-md w-full overflow-hidden relative">
            <!-- Watermark -->
            <div v-if="watermark" class="watermark" :style="{ color: watermark.color }">
                {{ watermark.text }}
            </div>

            <!-- Header -->
            <div class="bg-slate-700 px-6 py-5 text-center relative z-10" style="color: #fff">
                <img v-if="store?.logo_url" :src="store.logo_url" alt="Logo" class="h-10 mx-auto mb-2" />
                <h1 class="text-xl font-bold" style="color: #fff">{{ store?.name || 'POSIP' }}</h1>
                <p v-if="store?.address" class="text-sm mt-0.5" style="color: #cbd5e1">{{ store.address }}</p>
                <div v-if="store?.phone || store?.email" class="text-xs mt-0.5" style="color: #94a3b8">
                    <span v-if="store?.phone">{{ store.phone }}</span>
                    <span v-if="store?.phone && store?.email"> | </span>
                    <span v-if="store?.email">{{ store.email }}</span>
                </div>
                <div v-if="store?.npwp" class="text-xs mt-0.5" style="color: #94a3b8">NPWP: {{ store.npwp }}</div>
            </div>

            <div class="px-6 py-4 relative z-10">
                <!-- Transaction Info -->
                <div class="flex justify-between text-sm text-slate-500 border-b border-dashed border-slate-200 pb-3 mb-3">
                    <div>
                        <div class="font-semibold text-slate-700">{{ sales.nomor_dokumen }}</div>
                        <div>{{ formatDateTime(sales.tanggal) }}</div>
                    </div>
                    <div class="text-right">
                        <div>{{ sales.customer?.nama || 'Walk-in' }}</div>
                        <div v-if="sales.created_by?.name" class="text-xs text-slate-400">Kasir: {{ sales.created_by.name }}</div>
                    </div>
                </div>

                <!-- Items -->
                <div class="space-y-2 mb-3">
                    <div v-for="detail in sales.details" :key="detail.id" class="text-sm">
                        <div class="font-medium text-slate-700">{{ detail.product?.nama_produk }}</div>
                        <div v-if="detail.serial_units?.length" class="text-xs text-slate-400 leading-snug mt-0.5 mb-1 space-y-0.5">
                            <div v-for="(u, ui) in detail.serial_units" :key="ui">
                                <template v-if="u.kode_internal">{{ u.kode_internal }} · </template>SN {{ u.serial_number }}<template v-if="u.grade"> ({{ u.grade }})</template
                                ><template v-if="u.battery_health || u.battery_condition">
                                    · 🔋{{ u.battery_health }}%<template v-if="u.battery_condition"> {{ u.battery_condition }}</template></template
                                ><template v-if="u.account_status"> · {{ u.account_status }}</template
                                ><template v-if="u.catatan"> · {{ u.catatan }}</template>
                            </div>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{ formatQty(detail.qty) }} {{ detail.unit }} x {{ formatCurrency(detail.harga_satuan) }}</span>
                            <span class="text-slate-700">{{ formatCurrency(bruto(detail)) }}</span>
                        </div>
                        <div v-if="Number(detail.diskon_total) > 0" class="flex justify-between text-xs text-rose-500">
                            <span>{{ formatDiscLine(detail) }}</span>
                            <span>-{{ formatCurrency(detail.diskon_total) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="border-t border-dashed border-slate-200 pt-3 space-y-1 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-500">Subtotal</span><span class="text-slate-700">{{ formatCurrency(sales.subtotal) }}</span>
                    </div>
                    <div v-if="Number(sales.diskon_nota_1_hasil) > 0" class="flex justify-between text-rose-500">
                        <span>{{ sales.diskon_nota_1_label || 'Disc 1' }} ({{ sales.diskon_nota_1_tipe === 'percent' ? formatPercent(sales.diskon_nota_1_nilai) : formatCurrency(sales.diskon_nota_1_nilai) }})</span>
                        <span>-{{ formatCurrency(sales.diskon_nota_1_hasil) }}</span>
                    </div>
                    <div v-if="Number(sales.diskon_nota_2_hasil) > 0" class="flex justify-between text-rose-500">
                        <span>{{ sales.diskon_nota_2_label || 'Disc 2' }} ({{ sales.diskon_nota_2_tipe === 'percent' ? formatPercent(sales.diskon_nota_2_nilai) : formatCurrency(sales.diskon_nota_2_nilai) }})</span>
                        <span>-{{ formatCurrency(sales.diskon_nota_2_hasil) }}</span>
                    </div>
                    <div v-if="Number(sales.diskon_nota_3_hasil) > 0" class="flex justify-between text-rose-500">
                        <span>{{ sales.diskon_nota_3_label || 'Disc Manual' }} ({{ sales.diskon_nota_3_tipe === 'percent' ? formatPercent(sales.diskon_nota_3_nilai) : formatCurrency(sales.diskon_nota_3_nilai) }})</span>
                        <span>-{{ formatCurrency(sales.diskon_nota_3_hasil) }}</span>
                    </div>
                    <div v-if="Number(sales.total_diskon) > 0" class="flex justify-between">
                        <span class="text-slate-500">Total</span><span class="text-slate-700">{{ formatCurrency(sales.total_setelah_diskon) }}</span>
                    </div>
                    <div v-if="Number(sales.biaya_kirim_hasil) > 0" class="flex justify-between">
                        <span class="text-slate-500">Biaya Kirim ({{ sales.biaya_kirim_tipe === 'percent' ? formatPercent(sales.biaya_kirim_nilai) : formatCurrency(sales.biaya_kirim_nilai) }})</span
                        ><span class="text-slate-700">{{ formatCurrency(sales.biaya_kirim_hasil) }}</span>
                    </div>
                    <div v-if="Number(sales.biaya_lain_hasil) > 0" class="flex justify-between">
                        <span class="text-slate-500">Biaya Lain ({{ sales.biaya_lain_tipe === 'percent' ? formatPercent(sales.biaya_lain_nilai) : formatCurrency(sales.biaya_lain_nilai) }})</span
                        ><span class="text-slate-700">{{ formatCurrency(sales.biaya_lain_hasil) }}</span>
                    </div>
                    <div v-if="Number(sales.pajak_nominal) > 0" class="flex justify-between">
                        <span class="text-slate-500">DPP</span><span class="text-slate-700">{{ formatCurrency(sales.dpp) }}</span>
                    </div>
                    <div v-if="Number(sales.pajak_nominal) > 0" class="flex justify-between">
                        <span class="text-slate-500">{{ sales.pajak_nama }} {{ sales.pajak_persen }}%</span><span class="text-slate-700">{{ formatCurrency(sales.pajak_nominal) }}</span>
                    </div>
                    <div v-if="Number(sales.pembulatan)" class="flex justify-between">
                        <span class="text-slate-500">Pembulatan</span><span class="text-slate-700">{{ formatCurrency(sales.pembulatan) }}</span>
                    </div>
                </div>

                <!-- Grand Total -->
                <div class="border-t-2 border-slate-300 mt-3 pt-3">
                    <div class="flex justify-between text-lg font-bold text-slate-800">
                        <span>GRAND TOTAL</span>
                        <span>{{ formatCurrency(sales.grand_total) }}</span>
                    </div>
                </div>

                <!-- Payments -->
                <div class="border-t border-dashed border-slate-200 mt-3 pt-3 space-y-1 text-sm">
                    <div v-for="payment in sales.payments" :key="payment.id">
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{ payment.metode_pembayaran?.nama_pembayaran }}</span>
                            <span class="text-slate-700">{{ formatCurrency(payment.nominal) }}</span>
                        </div>
                        <div v-if="Number(payment.biaya_tambahan) > 0" class="text-xs text-slate-400 text-right">Biaya: {{ formatCurrency(payment.biaya_tambahan) }}</div>
                    </div>
                    <div v-if="Number(sales.kembalian) > 0" class="flex justify-between font-medium">
                        <span class="text-slate-500">Kembali</span><span class="text-slate-700">{{ formatCurrency(sales.kembalian) }}</span>
                    </div>
                </div>

                <!-- Status Banner -->
                <div v-if="statusBanner" class="mt-4 rounded-lg px-4 py-3 text-center" :style="{ backgroundColor: statusBanner.bg, border: '1px solid ' + statusBanner.border }">
                    <div class="font-bold text-base" :style="{ color: statusBanner.textColor }">{{ statusBanner.icon }} {{ statusBanner.label }}</div>
                    <!-- Void details -->
                    <template v-if="receiptStatus === 'voided'">
                        <div class="text-xs mt-1" :style="{ color: statusBanner.textColor }">
                            <div v-if="sales.voided_by">Void oleh: {{ sales.voided_by.name }}</div>
                            <div v-if="sales.void_reason">Alasan: {{ sales.void_reason }}</div>
                            <div v-if="sales.voided_at">Waktu: {{ formatDateTime(sales.voided_at) }}</div>
                        </div>
                    </template>
                    <!-- Retur info -->
                    <template v-if="receiptStatus === 'retur_partial'">
                        <div class="text-xs mt-1" :style="{ color: statusBanner.textColor }">Sebagian item telah diretur</div>
                    </template>
                    <template v-if="receiptStatus === 'retur_full'">
                        <div class="text-xs mt-1" :style="{ color: statusBanner.textColor }">Seluruh item telah diretur</div>
                    </template>
                </div>

                <!-- Return History -->
                <div v-if="sales.returns?.length > 0" class="mt-4 border-t border-dashed border-slate-200 pt-3">
                    <div class="font-semibold text-slate-700 mb-2 flex items-center gap-2"><span class="text-orange-500">⟲</span> Riwayat Retur</div>
                    <div v-for="ret in sales.returns" :key="ret.id" class="mb-3 p-3 bg-orange-50 rounded-lg text-sm">
                        <div class="flex justify-between items-center mb-1">
                            <span class="font-medium text-slate-700">{{ ret.nomor_dokumen }}</span>
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs font-medium">Tunai</span>
                        </div>
                        <div class="text-xs text-slate-500 mb-2">{{ formatDateTime(ret.tanggal) }} oleh {{ ret.created_by?.name || '-' }}</div>
                        <div class="space-y-1">
                            <div v-for="d in ret.details" :key="d.id" class="flex justify-between text-xs">
                                <span class="text-slate-600">{{ d.product?.nama_produk }} x {{ formatQty(d.qty) }}</span>
                                <span class="text-orange-600 font-medium">@ {{ formatCurrency(d.harga_satuan) }}</span>
                            </div>
                        </div>
                        <div v-if="Number(ret.pembulatan)" class="flex justify-between text-xs mt-1">
                            <span class="text-slate-500">Pembulatan</span>
                            <span class="text-orange-600">{{ formatCurrency(ret.pembulatan) }}</span>
                        </div>
                        <div class="flex justify-between font-medium mt-2 pt-2 border-t border-orange-200">
                            <span class="text-slate-700">Total Retur</span>
                            <span class="text-orange-600">{{ formatCurrency(ret.grand_total) }}</span>
                        </div>
                    </div>

                    <!-- Ringkasan Retur -->
                    <div class="mt-3 p-3 bg-slate-100 rounded-lg text-sm">
                        <div class="font-semibold mb-2 text-slate-700">RINGKASAN</div>

                        <div class="flex justify-between mb-1">
                            <span class="text-slate-600">Total Pembayaran Asli</span>
                            <span class="font-medium text-slate-700">{{ formatCurrency(sales.grand_total) }}</span>
                        </div>

                        <div v-if="Number(sales.biaya_kirim_hasil) > 0 || Number(sales.biaya_lain_hasil) > 0" class="mt-2 mb-2">
                            <div class="text-xs text-slate-500 mb-1">Tidak Termasuk Retur:</div>
                            <div v-if="Number(sales.biaya_kirim_hasil) > 0" class="flex justify-between text-xs pl-2">
                                <span class="text-slate-500">Biaya Kirim</span>
                                <span class="text-slate-700">{{ formatCurrency(sales.biaya_kirim_hasil) }}</span>
                            </div>
                            <div v-if="Number(sales.biaya_lain_hasil) > 0" class="flex justify-between text-xs pl-2">
                                <span class="text-slate-500">Biaya Lain</span>
                                <span class="text-slate-700">{{ formatCurrency(sales.biaya_lain_hasil) }}</span>
                            </div>
                        </div>

                        <div class="flex justify-between mb-1">
                            <span class="text-slate-600">Total Semua Retur</span>
                            <span class="font-medium text-orange-600">{{ formatCurrency(sales.returns.reduce((sum, r) => sum + Number(r.grand_total), 0)) }}</span>
                        </div>
                        <div class="flex justify-between text-xs pl-2 mb-2">
                            <span class="text-slate-500">Refund Tunai</span>
                            <span class="text-slate-700">{{ formatCurrency(sales.returns.reduce((sum, r) => sum + Number(r.grand_total), 0)) }}</span>
                        </div>

                        <div class="flex justify-between font-bold pt-2 border-t border-slate-300">
                            <span class="text-slate-700">NILAI BERSIH</span>
                            <span class="text-blue-600">{{ formatCurrency(Number(sales.grand_total) - sales.returns.reduce((sum, r) => sum + Number(r.grand_total), 0)) }}</span>
                        </div>
                        <div class="text-xs text-slate-500">(Pembayaran - Retur)</div>
                    </div>
                </div>

                <!-- Retur Policy -->
                <div v-if="returPolicyText" class="text-center mt-6 mb-1">
                    <p class="text-slate-500 text-xs italic">{{ returPolicyText }}</p>
                </div>

                <!-- Footer -->
                <div class="text-center mt-3 mb-2">
                    <p v-for="(line, idx) in footerLines" :key="idx" class="text-slate-400 text-sm">{{ line }}</p>
                    <p v-if="sales.notes" class="text-slate-400 text-xs mt-1">{{ sales.notes }}</p>
                </div>
            </div>

            <!-- Download PDF -->
            <div class="px-6 pb-4 no-print">
                <button @click="downloadPdf" class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Download PDF
                </button>
            </div>

            <!-- Powered by -->
            <div class="bg-slate-50 text-center py-3 text-xs text-slate-400 no-print">Powered by <a href="https://siapngeweb.com" target="_blank" class="text-slate-500 hover:text-slate-600 underline">siapngeweb.com</a></div>
        </div>
    </div>
</template>

<style>
.receipt-wrapper {
    position: relative;
}

.watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-30deg);
    font-size: 4rem;
    font-weight: 900;
    opacity: 0.12;
    white-space: nowrap;
    z-index: 5;
    pointer-events: none;
    letter-spacing: 0.1em;
    user-select: none;
}

@media print {
    .no-print {
        display: none !important;
    }
    body,
    .min-h-screen {
        background: #fff !important;
    }
    .shadow-xl {
        box-shadow: none !important;
    }
    .rounded-2xl {
        border-radius: 0 !important;
    }

    .watermark {
        opacity: 0.08;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

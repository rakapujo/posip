import { useFormatters } from './useFormatters';
import { useReceiptPdf } from './useReceiptPdf';
import { useSettingsStore } from '@/stores/settings';
import { buildShiftPenjualanLines } from '@/composables/print/shiftPenjualanEscpos';
import { buildFeedAndCutBytes } from '@/composables/print/escposFeedCut';

// ─── ESC/POS Command Bytes ───
const CMD = {
    INIT: [0x1b, 0x40],
    INIT_FEED: [0x1b, 0x40, 0x0a],
    CENTER: [0x1b, 0x61, 0x01],
    LEFT: [0x1b, 0x61, 0x00],
    RIGHT: [0x1b, 0x61, 0x02],
    BOLD_ON: [0x1b, 0x45, 0x01],
    BOLD_OFF: [0x1b, 0x45, 0x00],
    DOUBLE: [0x1b, 0x21, 0x30],
    NORMAL: [0x1b, 0x21, 0x00],
    CUT: [0x1d, 0x56, 0x01],
    DRAWER_2: [0x1b, 0x70, 0x00, 0x19, 0x19],
    DRAWER_5: [0x1b, 0x70, 0x01, 0x19, 0x19],
    feed: (n) => [0x1b, 0x64, Math.min(Math.max(n, 0), 10)]
};

// ─── Byte Buffer ───
class Buf {
    constructor() {
        this._parts = [];
    }
    cmd(bytes) {
        this._parts.push(new Uint8Array(bytes));
        return this;
    }
    text(str) {
        const bytes = new Uint8Array(str.length);
        for (let i = 0; i < str.length; i++) {
            const c = str.charCodeAt(i);
            bytes[i] = c < 128 ? c : 0x3f; // ASCII or '?'
        }
        this._parts.push(bytes);
        return this;
    }
    toBytes() {
        let len = 0;
        for (const p of this._parts) len += p.length;
        const out = new Uint8Array(len);
        let off = 0;
        for (const p of this._parts) {
            out.set(p, off);
            off += p.length;
        }
        return out;
    }
    toBase64() {
        const bytes = this.toBytes();
        let bin = '';
        for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin);
    }
}

// ─── Layout helpers (no state, pure functions) ───
function _line(char, w) {
    return char.repeat(w) + '\n';
}

function _twoCol(left, right, w) {
    const rLen = right.length;
    const space = w - rLen - 1;
    const l = left.length > space ? left.slice(0, space) : left + ' '.repeat(Math.max(0, space - left.length));
    return l + ' ' + right;
}

// Word-wrap a string to width `w`, preserving the leading indent on wrapped lines.
function _wrap(str, w) {
    if (str.length <= w) return [str];
    const indent = (str.match(/^\s*/) || [''])[0];
    const words = str.trim().split(/\s+/);
    const out = [];
    let cur = indent;
    for (const word of words) {
        const candidate = cur.trim() === '' ? indent + word : cur + ' ' + word;
        if (candidate.length > w && cur.trim() !== '') {
            out.push(cur);
            cur = indent + word;
        } else {
            cur = candidate;
        }
    }
    if (cur.trim() !== '') out.push(cur);
    return out.length ? out : [str];
}

function _feedAndCut(buf, feedLines, openDrawer = false) {
    buf.cmd(Array.from(buildFeedAndCutBytes(feedLines, openDrawer)));
}

/**
 * Composable: generate ESC/POS bytes for thermal printer.
 *
 * Uses the SAME data fields as useReceiptPdf.js (API DocSales object directly).
 * No intermediate mapping — eliminates desync between PDF and thermal output.
 */
export function useReceiptEscPos() {
    const { formatCurrency, formatNumber, formatQty, formatPercent, formatDateTime } = useFormatters();
    const settingsStore = useSettingsStore();
    // Shared retur-policy builder — single source of truth for PDF, ESC/POS, and online preview
    const { buildReturPolicyText } = useReceiptPdf();

    // ─── Number format without currency symbol (compact for thermal) ───
    function fmtN(val) {
        if (val === null || val === undefined) return '0';
        return formatNumber(Math.abs(Number(val)));
    }
    function fmtC(val) {
        if (val === null || val === undefined) return '0';
        const n = Number(val);
        const abs = fmtN(n);
        return n < 0 ? `-${abs}` : abs;
    }

    // ─── Serial unit detail line (ASCII only — thermal) ───
    // Builds "KI-xxx . SN xxx . Grade . Bat 90% Good . Active" parts; returns { main, catatan }
    function fmtSerialUnit(u) {
        const parts = [];
        if (u.kode_internal) parts.push(u.kode_internal);
        parts.push(`SN ${u.serial_number || '-'}`);
        if (u.grade) parts.push(u.grade);
        if (u.battery_health !== null && u.battery_health !== undefined && u.battery_health !== '') {
            parts.push(`Bat ${u.battery_health}%${u.battery_condition ? ' ' + u.battery_condition : ''}`);
        } else if (u.battery_condition) {
            parts.push(`Bat ${u.battery_condition}`);
        }
        if (u.account_status) parts.push(u.account_status);
        return { main: parts.join(' . '), catatan: u.catatan || '' };
    }

    // ─── Discount line label (5-level) ───
    function fmtDiscLine(detail) {
        const parts = [];
        for (let i = 1; i <= 5; i++) {
            const tipe = detail[`diskon_${i}_tipe`];
            const nilai = Number(detail[`diskon_${i}_nilai`] || 0);
            if (tipe === 'none' || nilai === 0) continue;
            parts.push(tipe === 'percent' ? formatPercent(nilai) : formatCurrency(nilai));
        }
        return parts.join('+');
    }

    // ─── Disc nota / biaya label with tipe/nilai ───
    function fmtLabel(base, tipe, nilai) {
        if (!tipe || !nilai) return base;
        const v = tipe === 'percent' ? formatPercent(nilai) : fmtC(nilai);
        return `${base} (${v})`;
    }

    // ─── Store header ───
    function _storeHeader(buf, cw, compact) {
        const s = settingsStore.store;
        buf.cmd(CMD.CENTER);
        if (compact) {
            buf.cmd(CMD.BOLD_ON)
                .text((s.name || 'POSIP') + '\n')
                .cmd(CMD.BOLD_OFF);
        } else {
            buf.cmd(CMD.DOUBLE)
                .text((s.name || 'POSIP') + '\n')
                .cmd(CMD.NORMAL);
        }
        // Multi-line address — split on \n so each line renders separately on thermal
        if (s.address) {
            for (const al of String(s.address).split(/\r?\n/)) {
                if (al.trim()) buf.text(al + '\n');
            }
        }
        if (s.phone) buf.text('Telp: ' + s.phone + '\n');
        if (s.email) buf.text('Email: ' + s.email + '\n');
        if (s.npwp) buf.text('NPWP: ' + s.npwp + '\n');
        buf.text(_line('=', cw));
    }

    // ════════════════════════════════════════════════════════════
    //  buildReceipt — Struk Penjualan
    //  data = DocSales from API (same object used by useReceiptPdf)
    // ════════════════════════════════════════════════════════════
    function buildReceipt(data, opts = {}) {
        const { charWidth: cw = 42, feedLines = 4, compact = false, returPolicy = null, footer = null, openDrawer = false } = opts;
        const buf = new Buf();

        // Init + Store header
        buf.cmd(CMD.INIT_FEED);
        _storeHeader(buf, cw, compact);

        // Transaction info
        buf.cmd(CMD.LEFT);
        buf.text(_twoCol('No', ': ' + (data.nomor_dokumen || '-'), cw) + '\n');
        buf.text(_twoCol('Tgl', ': ' + formatDateTime(data.tanggal), cw) + '\n');
        if (data.created_by?.name) buf.text(_twoCol('Kasir', ': ' + data.created_by.name, cw) + '\n');
        const cust = data.customer?.nama;
        if (cust && cust !== 'Walk-in') buf.text(_twoCol('Cust', ': ' + cust, cw) + '\n');
        buf.text(_line('-', cw));

        // Items
        for (const d of data.details || []) {
            buf.text((d.product?.nama_produk || '') + '\n');
            const bruto = Number(d.qty || 0) * Number(d.harga_satuan || 0);
            buf.text(_twoCol(`  ${formatQty(d.qty)} ${d.unit || ''} x ${fmtC(d.harga_satuan)}`, fmtC(bruto), cw) + '\n');
            if (Number(d.diskon_total) > 0) {
                const discLabel = fmtDiscLine(d);
                buf.text(_twoCol(`    ${discLabel}`, '-' + fmtC(d.diskon_total), cw) + '\n');
            }
            // Serial units — one (wrapped) line per unit, indented
            if (d.serial_units?.length) {
                for (const u of d.serial_units) {
                    const { main, catatan } = fmtSerialUnit(u);
                    for (const wl of _wrap('  ' + main, cw)) buf.text(wl + '\n');
                    if (catatan) for (const wl of _wrap('    Cat: ' + catatan, cw)) buf.text(wl + '\n');
                }
            }
        }
        buf.text(_line('-', cw));

        // Summary
        buf.text(_twoCol('Subtotal', fmtC(data.subtotal), cw) + '\n');
        for (let i = 1; i <= 3; i++) {
            const hasil = Number(data[`diskon_nota_${i}_hasil`] || 0);
            if (hasil > 0) {
                // Label fallback: live cart label → persisted label → generic "Disc N"
                const liveLabel = data[`_disc_label_${i}`] || data[`diskon_nota_${i}_label`];
                const label = liveLabel ? fmtLabel(liveLabel, data[`diskon_nota_${i}_tipe`], data[`diskon_nota_${i}_nilai`]) : fmtLabel(`Disc ${i}`, data[`diskon_nota_${i}_tipe`], data[`diskon_nota_${i}_nilai`]);
                buf.text(_twoCol('  ' + label, '-' + fmtC(hasil), cw) + '\n');
            }
        }
        if (Number(data.total_diskon) > 0) buf.text(_twoCol('Total', fmtC(data.total_setelah_diskon), cw) + '\n');
        if (Number(data.biaya_kirim_hasil) > 0) {
            const label = fmtLabel('Biaya Kirim', data.biaya_kirim_tipe, data.biaya_kirim_nilai);
            buf.text(_twoCol(label, fmtC(data.biaya_kirim_hasil), cw) + '\n');
        }
        if (Number(data.biaya_lain_hasil) > 0) {
            const label = fmtLabel('Biaya Lain', data.biaya_lain_tipe, data.biaya_lain_nilai);
            buf.text(_twoCol(label, fmtC(data.biaya_lain_hasil), cw) + '\n');
        }
        if (Number(data.pajak_nominal) > 0) {
            buf.text(_twoCol('DPP', fmtC(data.dpp), cw) + '\n');
            buf.text(_twoCol(`${data.pajak_nama || 'PPN'} ${data.pajak_persen}%`, fmtC(data.pajak_nominal), cw) + '\n');
        }
        if (Number(data.pembulatan)) buf.text(_twoCol('Pembulatan', fmtC(data.pembulatan), cw) + '\n');
        buf.text(_line('-', cw));

        // Grand Total
        buf.cmd(CMD.BOLD_ON);
        buf.text(_twoCol('GRAND TOTAL', fmtC(data.grand_total), cw) + '\n');
        buf.cmd(CMD.BOLD_OFF);
        buf.text(_line('-', cw));

        // Payments
        for (const p of data.payments || []) {
            buf.text(_twoCol(p.metode_pembayaran?.nama_pembayaran || '', fmtC(p.nominal), cw) + '\n');
            if (Number(p.biaya_tambahan) > 0) buf.text(_twoCol('  Biaya', fmtC(p.biaya_tambahan), cw) + '\n');
        }
        if (Number(data.total_bayar)) {
            buf.cmd(CMD.BOLD_ON);
            buf.text(_twoCol('Total Bayar', fmtC(data.total_bayar), cw) + '\n');
            buf.cmd(CMD.BOLD_OFF);
        }
        if (Number(data.kembalian) > 0) {
            buf.cmd(CMD.BOLD_ON);
            buf.text(_twoCol('Kembali', fmtC(data.kembalian), cw) + '\n');
            buf.cmd(CMD.BOLD_OFF);
        }
        buf.text(_line('=', cw));

        // Return History
        const returns = data.returns || [];
        if (returns.length > 0) {
            buf.cmd(CMD.BOLD_ON).text('RIWAYAT RETUR\n').cmd(CMD.BOLD_OFF);
            for (const ret of returns) {
                buf.text(_twoCol(ret.nomor_dokumen || '', 'Tunai', cw) + '\n');
                buf.text('  ' + formatDateTime(ret.tanggal) + '\n');
                for (const d of ret.details || []) {
                    buf.text(_twoCol(`  ${d.product?.nama_produk || ''} x${formatQty(d.qty)}`, `@ ${fmtC(d.harga_satuan)}`, cw) + '\n');
                }
                if (Number(ret.pembulatan)) buf.text(_twoCol('  Pembulatan', fmtC(ret.pembulatan), cw) + '\n');
                buf.cmd(CMD.BOLD_ON)
                    .text(_twoCol('  Total Retur', fmtC(ret.grand_total), cw) + '\n')
                    .cmd(CMD.BOLD_OFF);
            }
            buf.text(_line('-', cw));

            // Ringkasan Retur
            buf.cmd(CMD.BOLD_ON).text('RINGKASAN\n').cmd(CMD.BOLD_OFF);
            buf.text(_twoCol('Pembayaran Asli', fmtC(data.grand_total), cw) + '\n');
            if (Number(data.biaya_kirim_hasil) > 0 || Number(data.biaya_lain_hasil) > 0) {
                buf.text('Tidak Termasuk Retur:\n');
                if (Number(data.biaya_kirim_hasil) > 0) buf.text(_twoCol('  Biaya Kirim', fmtC(data.biaya_kirim_hasil), cw) + '\n');
                if (Number(data.biaya_lain_hasil) > 0) buf.text(_twoCol('  Biaya Lain', fmtC(data.biaya_lain_hasil), cw) + '\n');
            }
            const totalRetur = returns.reduce((s, r) => s + Number(r.grand_total || 0), 0);
            buf.text(_twoCol('Total Semua Retur', fmtC(totalRetur), cw) + '\n');
            buf.text(_twoCol('  Refund Tunai', fmtC(totalRetur), cw) + '\n');
            buf.cmd(CMD.BOLD_ON);
            buf.text(_twoCol('NILAI BERSIH', fmtC(Number(data.grand_total) - totalRetur), cw) + '\n');
            buf.cmd(CMD.BOLD_OFF);
            buf.text('(Pembayaran - Retur)\n');
            buf.text(_line('=', cw));
        }

        // Status watermark
        if (data.status === 'voided') {
            buf.cmd(CMD.CENTER).cmd(CMD.BOLD_ON);
            if (!compact) buf.cmd(CMD.DOUBLE);
            buf.text('*** VOID ***\n');
            buf.cmd(CMD.NORMAL).cmd(CMD.BOLD_OFF);
        } else if (returns.length > 0) {
            buf.cmd(CMD.CENTER).cmd(CMD.BOLD_ON);
            buf.text('*** RETUR ***\n');
            buf.cmd(CMD.BOLD_OFF);
        }

        // Retur policy — pakai builder shared dari useReceiptPdf, sehingga thermal,
        // PDF, dan preview online semua tampil kalimat yang sama persis
        if (returPolicy) {
            const policyText = buildReturPolicyText(returPolicy, data.tanggal);
            if (policyText) buf.cmd(CMD.CENTER).text(policyText + '\n');
        }

        // Footer
        const footerText = footer || 'Terima Kasih!';
        buf.cmd(CMD.CENTER);
        for (const fl of footerText.split('\n')) buf.text(fl + '\n');

        // Notes
        if (data.notes) buf.cmd(CMD.CENTER).text(data.notes + '\n');

        _feedAndCut(buf, feedLines, openDrawer);
        return buf.toBase64();
    }

    // ════════════════════════════════════════════════════════════
    //  buildReturReceipt — Struk Retur
    //  returData = SalesReturn from API
    //  salesData = original DocSales (for sales number reference)
    // ════════════════════════════════════════════════════════════
    function buildReturReceipt(returData, salesData, opts = {}) {
        const { charWidth: cw = 42, feedLines = 4, compact = false } = opts;
        const buf = new Buf();

        buf.cmd(CMD.INIT_FEED);
        _storeHeader(buf, cw, compact);

        // Title
        buf.cmd(CMD.CENTER).cmd(CMD.BOLD_ON).text('STRUK RETUR\n').cmd(CMD.BOLD_OFF).cmd(CMD.LEFT);
        buf.text(_line('=', cw));

        // Transaction info
        buf.text(_twoCol('No Retur', ': ' + (returData.nomor_dokumen || '-'), cw) + '\n');
        buf.text(_twoCol('No Nota', ': ' + (salesData?.nomor_dokumen || '-'), cw) + '\n');
        buf.text(_twoCol('Tgl', ': ' + formatDateTime(returData.tanggal || new Date()), cw) + '\n');
        if (returData.created_by?.name) buf.text(_twoCol('Kasir', ': ' + returData.created_by.name, cw) + '\n');
        buf.text(_line('-', cw));

        // Items
        for (const d of returData.details || []) {
            const name = d.product?.nama_produk || '';
            const qty = d.qty || 0;
            const price = d.harga_satuan || d.harga_per_base || 0;
            const sub = Number(qty) * Number(price);
            buf.text(name + '\n');
            buf.text(_twoCol(`  ${formatQty(qty)} x ${fmtC(price)}`, fmtC(sub), cw) + '\n');
        }
        buf.text(_line('-', cw));

        // Subtotal + pembulatan + total
        const subtotal = Number(returData.subtotal || 0);
        const pembulatan = Number(returData.pembulatan || 0);
        if (subtotal) buf.text(_twoCol('Subtotal', fmtC(subtotal), cw) + '\n');
        if (pembulatan) buf.text(_twoCol('Pembulatan', fmtC(pembulatan), cw) + '\n');
        buf.cmd(CMD.BOLD_ON);
        buf.text(_twoCol('TOTAL RETUR', fmtC(returData.grand_total), cw) + '\n');
        buf.cmd(CMD.BOLD_OFF);
        buf.text(_line('-', cw));

        // Refund method
        buf.text(_twoCol('Metode Refund', 'Tunai', cw) + '\n');
        buf.text(_line('=', cw));

        // Footer
        buf.cmd(CMD.CENTER).text('Terima Kasih\n');
        _feedAndCut(buf, feedLines);
        return buf.toBase64();
    }

    // ════════════════════════════════════════════════════════════
    //  buildCashReceipt — Struk Kas Masuk/Keluar/Setor Awal
    //  params = { tipe, nominal, keterangan, terminal, kasir, date }
    // ════════════════════════════════════════════════════════════
    function buildCashReceipt(params, opts = {}) {
        const { charWidth: cw = 42, feedLines = 4, compact = false } = opts;
        const buf = new Buf();

        buf.cmd(CMD.INIT_FEED);
        _storeHeader(buf, cw, compact);

        // Title
        const titles = { kas_masuk: 'KAS MASUK', kas_keluar: 'KAS KELUAR', setor_awal: 'SETOR AWAL' };
        const title = titles[params.tipe] || 'TRANSAKSI KAS';
        buf.cmd(CMD.CENTER)
            .cmd(CMD.BOLD_ON)
            .text(title + '\n')
            .cmd(CMD.BOLD_OFF)
            .cmd(CMD.LEFT);
        buf.text(_line('=', cw));

        // Info
        buf.text(_twoCol('Terminal', ': ' + (params.terminal || '-'), cw) + '\n');
        buf.text(_twoCol('Kasir', ': ' + (params.kasir || '-'), cw) + '\n');
        buf.text(_twoCol('Tanggal', ': ' + (params.date || '-'), cw) + '\n');
        buf.text(_line('-', cw));

        // Nominal
        buf.cmd(CMD.BOLD_ON);
        buf.text(_twoCol('Nominal', fmtC(params.nominal), cw) + '\n');
        buf.cmd(CMD.BOLD_OFF);

        // Keterangan
        if (params.keterangan) buf.text('Ket: ' + params.keterangan + '\n');

        buf.text(_line('=', cw));
        _feedAndCut(buf, feedLines);
        return buf.toBase64();
    }

    // ════════════════════════════════════════════════════════════
    //  buildShiftReport — Laporan Shift
    //  reportData = raw shift report API response
    // ════════════════════════════════════════════════════════════
    function buildShiftReport(reportData, opts = {}) {
        const { charWidth: cw = 42, feedLines = 4, compact = false } = opts;
        const buf = new Buf();

        const shift = reportData.shift || {};
        const p = reportData.penjualan || {};
        const payments = reportData.payment_breakdown || [];
        const vd = reportData.void || {};
        const retur = reportData.retur || {};
        const kas = reportData.kas || {};
        const ring = reportData.ringkasan || {};

        buf.cmd(CMD.INIT_FEED);
        _storeHeader(buf, cw, compact);

        // Title + ULID
        buf.cmd(CMD.CENTER).cmd(CMD.BOLD_ON).text('LAPORAN SHIFT\n').cmd(CMD.BOLD_OFF);
        if (shift.ulid) buf.text(shift.ulid + '\n');
        buf.cmd(CMD.LEFT);
        buf.text(_line('=', cw));

        // Shift info
        buf.text(_twoCol('Terminal', ': ' + (shift.terminal?.kode_terminal || '-'), cw) + '\n');
        buf.text(_twoCol('Kasir', ': ' + (shift.user?.name || '-'), cw) + '\n');
        buf.text(_twoCol('Mulai', ': ' + (shift.started_at ? formatDateTime(shift.started_at) : '-'), cw) + '\n');
        buf.text(_twoCol('Selesai', ': ' + (shift.ended_at ? formatDateTime(shift.ended_at) : '-'), cw) + '\n');
        // Status
        let shiftStatus = 'Masih Aktif';
        if (shift.ended_at) shiftStatus = shift.ended_by_force ? `Ditutup Paksa oleh ${shift.forced_by_user?.name || 'Admin'}` : 'Ditutup Normal';
        buf.text(_twoCol('Status', ': ' + shiftStatus, cw) + '\n');
        buf.text(_line('-', cw));

        // Penjualan — breakdown parity with useShiftReport.js PDF
        const penjualanLines = buildShiftPenjualanLines(p, fmtC, _twoCol, cw);
        if (penjualanLines.length) {
            buf.cmd(CMD.BOLD_ON).text(penjualanLines[0] + '\n').cmd(CMD.BOLD_OFF);
            for (let i = 1; i < penjualanLines.length - 1; i++) {
                buf.text(penjualanLines[i] + '\n');
            }
            if (penjualanLines.length > 1) {
                buf.cmd(CMD.BOLD_ON).text(penjualanLines[penjualanLines.length - 1] + '\n').cmd(CMD.BOLD_OFF);
            }
        }
        const _totalBiayaPembayaran = payments.reduce((s, pb) => s + Number(pb.biaya_tambahan || 0), 0);
        if (_totalBiayaPembayaran > 0) buf.text(_twoCol('Biaya Pembayaran', fmtC(_totalBiayaPembayaran), cw) + '\n');
        buf.text(_line('-', cw));

        // Unit Serial Terjual (hanya kalau ada isinya)
        const serialUnits = reportData.serial_units_sold || [];
        if (serialUnits.length) {
            buf.cmd(CMD.BOLD_ON);
            buf.text(_twoCol('UNIT SERIAL TERJUAL', `${serialUnits.length} unit`, cw) + '\n');
            buf.cmd(CMD.BOLD_OFF);
            for (const u of serialUnits) {
                // Baris 1: produk + harga
                buf.text(_twoCol(u.product || '-', fmtC(u.harga), cw) + '\n');
                // Baris 2: kode internal (identitas unik) atau SN + nomor nota
                buf.text(`  ${u.kode_internal || 'SN ' + (u.serial_number || '-')} | ${u.nomor_dokumen || '-'}\n`);
                // Baris 3: SN (bila ada kode_internal) / grade / baterai / status akun (skip yang kosong)
                const meta = [];
                if (u.kode_internal && u.serial_number) meta.push(`SN ${u.serial_number}`);
                if (u.grade) meta.push(`Grade ${u.grade}`);
                if (u.battery_health !== null && u.battery_health !== undefined) meta.push(`Bat ${u.battery_health}%`);
                if (u.account_status) meta.push(`Akun ${u.account_status}`);
                if (meta.length) buf.text(`  ${meta.join(' | ')}\n`);
            }
            buf.text(_line('-', cw));
        }

        // Per Metode Bayar
        buf.cmd(CMD.BOLD_ON).text('PER METODE BAYAR\n').cmd(CMD.BOLD_OFF);
        for (const pb of payments) {
            buf.text(_twoCol(`${pb.nama} (${pb.count}x)`, fmtC(pb.total), cw) + '\n');
            if (pb.is_tunai && Number(reportData.total_kembalian) > 0) {
                buf.text(_twoCol('  Kembalian', '-' + fmtC(reportData.total_kembalian), cw) + '\n');
                buf.text(_twoCol('  Nett Tunai', fmtC(pb.total - reportData.total_kembalian), cw) + '\n');
            }
            if (Number(pb.biaya_tambahan)) buf.text(_twoCol('  Biaya', fmtC(pb.biaya_tambahan), cw) + '\n');
        }
        buf.text(_line('-', cw));

        // Void
        buf.cmd(CMD.BOLD_ON);
        buf.text(_twoCol('VOID', `${vd.jumlah || 0} trx`, cw) + '\n');
        buf.cmd(CMD.BOLD_OFF);
        buf.text(_twoCol('Nominal Void', fmtC(vd.nominal), cw) + '\n');
        buf.text(_line('-', cw));

        // Retur
        buf.cmd(CMD.BOLD_ON);
        buf.text(_twoCol('RETUR', `${retur.jumlah || 0} trx`, cw) + '\n');
        buf.cmd(CMD.BOLD_OFF);
        buf.text(_twoCol('Total Refund', fmtC(retur.total_refund), cw) + '\n');
        if (Number(retur.total_refund)) {
            const si = retur.sesi_ini || {};
            const ss = retur.sesi_sebelumnya || {};
            buf.text(_twoCol(`  Sesi Ini (${si.jumlah || 0})`, fmtC(si.nominal), cw) + '\n');
            buf.text(_twoCol(`  Sesi Sblm (${ss.jumlah || 0})`, fmtC(ss.nominal), cw) + '\n');
        }
        buf.text(_line('-', cw));

        // Kas
        buf.cmd(CMD.BOLD_ON).text('KAS (Uang Fisik di Laci)\n').cmd(CMD.BOLD_OFF);
        buf.text(_twoCol('Setor Awal', fmtC(kas.setor_awal), cw) + '\n');
        buf.text(_twoCol('Penjualan Tunai (net)', '+' + fmtC(kas.penjualan_tunai), cw) + '\n');
        const km = Number(kas.kas_masuk || 0);
        const kmDetail = kas.kas_masuk_detail || [];
        buf.text(_twoCol(`Kas Masuk${kmDetail.length ? ` (${kmDetail.length}x)` : ''}`, km ? '+' + fmtC(km) : fmtC(0), cw) + '\n');
        for (const item of kmDetail) {
            buf.text(_twoCol(`  ${item.keterangan || '-'}`, '+' + fmtC(item.nominal), cw) + '\n');
        }
        const kk = Number(kas.kas_keluar || 0);
        const kkDetail = kas.kas_keluar_detail || [];
        buf.text(_twoCol(`Kas Keluar${kkDetail.length ? ` (${kkDetail.length}x)` : ''}`, kk ? '-' + fmtC(kk) : fmtC(0), cw) + '\n');
        for (const item of kkDetail) {
            buf.text(_twoCol(`  ${item.keterangan || '-'}`, '-' + fmtC(item.nominal), cw) + '\n');
        }
        const refund = Number(kas.refund_tunai || 0);
        buf.text(_twoCol('Refund Retur (Cash)', refund ? '-' + fmtC(refund) : fmtC(0), cw) + '\n');
        buf.text(_line('-', cw));
        buf.cmd(CMD.BOLD_ON);
        buf.text(_twoCol('Saldo Kas', fmtC(kas.saldo), cw) + '\n');
        buf.cmd(CMD.BOLD_OFF);
        buf.text(_line('-', cw));

        // Rekonsiliasi — tampil kalau shift sudah ended. Kalau saldo_fisik null berarti
        // kasir skip reconcile (pencet "Skip & Tutup") — tampilkan "Belum di-input".
        // NOTE: `shift` sudah di-declare di atas fungsi ini (line ~379).
        if (shift.ended_at) {
            buf.cmd(CMD.BOLD_ON).text('REKONSILIASI KAS\n').cmd(CMD.BOLD_OFF);
            buf.text(_twoCol('Saldo Sistem', fmtC(shift.saldo_system), cw) + '\n');
            if (shift.saldo_fisik !== null && shift.saldo_fisik !== undefined) {
                buf.text(_twoCol('Uang Fisik di Laci', fmtC(shift.saldo_fisik), cw) + '\n');
                const selisih = Number(shift.selisih || 0);
                const selisihLabel = selisih === 0 ? 'Cocok' : selisih > 0 ? 'Lebih' : 'Kurang';
                const selisihStr = (selisih > 0 ? '+' : '') + fmtC(selisih) + ' (' + selisihLabel + ')';
                buf.cmd(CMD.BOLD_ON);
                buf.text(_twoCol('Selisih', selisihStr, cw) + '\n');
                buf.cmd(CMD.BOLD_OFF);
            } else {
                buf.text(_twoCol('Uang Fisik di Laci', 'Belum di-input', cw) + '\n');
            }
            if (shift.closing_notes) {
                buf.text('Catatan: ' + shift.closing_notes + '\n');
            }
            buf.text(_line('-', cw));
        }

        // Ringkasan
        buf.cmd(CMD.BOLD_ON).text('RINGKASAN\n').cmd(CMD.BOLD_OFF);
        buf.text(_twoCol('Total Tunai', fmtC(ring.total_tunai), cw) + '\n');
        buf.text(_twoCol('Total Non-Tunai', fmtC(ring.total_non_tunai), cw) + '\n');
        buf.text(_line('=', cw));
        if (!compact) {
            buf.cmd(CMD.DOUBLE);
            buf.text(_twoCol('TOTAL SEMUA', fmtC(ring.total_semua), (cw / 2) | 0) + '\n');
            buf.cmd(CMD.NORMAL).cmd(CMD.BOLD_OFF);
        } else {
            buf.cmd(CMD.BOLD_ON);
            buf.text(_twoCol('TOTAL SEMUA', fmtC(ring.total_semua), cw) + '\n');
            buf.cmd(CMD.BOLD_OFF);
        }
        buf.text(_line('=', cw));

        _feedAndCut(buf, feedLines);
        return buf.toBase64();
    }

    // ════════════════════════════════════════════════════════════
    //  buildTestPage — Test Print
    // ════════════════════════════════════════════════════════════
    function buildTestPage(opts = {}) {
        const { charWidth: cw = 42 } = opts;
        const buf = new Buf();

        buf.cmd(CMD.INIT_FEED).cmd(CMD.CENTER).cmd(CMD.DOUBLE);
        buf.text('TEST PRINT\n');
        buf.cmd(CMD.NORMAL);
        buf.text('POSIP Thermal Print\n');
        buf.text(_line('=', cw));
        buf.cmd(CMD.LEFT);
        buf.text('Printer is working correctly!\n');
        buf.text(`Paper width: ${cw} chars\n`);
        buf.text(_line('-', cw));
        buf.text(_twoCol('LEFT ALIGN', 'RIGHT ALIGN', cw) + '\n');
        buf.text(_line('-', cw));
        buf.cmd(CMD.CENTER).text('END OF TEST\n');
        _feedAndCut(buf, 4);
        return buf.toBase64();
    }

    // ════════════════════════════════════════════════════════════
    //  drawerBytes — Open cash drawer command
    // ════════════════════════════════════════════════════════════
    function drawerBytes() {
        const buf = new Buf();
        buf.cmd(CMD.INIT).cmd(CMD.DRAWER_2);
        return buf.toBase64();
    }

    return {
        buildReceipt,
        buildReturReceipt,
        buildCashReceipt,
        buildShiftReport,
        buildTestPage,
        drawerBytes
    };
}

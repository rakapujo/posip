/**
 * Pure penjualan section lines for shift thermal report (parity with useShiftReport PDF).
 * @param {Object} p — reportData.penjualan
 * @param {(n: number) => string} fmtC
 * @param {(l: string, r: string, w: number) => string} twoCol
 * @param {number} cw
 * @returns {string[]}
 */
export function buildShiftPenjualanLines(p, fmtC, twoCol, cw) {
    const lines = [];
    lines.push(twoCol('PENJUALAN', `${p.jumlah_transaksi || 0} trx`, cw));
    lines.push(twoCol('Penjualan Kotor', fmtC(p.penjualan_kotor), cw));

    if (Number(p.diskon_item) > 0) {
        lines.push(twoCol('Diskon Item', '-' + fmtC(p.diskon_item), cw));
        for (let i = 1; i <= 5; i++) {
            const val = Number(p[`diskon_line_${i}`] || 0);
            if (val > 0) {
                const suffix = i === 5 ? ' (Manual)' : '';
                lines.push(twoCol(`  Line ${i}${suffix}`, '-' + fmtC(val), cw));
            }
        }
    } else {
        lines.push(twoCol('Diskon Item', fmtC(0), cw));
    }

    if (Number(p.diskon_nota) > 0) {
        lines.push(twoCol('Diskon Nota', '-' + fmtC(p.diskon_nota), cw));
        if (Number(p.diskon_nota_l1) > 0) lines.push(twoCol('  Tipe Customer (L1)', '-' + fmtC(p.diskon_nota_l1), cw));
        if (Number(p.diskon_nota_l2) > 0) lines.push(twoCol('  Kategori Customer (L2)', '-' + fmtC(p.diskon_nota_l2), cw));
        if (Number(p.diskon_nota_l3) > 0) lines.push(twoCol('  Manual Kasir (L3)', '-' + fmtC(p.diskon_nota_l3), cw));
    } else {
        lines.push(twoCol('Diskon Nota', fmtC(0), cw));
    }

    lines.push(twoCol('Penjualan Bersih', fmtC(p.penjualan_bersih), cw));
    lines.push(twoCol('Biaya Kirim', fmtC(p.biaya_kirim), cw));
    lines.push(twoCol('Biaya Lain', fmtC(p.biaya_lain), cw));
    if (p.pajak_nama) {
        lines.push(twoCol(`Pajak (${p.pajak_nama} ${p.pajak_persen}%)`, fmtC(p.pajak_nominal), cw));
    } else {
        lines.push(twoCol('Pajak', fmtC(p.pajak_nominal), cw));
    }
    lines.push(twoCol('Pembulatan', fmtC(p.pembulatan), cw));
    lines.push(twoCol('OMZET', fmtC(p.omzet), cw));
    return lines;
}

/** Labels that must appear in shift penjualan thermal section */
export const SHIFT_PENJUALAN_REQUIRED_LABELS = [
    'PENJUALAN',
    'Penjualan Kotor',
    'Diskon Item',
    'Diskon Nota',
    'Penjualan Bersih',
    'Biaya Kirim',
    'Biaya Lain',
    'Pajak',
    'Pembulatan',
    'OMZET'
];

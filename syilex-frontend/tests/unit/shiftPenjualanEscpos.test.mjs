import { TestRunner } from './testRunner.mjs';
import {
    SHIFT_PENJUALAN_REQUIRED_LABELS,
    buildShiftPenjualanLines
} from '../../src/composables/print/shiftPenjualanEscpos.js';

const runner = new TestRunner('shiftPenjualanEscpos');

function twoCol(l, r, w) {
    const space = w - r.length - 1;
    const left = l.length > space ? l.slice(0, space) : l + ' '.repeat(Math.max(0, space - l.length));
    return left + ' ' + r;
}

const fmtC = (n) => String(Math.round(Number(n) || 0));

console.log('\n🧪 shiftPenjualanEscpos Tests\n' + '='.repeat(50) + '\n');

runner.test('includes all required PDF parity labels', () => {
    const p = {
        jumlah_transaksi: 3,
        penjualan_kotor: 100000,
        diskon_item: 0,
        diskon_nota: 0,
        penjualan_bersih: 100000,
        biaya_kirim: 0,
        biaya_lain: 0,
        pajak_nominal: 11000,
        pembulatan: 0,
        omzet: 111000
    };
    const text = buildShiftPenjualanLines(p, fmtC, twoCol, 42).join('\n');
    for (const label of SHIFT_PENJUALAN_REQUIRED_LABELS) {
        runner.assertContains(text, label, `missing ${label}`);
    }
});

runner.test('diskon item breakdown shows line 5 manual suffix', () => {
    const p = {
        jumlah_transaksi: 1,
        penjualan_kotor: 50000,
        diskon_item: 5000,
        diskon_line_5: 5000,
        diskon_nota: 0,
        penjualan_bersih: 45000,
        biaya_kirim: 0,
        biaya_lain: 0,
        pajak_nominal: 0,
        pembulatan: 0,
        omzet: 45000
    };
    const text = buildShiftPenjualanLines(p, fmtC, twoCol, 42).join('\n');
    runner.assertContains(text, 'Line 5 (Manual)');
    runner.assertContains(text, 'Diskon Item');
});

runner.test('diskon nota L1-L3 labels when present', () => {
    const p = {
        jumlah_transaksi: 2,
        penjualan_kotor: 80000,
        diskon_item: 0,
        diskon_nota: 3000,
        diskon_nota_l1: 1000,
        diskon_nota_l2: 1000,
        diskon_nota_l3: 1000,
        penjualan_bersih: 77000,
        biaya_kirim: 0,
        biaya_lain: 0,
        pajak_nama: 'PPN',
        pajak_persen: 11,
        pajak_nominal: 8470,
        pembulatan: 0,
        omzet: 85470
    };
    const text = buildShiftPenjualanLines(p, fmtC, twoCol, 42).join('\n');
    runner.assertContains(text, 'Tipe Customer (L1)');
    runner.assertContains(text, 'Pajak (PPN 11%)');
});

runner.test('zero diskon still shows Diskon Item/Nota rows', () => {
    const p = {
        jumlah_transaksi: 0,
        penjualan_kotor: 0,
        diskon_item: 0,
        diskon_nota: 0,
        penjualan_bersih: 0,
        biaya_kirim: 0,
        biaya_lain: 0,
        pajak_nominal: 0,
        pembulatan: 0,
        omzet: 0
    };
    const lines = buildShiftPenjualanLines(p, fmtC, twoCol, 42);
    runner.assertTrue(lines.some((l) => l.includes('Diskon Item')));
    runner.assertTrue(lines.some((l) => l.includes('Diskon Nota')));
});

runner.test('diskon item lines 1-4 hidden when zero', () => {
    const p = {
        jumlah_transaksi: 1,
        penjualan_kotor: 10000,
        diskon_item: 500,
        diskon_line_2: 500,
        diskon_nota: 0,
        penjualan_bersih: 9500,
        biaya_kirim: 0,
        biaya_lain: 0,
        pajak_nominal: 0,
        pembulatan: 0,
        omzet: 9500
    };
    const text = buildShiftPenjualanLines(p, fmtC, twoCol, 42).join('\n');
    runner.assertFalse(text.includes('Line 1'));
    runner.assertContains(text, 'Line 2');
});

const ok = runner.summary();
process.exit(ok ? 0 : 1);

/**
 * Guard — mobile dialog fixes (Produk harga cards, Serial HTML preview, PriceChange/Promo detail cards).
 * Source-level assertions (repo pattern: masterUiGuard / printIsolation).
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TestRunner } from './testRunner.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const srcRoot = join(__dirname, '../../src');

function read(rel) {
    return readFileSync(join(srcRoot, rel), 'utf8');
}

function walkVueJs(dir, acc = []) {
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        if (statSync(p).isDirectory()) walkVueJs(p, acc);
        else if (/\.(vue|js)$/.test(name)) acc.push(p);
    }
    return acc;
}

const runner = new TestRunner('mobileDialogGuard');

console.log('\n🧪 mobileDialogGuard Tests\n' + '='.repeat(50) + '\n');

// ─── A: Produk Satuan & Harga ───
runner.test('ProdukPage Satuan&Harga: no forced min-width 36rem table', () => {
    const vue = read('views/master/ProdukPage.vue');
    runner.assertFalse(/min-width:\s*36rem/.test(vue), 'must not force horizontal scroll table');
});

runner.test('ProdukPage Satuan&Harga: desktop table + mobile unit cards', () => {
    const vue = read('views/master/ProdukPage.vue');
    const fieldset = vue.match(/Fieldset v-if="!produk\.is_serial" legend="Satuan & Harga"[\s\S]*?<\/Fieldset>/);
    runner.assertTrue(!!fieldset, 'Satuan & Harga Fieldset for non-serial must exist');
    const block = fieldset[0];
    runner.assertContains(block, 'hidden lg:block', 'desktop table wrapper');
    runner.assertContains(block, 'lg:hidden flex flex-col gap-3', 'mobile card stack');
    runner.assertTrue(/v-for="n in 4"/.test(block), 'four units on desktop or mobile');
    runner.assertContains(block, "produk[`unit_${n}`]");
    runner.assertContains(block, "produk[`konversi_${n}`]");
    runner.assertContains(block, "produk[`harga_${n}`]");
});

runner.test('ProdukPage Satuan&Harga only for non-serial products', () => {
    const vue = read('views/master/ProdukPage.vue');
    runner.assertContains(vue, 'Fieldset v-if="!produk.is_serial" legend="Satuan & Harga"');
});

// ─── B: Serial Label HTML preview ───
runner.test('SerialLabelPrintDialog: no iframe / blob previewUrl', () => {
    const vue = read('components/common/SerialLabelPrintDialog.vue');
    runner.assertFalse(/<iframe\b/.test(vue), 'must not use iframe PDF preview');
    runner.assertFalse(/\bpreviewUrl\b/.test(vue), 'must not keep blob previewUrl');
    runner.assertFalse(/doc\.output\(\s*['"]blob['"]\s*\)/.test(vue), 'preview must not build PDF blob');
});

runner.test('SerialLabelPrintDialog: HTML preview via generateBarcodeDataURL', () => {
    const vue = read('components/common/SerialLabelPrintDialog.vue');
    runner.assertContains(vue, 'generateBarcodeDataURL');
    runner.assertContains(vue, 'previewLabels');
    runner.assertContains(vue, 'barcodeImg');
    runner.assertContains(vue, '<img');
    runner.assertContains(vue, 'flex-col-reverse', 'preview-first on mobile');
    runner.assertTrue(/watch\(\s*\[settings,\s*keterangan,\s*labelItems\]/.test(vue), 'must watch labelItems for async units');
});

runner.test('SerialLabelPrintDialog: Print/Download still use PDF helpers', () => {
    const vue = read('components/common/SerialLabelPrintDialog.vue');
    runner.assertContains(vue, 'printSerialLabels');
    runner.assertContains(vue, 'downloadSerialLabels');
});

runner.test('src has zero <iframe> (no PDF preview iframes)', () => {
    const offenders = [];
    for (const p of walkVueJs(srcRoot)) {
        if (/<iframe\b/.test(readFileSync(p, 'utf8'))) {
            offenders.push(p.slice(srcRoot.length + 1).replace(/\\/g, '/'));
        }
    }
    runner.assertDeepEqual(offenders, []);
});

// ─── C: Price Change + Promo detail ───
runner.test('PriceChangePage detail: desktop table + mobile product cards', () => {
    const vue = read('views/master/PriceChangePage.vue');
    runner.assertContains(vue, 'hidden lg:block overflow-x-auto');
    runner.assertContains(vue, 'lg:hidden flex flex-col gap-3');
    runner.assertContains(vue, 'harga_${u}_lama');
    runner.assertContains(vue, 'harga_${u}_baru');
    runner.assertContains(vue, 'getSelisih(item, u)');
    runner.assertContains(vue, 'rowspan="3"', 'desktop rowspan retained');
});

runner.test('PromosPage detail: desktop table + mobile discount cards', () => {
    const vue = read('views/master/PromosPage.vue');
    runner.assertContains(vue, 'hidden lg:block overflow-x-auto');
    runner.assertContains(vue, 'lg:hidden flex flex-col gap-3');
    runner.assertContains(vue, 'formatDiskonSlot(d.diskon_1_tipe');
    runner.assertContains(vue, 'targetTypeLabel(d.target_type)');
});

// ─── CSS shell still protects dialog grids ───
runner.test('_responsive.scss still forces dialog grids 1-col ≤991 (excl pos-payment)', () => {
    const scss = read('assets/layout/_responsive.scss');
    runner.assertContains(scss, '.p-dialog:not(.pos-payment-dialog) .p-dialog-content .grid');
    runner.assertTrue(/grid-template-columns:\s*repeat\(\s*1\s*,/.test(scss), '1-col grid force');
    runner.assertContains(scss, 'grid-column: auto !important');
});

const ok = runner.summary();
process.exit(ok ? 0 : 1);

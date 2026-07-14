/**
 * Static policy tests — thermal migration rules enforced in source (PosKasir + consumers).
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TestRunner } from './testRunner.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const srcRoot = join(__dirname, '../../src');

function read(rel) {
    return readFileSync(join(srcRoot, rel), 'utf8');
}

function walkJsVue(dir, acc = []) {
    for (const name of readdirSync(dir, { withFileTypes: true })) {
        const p = join(dir, name.name);
        if (name.isDirectory() && name.name !== 'node_modules') walkJsVue(p, acc);
        else if (/\.(vue|js)$/.test(name.name)) acc.push(p);
    }
    return acc;
}

const runner = new TestRunner('printPolicy');

console.log('\n🧪 printPolicy Tests\n' + '='.repeat(50) + '\n');

const kasir = read('views/pos/PosKasirPage.vue');

runner.test('PosKasir uses usePrintAdapter (not usePrintService directly)', () => {
    runner.assertContains(kasir, "from '@/composables/print/usePrintAdapter'");
    runner.assertFalse(kasir.includes('usePrintService'), 'PosKasir must not import legacy print service');
});

runner.test('PosKasir auto-print ONLY auto_print_receipt at checkout', () => {
    runner.assertContains(kasir, 'auto_print_receipt');
    runner.assertFalse(/\bauto_print_kas\b/.test(kasir), 'auto_print_kas must not appear in PosKasir');
    runner.assertFalse(/\bauto_print_retur\b/.test(kasir), 'auto_print_retur must not appear in PosKasir');
    runner.assertFalse(/\bauto_print_report\b/.test(kasir), 'auto_print_report must not appear in PosKasir');
});

runner.test('PosKasir passes openDrawer into buildReceipt opts', () => {
    runner.assertContains(kasir, 'buildReceipt(salesData, { ...printOpts.value, openDrawer })');
});

runner.test('PosKasir reconnects printer when receipt dialog opens', () => {
    runner.assertContains(kasir, 'watch(receiptDialog');
    runner.assertContains(kasir, 'printAdapter.reconnect()');
});

runner.test('PosKasir thermal errors surface via notify (not silent catch on tryDirectPrint)', () => {
    runner.assertContains(kasir, "notify.warn('Gagal mencetak struk thermal");
});

const THERMAL_PAGES = [
    'views/pos/PosKasirPage.vue',
    'views/pos/ShiftPage.vue',
    'views/laporan/penjualan/PerNotaPage.vue',
    'views/master/PosTerminalPage.vue',
    'components/print/PrinterPickerPanel.vue'
];

for (const rel of THERMAL_PAGES) {
    runner.test(`${rel} imports usePrintAdapter`, () => {
        runner.assertContains(read(rel), "usePrintAdapter");
    });
}

runner.test('usePrintService only used by adapter + PosTerminal legacy list', () => {
    const allFiles = walkJsVue(srcRoot);
    const offenders = [];
    for (const file of allFiles) {
        const rel = file.slice(srcRoot.length + 1).replace(/\\/g, '/');
        const content = readFileSync(file, 'utf8');
        if (!content.includes('usePrintService')) continue;
        const allowed =
            rel === 'composables/usePrintService.js' ||
            rel === 'composables/print/usePrintAdapter.js' ||
            rel === 'views/master/PosTerminalPage.vue';
        if (!allowed) offenders.push(rel);
    }
    runner.assertDeepEqual(offenders, []);
});

runner.test('ENABLE_LEGACY_PRINT_SERVICE defaults true in printAdapterCore', () => {
    const core = read('composables/print/printAdapterCore.js');
    runner.assertContains(core, 'export const ENABLE_LEGACY_PRINT_SERVICE = true');
});

const ok = runner.summary();
process.exit(ok ? 0 : 1);

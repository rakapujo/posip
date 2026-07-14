/**
 * Isolation guard — barcode/label/export modules must not import browser thermal stack.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TestRunner } from './testRunner.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const srcRoot = join(__dirname, '../../src');

const FORBIDDEN_IMPORTS = [
    '@/composables/print/usePrintAdapter',
    '@/composables/print/usePrintTransport',
    'composables/print/usePrintAdapter',
    'composables/print/usePrintTransport'
];

const PROTECTED_FILES = [
    'composables/useBarcodePrint.js',
    'composables/useSerialLabelPrint.js',
    'composables/useExportPdf.js',
    'views/master/PrintBarcodePage.vue',
    'components/common/SerialLabelPrintDialog.vue',
    'views/public/StrukOnlinePage.vue'
];

const runner = new TestRunner('printIsolation');

console.log('\n🧪 printIsolation Tests\n' + '='.repeat(50) + '\n');

for (const rel of PROTECTED_FILES) {
    runner.test(`${rel} has no thermal adapter imports`, () => {
        const content = readFileSync(join(srcRoot, rel), 'utf8');
        for (const imp of FORBIDDEN_IMPORTS) {
            runner.assertFalse(content.includes(imp), `${rel} must not import ${imp}`);
        }
    });
}

runner.test('barcode localStorage key unchanged in useBarcodePrint', () => {
    const content = readFileSync(join(srcRoot, 'composables/useBarcodePrint.js'), 'utf8');
    runner.assertContains(content, 'barcode-print-settings');
});

runner.test('serial label localStorage key unchanged', () => {
    const content = readFileSync(join(srcRoot, 'composables/useSerialLabelPrint.js'), 'utf8');
    runner.assertContains(content, 'serial-label-print-settings');
});

runner.test('thermal storage key not used in barcode module', () => {
    const content = readFileSync(join(srcRoot, 'composables/useBarcodePrint.js'), 'utf8');
    runner.assertFalse(content.includes('posip-thermal-printer'));
});

const ok = runner.summary();
process.exit(ok ? 0 : 1);

/**
 * Guard: never shrink jsPDF pageSize after drawing.
 * Fit height by redrawing on a new doc (createFittedThermalPdf).
 */
import { jsPDF } from 'jspdf';
import { createFittedThermalPdf } from '../../src/composables/print/fittedThermalPdf.js';
import { TestRunner } from './testRunner.mjs';

const runner = new TestRunner('pdfPageSizeGuard');

console.log('\n🧪 pdfPageSizeGuard Tests\n' + '='.repeat(50) + '\n');

function mediaAndTextY(doc) {
    const raw = Buffer.from(doc.output('arraybuffer')).toString('latin1');
    const mediaH = Number(raw.match(/\/MediaBox\s*\[[^\]]+\s([\d.]+)\]/)[1]);
    const textY = Number(raw.match(/([\d.]+)\s+([\d.]+)\s+Td/)[2]);
    return { mediaH, textY };
}

runner.test('mutating pageSize.height after text leaves glyphs outside MediaBox', () => {
    const doc = new jsPDF({ unit: 'mm', format: [80, 500] });
    doc.setFontSize(12);
    doc.text('VISIBLE', 5, 20);
    doc.internal.pageSize.height = 50;
    const { mediaH, textY } = mediaAndTextY(doc);
    runner.assertTrue(textY > mediaH, `textY ${textY} must be > mediaH ${mediaH} (blank page bug)`);
});

await runner.testAsync('createFittedThermalPdf keeps text inside short MediaBox', async () => {
    const doc = await createFittedThermalPdf((d) => {
        d.setFontSize(12);
        d.text('VISIBLE', 5, 20);
        return 30;
    });
    const heightMm = doc.internal.pageSize.getHeight();
    runner.assertTrue(heightMm >= 80 && heightMm <= 85, `fitted height mm should be ~80, got ${heightMm}`);
    runner.assertEqual(doc.internal.pageSize.getWidth(), 80);
    const { mediaH, textY } = mediaAndTextY(doc);
    runner.assertTrue(textY <= mediaH, `textY ${textY} must be <= mediaH ${mediaH}`);
});

const ok = runner.summary();
process.exit(ok ? 0 : 1);

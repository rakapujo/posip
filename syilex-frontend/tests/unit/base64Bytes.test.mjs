import { TestRunner } from './testRunner.mjs';
import {
    base64ToBytes,
    bytesToBase64,
    hasDrawerBeforeCut,
    lastIndexOfBytes
} from '../../src/composables/print/base64Bytes.js';
import { buildFeedAndCutBytes } from '../../src/composables/print/escposFeedCut.js';

const runner = new TestRunner('base64Bytes + escposFeedCut');

console.log('\n🧪 base64Bytes Tests\n' + '='.repeat(50) + '\n');

runner.test('base64 roundtrip preserves bytes', () => {
    const original = new Uint8Array([0x1b, 0x40, 0x0a, 0xff, 0x00]);
    const b64 = bytesToBase64(original);
    const back = base64ToBytes(b64);
    runner.assertEqual(back.length, original.length);
    for (let i = 0; i < original.length; i++) {
        runner.assertEqual(back[i], original[i]);
    }
});

runner.test('base64ToBytes empty string → empty array', () => {
    runner.assertEqual(base64ToBytes('').length, 0);
    runner.assertEqual(base64ToBytes(null).length, 0);
});

runner.test('lastIndexOfBytes finds subsequence at end', () => {
    const hay = new Uint8Array([1, 2, 3, 4, 5, 3, 4]);
    runner.assertEqual(lastIndexOfBytes(hay, [3, 4]), 5);
    runner.assertEqual(lastIndexOfBytes(hay, [9]), -1);
});

runner.test('hasDrawerBeforeCut true when drawer precedes cut', () => {
    const bytes = buildFeedAndCutBytes(4, true);
    runner.assertTrue(hasDrawerBeforeCut(bytes));
});

runner.test('hasDrawerBeforeCut false without drawer', () => {
    const bytes = buildFeedAndCutBytes(4, false);
    runner.assertFalse(hasDrawerBeforeCut(bytes));
});

runner.test('buildFeedAndCutBytes clamps feed 0-10', () => {
    const zero = buildFeedAndCutBytes(0, false);
    const max = buildFeedAndCutBytes(99, false);
    runner.assertEqual(zero[zero.length - 3], 0x1d); // cut directly after no feed
    runner.assertEqual(max[2], 10); // ESC d n — feed n=10
});

runner.test('buildFeedAndCutBytes negative feed treated as 0', () => {
    const bytes = buildFeedAndCutBytes(-5, false);
    runner.assertEqual(bytes[0], 0x1d); // starts with cut (no feed byte 0x1b 0x64)
});

runner.test('buildFeedAndCutBytes drawer byte order: drawer before feed before cut', () => {
    const bytes = buildFeedAndCutBytes(3, true);
    const drawerAt = lastIndexOfBytes(bytes, [0x1b, 0x70, 0x00, 0x19, 0x19]);
    const cutAt = lastIndexOfBytes(bytes, [0x1d, 0x56, 0x01]);
    runner.assertTrue(drawerAt >= 0 && cutAt > drawerAt);
    runner.assertEqual(bytes[drawerAt + 5], 0x1b); // feed command follows drawer
    runner.assertEqual(bytes[drawerAt + 6], 0x64);
    runner.assertEqual(bytes[drawerAt + 7], 3);
});

runner.test('hasDrawerBeforeCut false when cut precedes drawer', () => {
    const wrong = new Uint8Array([0x1d, 0x56, 0x01, 0x1b, 0x70, 0x00, 0x19, 0x19]);
    runner.assertFalse(hasDrawerBeforeCut(wrong));
});

runner.test('bytesToBase64 empty Uint8Array returns empty string', () => {
    runner.assertEqual(bytesToBase64(new Uint8Array(0)), '');
});

runner.test('lastIndexOfBytes empty needle returns -1', () => {
    runner.assertEqual(lastIndexOfBytes(new Uint8Array([1, 2]), []), -1);
});

runner.test('base64ToBytes whitespace-only → empty array (adapter rejects payload)', () => {
    runner.assertEqual(base64ToBytes('   ').length, 0);
    runner.assertEqual(base64ToBytes('\n\t').length, 0);
});

const ok = runner.summary();
process.exit(ok ? 0 : 1);

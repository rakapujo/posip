import { TestRunner } from './testRunner.mjs';
import { bytesToBase64 } from '../../src/composables/print/base64Bytes.js';
import { checkStatusCore, printRawCore } from '../../src/composables/print/printAdapterCore.js';
import { clearStoredPrinter, setStoredPrinter } from '../../src/composables/print/printStorage.js';
import { clearActiveConnection, setActiveConnection } from '../../src/composables/print/printTransportCore.js';

const store = {};
globalThis.localStorage = {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => {
        store[k] = v;
    },
    removeItem: (k) => {
        delete store[k];
    }
};

const runner = new TestRunner('printAdapterCore');

console.log('\n🧪 printAdapterCore Tests\n' + '='.repeat(50) + '\n');

const sampleB64 = bytesToBase64(new Uint8Array([0x1b, 0x40]));

async function runAsyncTests() {
    await runner.testAsync('printRawCore rejects empty base64', async () => {
        const r = await printRawCore('');
        runner.assertFalse(r.ok);
        runner.assertEqual(r.error, 'Data cetak kosong');
    });

    await runner.testAsync('printRawCore rejects invalid base64', async () => {
        const r = await printRawCore('!!!not-base64!!!');
        runner.assertFalse(r.ok);
        runner.assertContains(r.error, 'base64');
    });

    await runner.testAsync('printRawCore writes via active connection', async () => {
        clearActiveConnection();
        let written = null;
        setActiveConnection({
            kind: 'serial',
            label: 'Test',
            write: async (data) => {
                written = data;
            },
            disconnect: async () => {}
        });
        const r = await printRawCore(sampleB64);
        runner.assertTrue(r.ok);
        runner.assertEqual(written?.length, 2);
        clearActiveConnection();
    });

    await runner.testAsync('printRawCore needPicker when no connection', async () => {
        clearActiveConnection();
        clearStoredPrinter();
        const r = await printRawCore(sampleB64, {
            reconnectFn: async () => null
        });
        runner.assertFalse(r.ok);
        runner.assertTrue(r.needPicker);
    });

    await runner.testAsync('printRawCore whitespace base64 → payload kosong', async () => {
        const r = await printRawCore('   ', { reconnectFn: async () => null });
        runner.assertFalse(r.ok);
        runner.assertEqual(r.error, 'Payload ESC/POS kosong');
    });

    await runner.testAsync('printRawCore stored kind without connection → needPicker + reconnect message', async () => {
        clearActiveConnection();
        setStoredPrinter({ kind: 'serial', terminalUlid: 'TERM1' });
        const r = await printRawCore(sampleB64, { reconnectFn: async () => null });
        runner.assertFalse(r.ok);
        runner.assertTrue(r.needPicker);
        runner.assertContains(r.error, 'disambungkan');
        clearStoredPrinter();
    });

    await runner.testAsync('printRawCore write failure returns error', async () => {
        clearActiveConnection();
        setActiveConnection({
            kind: 'serial',
            label: 'Fail',
            write: async () => {
                throw new Error('USB unplugged');
            },
            disconnect: async () => {}
        });
        const r = await printRawCore(sampleB64);
        runner.assertFalse(r.ok);
        runner.assertContains(r.error, 'USB unplugged');
        clearActiveConnection();
    });

    await runner.testAsync('checkStatusCore false when no pair/connection', async () => {
        clearActiveConnection();
        clearStoredPrinter();
        const ok = await checkStatusCore();
        runner.assertFalse(ok);
    });

    await runner.testAsync('checkStatusCore true when printer paired in storage', async () => {
        clearActiveConnection();
        setStoredPrinter({ kind: 'serial', terminalUlid: 'T1' });
        const ok = await checkStatusCore();
        runner.assertTrue(ok);
        clearStoredPrinter();
    });
}

await runAsyncTests();

const ok = runner.summary();
process.exit(ok ? 0 : 1);
